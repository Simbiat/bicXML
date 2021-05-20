<?php
#Supressing SqlResolve, since we use prefixes and PHPStorm does not support them, thus failing on them
/** @noinspection SqlResolve */
declare(strict_types=1);
namespace Simbiat;

use Simbiat\Database\Controller;

class bicXML
{
    #Base link where we download BIC files
    const bicDownBase = 'https://www.cbr.ru/VFS/mcirabis/BIK/';
    #Link to SWIFT codes
    const swiftZipName = 'https://www.cbr.ru/analytics/digest/bik_swift-bik.zip';
    #List of files to process. Ordered to avoid foreign key issues
    const dbfFiles = ['pzn', 'rclose', 'real', 'reg', 'tnp', 'uer', 'uerko', 'bnkseek', 'bnkdel', 'bik_swif', 'co', 'keybaseb', 'keybasef', 'kgur', 'prim', 'rayon'];
    #List of columns, that represent dates
    const dateColumns = ['CB_DATE', 'CE_DATE', 'DATE_END', 'DATE_CH', 'DATE_IN', 'DATEDEL', 'DT_IZM', 'DT_ST', 'DT_FIN'];
    private string $dbPrefix;

    public function __construct(string $dbPrefix = 'bic__')
    {
        $this->dbPrefix = $dbPrefix;
    }

