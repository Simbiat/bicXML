<?php
declare(strict_types=1);
namespace Simbiat;

class bicXML
{    
    #Base link where we download BIC files
    const bicdownbase = 'https://www.cbr.ru/VFS/mcirabis/BIK/';
    #Link to SWIFT codes
    const swiftzipname = 'https://www.cbr.ru/analytics/digest/bik_swift-bik.zip';
    #List of files to process. Ordered to avoid foreign key issues
    const dbffiles = ['pzn', 'rclose', 'real', 'reg', 'tnp', 'uer', 'uerko', 'bnkseek', 'bnkdel', 'bik_swif', 'co', 'keybaseb', 'keybasef', 'kgur', 'prim', 'rayon'];
    #List of columns, that represent dates
    const datecolumns = ['CB_DATE', 'CE_DATE', 'DATE_END', 'DATE_CH', 'DATE_IN', 'DATEDEL', 'DT_IZM', 'DT_ST', 'DT_FIN'];
    private string $dbprefix = '';
    
    public function __construct(string $dbprefix = 'bic__')
    {
        $this->dbprefix = $dbprefix;
    }
    
    #Function to update the BICs data in database
    public function dbUpdate(string $datadir, string $date = 'DDMMYYYY'): bool|string
    {
        try {
            #Download files first
            #If date was not provided, use current system one
            if ($date === 'DDMMYYYY') {
                $date = date('dmY');
            }
            $bicdate = strtotime(substr($date, 0, 2).'.'.substr($date, 2, 2).'.'.substr($date, 4, 4));
            #Set filename
            $bicfname = 'bik_db_'.$date.'.zip';
            #Attempt to download main bic archive
            if (file_put_contents($datadir.$bicfname, @fopen(self::bicdownbase.$bicfname, 'r'))) {
                #Unzip the file
                if (file_exists($datadir.$bicfname)) {
                    $zip = new \ZipArchive;
                    if ($zip->open($datadir.$bicfname) === true) {
                        $zip->extractTo($datadir);
                        $zip->close();
                    }
                }
                #Delete the file
                @unlink($datadir.$bicfname);
                @unlink($datadir.'FC.DBF');
                @unlink($datadir.'KORREK.DBF');
                #Meant to remove files like 3503_21N.DBF
                array_map('unlink', glob($datadir.'[0-9]*.DBF'));
            } else {
                @unlink($datadir.$bicfname);
                return false;
            }
            #Attempt to download SWIFT library
            if (file_put_contents($datadir.basename(self::swiftzipname), @fopen(self::swiftzipname, 'r'))) {
                #Unzip the file
                if (file_exists($datadir.basename(self::swiftzipname))) {
                    $zip = new \ZipArchive;
                    if ($zip->open($datadir.basename(self::swiftzipname)) === true) {
                        $zip->extractTo($datadir);
                        $zip->close();
                    }
                }
                #Delete the file
                @unlink($datadir.basename(self::swiftzipname));
            } else {
                @unlink($datadir.basename(self::swiftzipname));
            }
            foreach (self::dbffiles as $file) {
                #Prepare empty array for queries
                $queries = [];
                $filename = $datadir.$file.'.dbf';
                if (file_exists($filename)) {
                    #Convert DBF file to array
                    $array = (new \Simbiat\ArrayHelpers)->dbfToArray($filename);
                    if (is_array($array) && !empty($array)) {
                        #Normalize data
                        #Iterate rows
                        foreach ($array as $key=>$element) {
                            #Prim file can some keys, that are missing in main list for some reason, need to skip them, since foreign key constraint will fail otherwise
                            if ($file === 'prim' && (in_array($element['VKEY'], ['392!EOmE', 'Fyg(wdtf', 'Yw7y9=6+']))) {
                                continue;
                            }
                            #Prepare variables for update
                            $bindings = [];
                            $update = '';
                            #Iterate columns
                            foreach ($element as $column=>$value) {
                                #Check if column is one of those that hold dates
                                if (in_array($column, self::datecolumns)) {
                                    if (empty(trim($value))) {
                                        #Save it as NULL
                                        $value = NULL;
                                    } else {
                                        #Parse string and convert it to UNIX timestamp
                                        $value = strtotime(substr($value, 6, 2).'.'.substr($value, 4, 2).'.'.substr($value, 0, 4));
                                        #If 0 is return - save as NULL
                                        if ($value < 0) {
                                            $value = NULL;
                                        }
                                    }
                                    #Add to variables for update
                                    $bindings[':'.$column] = array((empty($value) ? NULL : $value), (empty($value) ? 'null' : 'date'));
                                    $update .= '`'.$column.'` = :'.$column.', ';
                                } else {
                                    #Some columns are not actually used, so we can safely ignore them
                                    if ($column !== 'deleted' && $column !== 'DT_IZMR') {
                                        #Convert value to UTF
                                        $value = trim(iconv('CP866', 'UTF-8', $value));
                                        #To be honest, do not remember why this has to be emptied and too lazy to re-read the documentation for DBF files
                                        if ($column == 'PZN' && $value == '60') {
                                            $value = '';
                                        }
                                        if (($column == 'TNP' || $column == 'R_CLOSE') && empty($value)) {
                                            $bindings[':'.$column] = array(NULL, 'null');
                                        } else {
                                            $bindings[':'.$column] = $value;
                                        }
                                        $update .= '`'.$column.'` = :'.$column.', ';
                                    }
                                }
                            }
                            #Trim the last comma
                            $update = rtrim($update, ', ');
                            #bnkseek and bnkdel are stored in common table, hence the 'rename' below
                            $queries[] = [
                                'INSERT INTO `'.$this->dbprefix.(($file == 'bnkseek' || $file == 'bnkdel') ? 'list' : $file).'` SET '.$update.' ON DUPLICATE KEY UPDATE '.$update,
                                $bindings
                            ];
                        }
                    }
                }
                #Deleting the file
                @unlink($filename);
                #Running the queries we've accumulated
                (new \Simbiat\Database\Controller)->query($queries);
            }
            return true;
        } catch(\Exception $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }
    
    #Function to return current data about the bank
    public function getCurrent(string $vkey): array
    {
        #Get general data
        $bicdetails = (new \Simbiat\Database\Controller)->selectRow('SELECT biclist.`VKEY`, `VKEYDEL`, `'.$this->dbprefix.'keybaseb`.`BVKEY`, `'.$this->dbprefix.'keybasef`.`FVKEY`, `ADR`, `AT1`, `AT2`, `CKS`, `DATE_CH`, `DATE_IN`, `DATEDEL`, `DT_IZM`, `IND`, `KSNP`, `NAMEP`, `'.$this->dbprefix.'keybaseb`.`NAMEMAXB`, `'.$this->dbprefix.'keybasef`.`NAMEMAXF`, `NEWKS`, biclist.`NEWNUM`, `'.$this->dbprefix.'co`.`BIC_UF`, `'.$this->dbprefix.'co`.`DT_ST`, `'.$this->dbprefix.'co`.`DT_FIN`, `'.$this->dbprefix.'bik_swif`.`KOD_SWIFT`, `'.$this->dbprefix.'bik_swif`.`NAME_SRUS`, `NNP`, `OKPO`, `PERMFO`, `'.$this->dbprefix.'pzn`.`NAME` AS `PZN`, `'.$this->dbprefix.'real`.`NAME_OGR` AS `REAL`, `'.$this->dbprefix.'rclose`.`NAMECLOSE` AS `R_CLOSE`, `REGN`, `'.$this->dbprefix.'reg`.`NAME` AS `RGN`, `'.$this->dbprefix.'reg`.`CENTER`, `RKC`, `SROK`, `TELEF`, `'.$this->dbprefix.'tnp`.`FULLNAME` AS `TNP`, `'.$this->dbprefix.'uerko`.`UERNAME` AS `UER`, `'.$this->dbprefix.'prim`.`PRIM1`, `'.$this->dbprefix.'prim`.`PRIM2`, `'.$this->dbprefix.'prim`.`PRIM3`, `'.$this->dbprefix.'rayon`.`NAME` AS `RAYON`, `'.$this->dbprefix.'kgur`.`KGUR` FROM `'.$this->dbprefix.'list` biclist
                LEFT JOIN `'.$this->dbprefix.'bik_swif` ON `'.$this->dbprefix.'bik_swif`.`KOD_RUS` = biclist.`NEWNUM`
                LEFT JOIN `'.$this->dbprefix.'reg` ON `'.$this->dbprefix.'reg`.`RGN` = biclist.`RGN`
                LEFT JOIN `'.$this->dbprefix.'uerko` ON `'.$this->dbprefix.'uerko`.`UERKO` = biclist.`UER`
                LEFT JOIN `'.$this->dbprefix.'tnp` ON `'.$this->dbprefix.'tnp`.`TNP` = biclist.`TNP`
                LEFT JOIN `'.$this->dbprefix.'pzn` ON `'.$this->dbprefix.'pzn`.`PZN` = biclist.`PZN`
                LEFT JOIN `'.$this->dbprefix.'real` ON `'.$this->dbprefix.'real`.`REAL` = biclist.`REAL`
                LEFT JOIN `'.$this->dbprefix.'rclose` ON `'.$this->dbprefix.'rclose`.`R_CLOSE` = biclist.`R_CLOSE`
                LEFT JOIN `'.$this->dbprefix.'keybaseb` ON `'.$this->dbprefix.'keybaseb`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbprefix.'keybasef` ON `'.$this->dbprefix.'keybasef`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbprefix.'prim` ON `'.$this->dbprefix.'prim`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbprefix.'rayon` ON `'.$this->dbprefix.'rayon`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbprefix.'co` ON `'.$this->dbprefix.'co`.`BIC_CF` = biclist.`NEWNUM`
                LEFT JOIN `'.$this->dbprefix.'kgur` ON `'.$this->dbprefix.'kgur`.`NEWNUM` = biclist.`RKC`
                WHERE biclist.`VKEY` = :vkey', [':vkey'=>$vkey]);
        if (empty($bicdetails)) {
            return [];
        } else {
            #Generating address from different fields
            $bicdetails['ADR'] = (!empty($bicdetails['IND']) ? $bicdetails['IND'].' ' : '').(!empty($bicdetails['TNP']) ? $bicdetails['TNP'].' ' : '').(!empty($bicdetails['NNP']) ? $bicdetails['NNP'].(!empty($bicdetails['RAYON']) ?  ' '.$bicdetails['RAYON'] : '').', ' : '').$bicdetails['ADR'];
            #Get list of phones
            if (!empty($bicdetails['TELEF'])) {
                $bicdetails['TELEF'] = $this->phoneList($bicdetails['TELEF']);
            } else {
                $bicdetails['TELEF'] = [];
            }
            #If RKC=NEWNUM it means, that current bank is RKC and does not have bank above it
            if ($bicdetails['RKC'] == $bicdetails['NEWNUM']) {
                $bicdetails['RKC'] = '';
            }
            #If we have an RKC - get the whole chain of RKCs
            if (!empty($bicdetails['RKC'])) {$bicdetails['RKC'] = $this->rkcChain($bicdetails['RKC']);}
            #Get authorized branch
            if (!empty($bicdetails['BIC_UF'])) {$bicdetails['BIC_UF'] = $this->bicUf($bicdetails['BIC_UF']);}
            #Get all branches of the bank (if any)
            $bicdetails['filials'] = $this->filials($bicdetails['NEWNUM']);
            #Get the chain of predecessors (if any)
            $bicdetails['predecessors'] = $this->predecessors($bicdetails['VKEY']);
            #Get the chain of successors (if any)
            $bicdetails['successors'] = $this->successors($bicdetails['VKEYDEL']);
            return $bicdetails;
        }
    }
    
    #Function to search for BICs
    public function Search(string $what = ''): array
    {
        return (new \Simbiat\Database\Controller)->selectAll('SELECT `VKEY`, `NEWNUM`, `NAMEP`, `DATEDEL` FROM `'.$this->dbprefix.'list` WHERE `VKEY` LIKE :name OR `NEWNUM` LIKE :name OR `NAMEP` LIKE :name OR `KSNP` LIKE :name OR `REGN` LIKE :name ORDER BY `NAMEP` ASC', [':name'=>'%'.$what.'%']);
    }
    
    #Function to get basic statistics
    public function Statistics(int $lastchanges = 10): array
    {
        #Cache Controller
        $dbcon = (new \Simbiat\Database\Controller);
        $temp = $dbcon->selectAll('SELECT COUNT(*) as \'bics\' FROM `'.$this->dbprefix.'list` WHERE `DATEDEL` IS NULL UNION ALL SELECT COUNT(*) as \'bics\' FROM `'.$this->dbprefix.'list` WHERE `DATEDEL` IS NOT NULL');
        $statistics['bicactive'] = $temp[0]['bics'];
        $statistics['bicdeleted'] = $temp[1]['bics'];
        $statistics['bicchanges'] = $dbcon->selectAll('SELECT * FROM ((SELECT \'changed\' as `type`, `VKEY`, `NAMEP`, `DATEDEL`, `DT_IZM` FROM `'.$this->dbprefix.'list` a WHERE `DATEDEL` IS NULL ORDER BY `DT_IZM` DESC LIMIT '.$lastchanges.') UNION ALL (SELECT \'deleted\' as `type`, `VKEY`, `NAMEP`, `DATEDEL`, `DT_IZM` FROM `'.$this->dbprefix.'list` b WHERE `DATEDEL` IS NOT NULL ORDER BY `DATEDEL` DESC LIMIT '.$lastchanges.')) c');
        return $statistics;
    }
    
    #Function to prepare tables
    public function install(): bool
    {
        #Get contents from SQL file
        $sql = file_get_contents(__DIR__.'\install.sql');
        #Replace prefix
        $sql = str_replace('%dbprefix%', $this->dbprefix, $sql);
        #Split file content into queries
        $sql = (new \Simbiat\Database\Controller)->stringToQueries($sql);
        try {
            (new \Simbiat\Database\Controller)->query($sql);
            return true;
        } catch(\Exception $e) {
            echo $e->getTraceAsString();
            return false;
        }
    }
    
    #Function to get list of all predecessors (each as a chain)
    private function predecessors(string $vkey): array
    {
        #Get initial list
        $bank = (new \Simbiat\Database\Controller)->selectAll('SELECT `VKEY`, `VKEYDEL`, `NAMEP`, `DATEDEL` FROM `'.$this->dbprefix.'list` WHERE `VKEYDEL` = :newnum ORDER BY `NAMEP` ASC', [':newnum'=>$vkey]);
        if (empty($bank)) {
            $bank = array();
        } else {
            foreach ($bank as $key=>$item) {
                #Check for predecessors of predecessor
                $next = $this->predecessors($item['VKEY']);
                if (!empty($next)) {
                    #If predecessor has a predecessor as well - get its predecessors
                    if (count($next) == 1) {
                        if (!empty($next[0][0]) && is_array($next[0][0])) {
                            $bank[$key] = [];
                            foreach ($next[0] as $nexti) {
                                $bank[$key][] = $nexti;
                            }
                            $bank[$key][] = $item;
                        } else {
                            $bank[$key] = [$next[0], $item];
                        }
                    }
                }
            }
        }
        return $bank;
    }
    
    #Function to get all successors (each as a chain)
    private function successors(string $vkey): array
    {
        #Get initial list
        $bank = (new \Simbiat\Database\Controller)->selectAll('SELECT `VKEY`, `VKEYDEL`, `NAMEP`, `DATEDEL` FROM `'.$this->dbprefix.'list` WHERE `VKEY` = :newnum ORDER BY `NAMEP` ASC', [':newnum'=>$vkey]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get successors for each successor
            foreach ($bank as $key=>$item) {
                if (!empty($item[0]['VKEYDEL']) && $item[0]['VKEYDEL'] != $vkey && $bank[0]['VKEYDEL'] != $bank[0]['VKEY']) {
                    $bank[$key] = array_merge($item, $this->successors($item[0]['VKEY']));
                }
            }
        }
        return $bank;
    }
    
    #Function to get all RKCs for a bank as a chain
    private function rkcChain(string $bic): array
    {
        #Get initial list
        $bank = (new \Simbiat\Database\Controller)->selectAll('SELECT `VKEY`, `NEWNUM`, `RKC`, `NAMEP`, `DATEDEL` FROM `'.$this->dbprefix.'list` WHERE `NEWNUM` = :newnum AND `DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get RKC for RKC
            if (!empty($bank[0]['RKC']) && $bank[0]['RKC'] != $bic && $bank[0]['RKC'] != $bank[0]['NEWNUM']) {
                $bank = array_merge($bank, $this->rkcChain($bank[0]['RKC']));
            }
        }
        return $bank;
    }
    
    #Function to get authorized branches as a chain
    private function bicUf(string $bic): array
    {
        #Get initial list
        $bank = (new \Simbiat\Database\Controller)->selectAll('SELECT `VKEY`, `NAMEP`, `DATEDEL`, `'.$this->dbprefix.'co`.`BIC_UF` FROM `'.$this->dbprefix.'list` biclist LEFT JOIN `'.$this->dbprefix.'co` ON `'.$this->dbprefix.'co`.`BIC_CF` = biclist.`NEWNUM` WHERE biclist.`NEWNUM` = :newnum AND biclist.`DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get authorized branch of authorized branch
            if (!empty($bank[0]['BIC_UF']) && $bank[0]['BIC_UF'] != $bic && isset($bank[0]['NEWNUM']) && $bank[0]['BIC_UF'] != $bank[0]['NEWNUM']) {
                $bank = array_merge($bank, $this->bicUf($bank[0]['BIC_UF']));
            }
        }
        return $bank;
    }
    
    #Function to get all branches of a bank
    private function filials(string $bic): array
    {
        $bank = (new \Simbiat\Database\Controller)->selectAll('SELECT `'.$this->dbprefix.'list`.`VKEY`, `'.$this->dbprefix.'list`.`NEWNUM`, `'.$this->dbprefix.'list`.`NAMEP`, `'.$this->dbprefix.'list`.`DATEDEL` FROM `'.$this->dbprefix.'co` bicco LEFT JOIN `'.$this->dbprefix.'list` ON `'.$this->dbprefix.'list`.`NEWNUM` = bicco.`BIC_CF` WHERE `BIC_UF` = :newnum ORDER BY `'.$this->dbprefix.'list`.`NAMEP`', [':newnum'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        }
        return $bank;
    }
    
    #Function to format list of phones
    private function phoneList(string $phonestring): array
    {
        #Remove empty brackets
        $phonestring = str_replace('()', '', $phonestring);
        #Remvoe pager notation (obsolete)
        $phonestring = str_replace('ПЕЙД', '', $phonestring);
        #Update Moscow code
        $phonestring = str_replace('(095)', '(495)', $phonestring);
        #Attempt to get additional number (to be entered after you've dialed-in)
        $dob = explode(',ДОБ.', $phonestring);
        if (empty($dob[1])) {
            $dob = explode(',ДБ.', $phonestring);
            if (empty($dob[1])) {
                $dob = explode('(ДОБ.', $phonestring);
                if (empty($dob[1])) {
                    $dob = explode(' ДОБ.', $phonestring);
                    if (empty($dob[1])) {
                        $dob = explode('ДОБ', $phonestring);
                        if (empty($dob[1])) {
                            $dob = explode(' код ', $phonestring);
                            if (empty($dob[1])) {
                                $dob = explode(',АБ.', $phonestring);
                                if (empty($dob[1])) {
                                    $dob = explode(',Д.', $phonestring);
                                    if (empty($dob[1])) {
                                        $dob = explode('(Д.', $phonestring);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        #Check if there are additional numbers
        if (empty($dob[1])) {
            $dobs = '';
        } else {
            #Remove all letters from additional number
            $dobs = preg_replace('/[^0-9,]/', '', $dob[1]);
            #Replace ','. To be honest not sure why it's done through explode/implode, but I think this helped with removing empty values
            $dobs = explode(',', $dobs);
            $dobs = implode(' или ', $dobs);
        }
        #Get actual phones
        $phones = explode(',', $dob[0]);
        #Attempting to sanitize the phone numbers to utilize +7 code only
        preg_match('/\((\d*)\)/', $phones[0], $code);
        if (empty($code[1])) {
            $code = '+7 ';
        } else {
            $code = '+7 ('.$code[1].') ';
        }
        foreach ($phones as $key=>$phone) {
            if (!preg_match('/\((\d*)\)/', $phone)) {
                $phone = $code.$phone;
            } else {
                $phone = '+7 '.$phone;
                if (!preg_match('/\) /', $phone)) {
                    $phone = preg_replace('/\)/', ') ', $phone);
                }
            }
            $phones[$key] = ['phone'=>$phone,'url'=>preg_replace('/[^0-9+]/', '', $phone)];
        }
        return ['phones'=>$phones,'dob'=>$dobs];
    }
}
?>