    #Function to update the BICs data in database
    public function dbUpdate(string $dataDir, string $date = 'DDMMYYYY'): bool|string
    {
        try {
            #Download files first
            #If date was not provided, use current system one
            if ($date === 'DDMMYYYY') {
                $date = date('dmY');
            }
            #Set filename
            $bicFileName = 'bik_db_'.$date.'.zip';
            #Attempt to download main bic archive
            if (file_put_contents($dataDir.$bicFileName, @fopen(self::bicDownBase.$bicFileName, 'r'))) {
                #Unzip the file
                if (file_exists($dataDir.$bicFileName)) {
                    $zip = new \ZipArchive;
                    if ($zip->open($dataDir.$bicFileName) === true) {
                        $zip->extractTo($dataDir);
                        $zip->close();
                    }
                }
                #Delete the file
                @unlink($dataDir.$bicFileName);
                @unlink($dataDir.'FC.DBF');
                @unlink($dataDir.'KORREK.DBF');
                #Meant to remove files like 3503_21N.DBF
                array_map('unlink', glob($dataDir.'[0-9]*.DBF'));
            } else {
                @unlink($dataDir.$bicFileName);
                return false;
            }
            #Attempt to download SWIFT library
            if (file_put_contents($dataDir.basename(self::swiftZipName), @fopen(self::swiftZipName, 'r'))) {
                #Unzip the file
                if (file_exists($dataDir.basename(self::swiftZipName))) {
                    $zip = new \ZipArchive;
                    if ($zip->open($dataDir.basename(self::swiftZipName)) === true) {
                        $zip->extractTo($dataDir);
                        $zip->close();
                    }
                }
            }
            #Delete the file
            @unlink($dataDir.basename(self::swiftZipName));
            foreach (self::dbfFiles as $file) {
                #Prepare empty array for queries
                $queries = [];
                $filename = $dataDir.$file.'.dbf';
                if (file_exists($filename)) {
                    #Convert DBF file to array
                    $array = (new ArrayHelpers)->dbfToArray($filename);
                    if (is_array($array) && !empty($array)) {
                        #Normalize data
                        #Iterate rows
                        foreach ($array as $element) {
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
                                if (in_array($column, self::dateColumns)) {
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
                                'INSERT INTO `'.$this->dbPrefix.(($file == 'bnkseek' || $file == 'bnkdel') ? 'list' : $file).'` SET '.$update.' ON DUPLICATE KEY UPDATE '.$update,
                                $bindings
                            ];
                        }
                    }
                }
                #Deleting the file
                @unlink($filename);
                #Running the queries we've accumulated
                (new Controller)->query($queries);
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
        $bicDetails = (new Controller)->selectRow('SELECT `biclist`.`VKEY`, `VKEYDEL`, `'.$this->dbPrefix.'keybaseb`.`BVKEY`, `'.$this->dbPrefix.'keybasef`.`FVKEY`, `ADR`, `AT1`, `AT2`, `CKS`, `DATE_CH`, `DATE_IN`, `DATEDEL`, `DT_IZM`, `IND`, `KSNP`, `NAMEP`, `'.$this->dbPrefix.'keybaseb`.`NAMEMAXB`, `'.$this->dbPrefix.'keybasef`.`NAMEMAXF`, `NEWKS`, biclist.`NEWNUM`, `'.$this->dbPrefix.'co`.`BIC_UF`, `'.$this->dbPrefix.'co`.`DT_ST`, `'.$this->dbPrefix.'co`.`DT_FIN`, `'.$this->dbPrefix.'bik_swif`.`KOD_SWIFT`, `'.$this->dbPrefix.'bik_swif`.`NAME_SRUS`, `NNP`, `OKPO`, `PERMFO`, `'.$this->dbPrefix.'pzn`.`NAME` AS `PZN`, `'.$this->dbPrefix.'real`.`NAME_OGR` AS `REAL`, `'.$this->dbPrefix.'rclose`.`NAMECLOSE` AS `R_CLOSE`, `REGN`, `'.$this->dbPrefix.'reg`.`NAME` AS `RGN`, `'.$this->dbPrefix.'reg`.`CENTER`, `RKC`, `SROK`, `TELEF`, `'.$this->dbPrefix.'tnp`.`FULLNAME` AS `TNP`, `'.$this->dbPrefix.'uerko`.`UERNAME` AS `UER`, `'.$this->dbPrefix.'prim`.`PRIM1`, `'.$this->dbPrefix.'prim`.`PRIM2`, `'.$this->dbPrefix.'prim`.`PRIM3`, `'.$this->dbPrefix.'rayon`.`NAME` AS `RAYON`, `'.$this->dbPrefix.'kgur`.`KGUR` FROM `'.$this->dbPrefix.'list` biclist
                LEFT JOIN `'.$this->dbPrefix.'bik_swif` ON `'.$this->dbPrefix.'bik_swif`.`KOD_RUS` = biclist.`NEWNUM`
                LEFT JOIN `'.$this->dbPrefix.'reg` ON `'.$this->dbPrefix.'reg`.`RGN` = biclist.`RGN`
                LEFT JOIN `'.$this->dbPrefix.'uerko` ON `'.$this->dbPrefix.'uerko`.`UERKO` = biclist.`UER`
                LEFT JOIN `'.$this->dbPrefix.'tnp` ON `'.$this->dbPrefix.'tnp`.`TNP` = biclist.`TNP`
                LEFT JOIN `'.$this->dbPrefix.'pzn` ON `'.$this->dbPrefix.'pzn`.`PZN` = biclist.`PZN`
                LEFT JOIN `'.$this->dbPrefix.'real` ON `'.$this->dbPrefix.'real`.`REAL` = biclist.`REAL`
                LEFT JOIN `'.$this->dbPrefix.'rclose` ON `'.$this->dbPrefix.'rclose`.`R_CLOSE` = biclist.`R_CLOSE`
                LEFT JOIN `'.$this->dbPrefix.'keybaseb` ON `'.$this->dbPrefix.'keybaseb`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbPrefix.'keybasef` ON `'.$this->dbPrefix.'keybasef`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbPrefix.'prim` ON `'.$this->dbPrefix.'prim`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbPrefix.'rayon` ON `'.$this->dbPrefix.'rayon`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `'.$this->dbPrefix.'co` ON `'.$this->dbPrefix.'co`.`BIC_CF` = biclist.`NEWNUM`
                LEFT JOIN `'.$this->dbPrefix.'kgur` ON `'.$this->dbPrefix.'kgur`.`NEWNUM` = biclist.`RKC`
                WHERE biclist.`VKEY` = :vkey', [':vkey'=>$vkey]);
        if (empty($bicDetails)) {
            return [];
        } else {
            #Generating address from different fields
            $bicDetails['ADR'] = (!empty($bicDetails['IND']) ? $bicDetails['IND'].' ' : '').(!empty($bicDetails['TNP']) ? $bicDetails['TNP'].' ' : '').(!empty($bicDetails['NNP']) ? $bicDetails['NNP'].(!empty($bicDetails['RAYON']) ?  ' '.$bicDetails['RAYON'] : '').', ' : '').$bicDetails['ADR'];
            #Get list of phones
            if (!empty($bicDetails['TELEF'])) {
                $bicDetails['TELEF'] = $this->phoneList($bicDetails['TELEF']);
            } else {
                $bicDetails['TELEF'] = [];
            }
            #If RKC=NEWNUM it means, that current bank is RKC and does not have bank above it
            if ($bicDetails['RKC'] == $bicDetails['NEWNUM']) {
                $bicDetails['RKC'] = '';
            }
            #If we have an RKC - get the whole chain of RKCs
            if (!empty($bicDetails['RKC'])) {$bicDetails['RKC'] = $this->rkcChain($bicDetails['RKC']);}
            #Get authorized branch
            if (!empty($bicDetails['BIC_UF'])) {$bicDetails['BIC_UF'] = $this->bicUf($bicDetails['BIC_UF']);}
            #Get all branches of the bank (if any)
            $bicDetails['filials'] = $this->filials($bicDetails['NEWNUM']);
            #Get the chain of predecessors (if any)
            $bicDetails['predecessors'] = $this->predecessors($bicDetails['VKEY']);
            #Get the chain of successors (if any)
            $bicDetails['successors'] = $this->successors($bicDetails['VKEYDEL']);
            return $bicDetails;
        }
    }

    #Function to search for BICs
    public function Search(string $what = ''): array
    {
        return (new Controller)->selectAll('SELECT `VKEY`, `NEWNUM`, `NAMEP`, `DATEDEL` FROM `'.$this->dbPrefix.'list` WHERE `VKEY` LIKE :name OR `NEWNUM` LIKE :name OR `NAMEP` LIKE :name OR `KSNP` LIKE :name OR `REGN` LIKE :name ORDER BY `NAMEP`', [':name'=>'%'.$what.'%']);
    }

    #Function to get basic statistics
    public function Statistics(int $lastChanges = 10): array
    {
        #Cache Controller
        $dbCon = (new Controller);
        $temp = $dbCon->selectAll('SELECT COUNT(*) as \'bics\' FROM `'.$this->dbPrefix.'list` WHERE `DATEDEL` IS NULL UNION ALL SELECT COUNT(*) as \'bics\' FROM `'.$this->dbPrefix.'list` WHERE `DATEDEL` IS NOT NULL');
        $statistics['bicactive'] = $temp[0]['bics'];
        $statistics['bicdeleted'] = $temp[1]['bics'];
        $statistics['bicchanges'] = $dbCon->selectAll('SELECT * FROM ((SELECT \'changed\' as `type`, `VKEY`, `NAMEP`, `DATEDEL`, `DT_IZM` FROM `'.$this->dbPrefix.'list` a WHERE `DATEDEL` IS NULL ORDER BY `DT_IZM` DESC LIMIT '.$lastChanges.') UNION ALL (SELECT \'deleted\' as `type`, `VKEY`, `NAMEP`, `DATEDEL`, `DT_IZM` FROM `'.$this->dbPrefix.'list` b WHERE `DATEDEL` IS NOT NULL ORDER BY `DATEDEL` DESC LIMIT '.$lastChanges.')) c');
        return $statistics;
    }

    #Function to prepare tables
    public function install(): bool
    {
        #Get contents from SQL file
        $sql = file_get_contents(__DIR__.'\install.sql');
        #Replace prefix
        $sql = str_replace('%dbPrefix%', $this->dbPrefix, $sql);
        #Split file content into queries
        $sql = (new Controller)->stringToQueries($sql);
        try {
            (new Controller)->query($sql);
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
        $bank = (new Controller)->selectAll('SELECT `VKEY`, `VKEYDEL`, `NAMEP`, `DATEDEL` FROM `'.$this->dbPrefix.'list` WHERE `VKEYDEL` = :newnum ORDER BY `NAMEP`', [':newnum'=>$vkey]);
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
                            foreach ($next[0] as $nextI) {
                                $bank[$key][] = $nextI;
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
        $bank = (new Controller)->selectAll('SELECT `VKEY`, `VKEYDEL`, `NAMEP`, `DATEDEL` FROM `'.$this->dbPrefix.'list` WHERE `VKEY` = :newnum ORDER BY `NAMEP`', [':newnum'=>$vkey]);
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
        $bank = (new Controller)->selectAll('SELECT `VKEY`, `NEWNUM`, `RKC`, `NAMEP`, `DATEDEL` FROM `'.$this->dbPrefix.'list` WHERE `NEWNUM` = :newnum AND `DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
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
        $bank = (new Controller)->selectAll('SELECT `VKEY`, `NAMEP`, `DATEDEL`, `'.$this->dbPrefix.'co`.`BIC_UF` FROM `'.$this->dbPrefix.'list` biclist LEFT JOIN `'.$this->dbPrefix.'co` ON `'.$this->dbPrefix.'co`.`BIC_CF` = biclist.`NEWNUM` WHERE biclist.`NEWNUM` = :newnum AND biclist.`DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
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
        $bank = (new Controller)->selectAll('SELECT `'.$this->dbPrefix.'list`.`VKEY`, `'.$this->dbPrefix.'list`.`NEWNUM`, `'.$this->dbPrefix.'list`.`NAMEP`, `'.$this->dbPrefix.'list`.`DATEDEL` FROM `'.$this->dbPrefix.'co` bicco LEFT JOIN `'.$this->dbPrefix.'list` ON `'.$this->dbPrefix.'list`.`NEWNUM` = bicco.`BIC_CF` WHERE `BIC_UF` = :newnum ORDER BY `'.$this->dbPrefix.'list`.`NAMEP`', [':newnum'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        }
        return $bank;
    }

    #Function to format list of phones
    private function phoneList(string $phoneString): array
    {
        #Remove empty brackets
        $phoneString = str_replace('()', '', $phoneString);
        #Remvoe pager notation (obsolete)
        $phoneString = str_replace('ПЕЙД', '', $phoneString);
        #Update Moscow code
        $phoneString = str_replace('(095)', '(495)', $phoneString);
        #Attempt to get additional number (to be entered after you've dialed-in)
        $dob = explode(',ДОБ.', $phoneString);
        if (empty($dob[1])) {
            $dob = explode(',ДБ.', $phoneString);
            if (empty($dob[1])) {
                $dob = explode('(ДОБ.', $phoneString);
                if (empty($dob[1])) {
                    $dob = explode(' ДОБ.', $phoneString);
                    if (empty($dob[1])) {
                        $dob = explode('ДОБ', $phoneString);
                        if (empty($dob[1])) {
                            $dob = explode(' код ', $phoneString);
                            if (empty($dob[1])) {
                                $dob = explode(',АБ.', $phoneString);
                                if (empty($dob[1])) {
                                    $dob = explode(',Д.', $phoneString);
                                    if (empty($dob[1])) {
                                        $dob = explode('(Д.', $phoneString);
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
