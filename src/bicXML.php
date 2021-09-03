<?php
declare(strict_types=1);
namespace Simbiat;

use Simbiat\Database\Controller;

class bicXML
{
    private string $prefix;
    #Base link where we download BIC files
    const bicDownBase = 'https://www.cbr.ru/PSystem/payment_system/?UniDbQuery.Posted=True&UniDbQuery.To=';
    #Base link for href attribute
    const bicBaseHref = 'https://www.cbr.ru';

    #cURL options
    protected array $CURL_OPTIONS = [
        CURLOPT_POST => false,
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        #Allow caching and reuse of already open connections
        CURLOPT_FRESH_CONNECT => false,
        CURLOPT_FORBID_REUSE => false,
        #Let cURL determine appropriate HTTP version
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Content-type: text/html; charset=utf-8', 'Accept-Language: en'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36 Edg/92.0.902.84',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    #cURL Handle is static to allow reuse of single instance, if possible
    public static \CurlHandle|null|false $curlHandle = null;


    #Link to SWIFT codes
    const swiftZipName = 'https://www.cbr.ru/analytics/digest/bik_swift-bik.zip';
    #List of files to process. Ordered to avoid foreign key issues
    const dbfFiles = ['pzn', 'rclose', 'real', 'reg', 'tnp', 'uer', 'uerko', 'bnkseek', 'bnkdel', 'bik_swif', 'co', 'keybaseb', 'keybasef', 'kgur', 'prim', 'rayon'];
    #List of columns, that represent dates
    const dateColumns = ['CB_DATE', 'CE_DATE', 'DATE_END', 'DATE_CH', 'DATE_IN', 'DATEDEL', 'DT_IZM', 'DT_ST', 'DT_FIN'];

    public function __construct(string $prefix = 'bic__')
    {
        #Set prefix for SQL
        $this->prefix = $prefix;
    }


    #Function to update library in database
    /**
     * @throws \Exception
     */
    public function dbUpdate(): bool
    {
        #Cache controller
        $dbController = (new Controller);
        $currentDate = strtotime(date('d.m.Y', time()));
        #Get date of current library
        $libDate = $dbController->selectValue('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'date\'');
        $libDate = strtotime(date('d.m.Y', intval($libDate)));
        while ($libDate <= $currentDate) {
            $download = $this->download($libDate);
            if ($download === true) {
                #The day does not have library, skip it
                continue;
            } elseif ($download === false) {
                #Failed to download. Stop processing to avoid loosing sequence
                return false;
            } else {
                #Load file
                libxml_use_internal_errors(true);
                $library = new \DOMDocument();
                $library->load(realpath($download));
                #Get date from root node
                $elements = new \DOMXpath($library);
                #Check date of the library
                if ($elements->evaluate('string(/*/@EDDate)') !== date('Y-m-d', $libDate)) {
                    #Date mismatch. Stop processing to avoid loosing sequence
                    return false;
                }
                #Get entries
                $elements = $library->getElementsByTagName('BICDirectoryEntry');
                $queries = [];
                #Iterate entries
                foreach ($elements as $element) {
                    var_dump($element);
                }
                #Increase $libDate by 1 day
                $libDate = $libDate + 86400;
                #Remove library file
                #@unlink($download);
                exit;
            }
        }
        return true;
    }

    #Function to download BIC
    /**
     * @throws \Exception
     */
    private function download(int $date): bool|string
    {
        #Generate zip path
        $fileName = sys_get_temp_dir().'/'.date('Ymd', $date).'_ED807_full.xml';
        #Generate link
        $link = self::bicDownBase.date('d.m.Y', $date);
        #Check if cURL handle already created and create it if not
        if (empty(self::$curlHandle)) {
            self::$curlHandle = curl_init();
            if (self::$curlHandle === false) {
                throw new \Exception('Failed to initiate cURL handle');
            } else {
                if(!curl_setopt_array(self::$curlHandle, $this->CURL_OPTIONS)) {
                    throw new \Exception('Failed to set cURL handle options');
                }
            }
        }
        #Get page contents
        curl_setopt(self::$curlHandle, CURLOPT_URL, $link);
        #Get response
        $response = curl_exec(self::$curlHandle);
        $httpCode = curl_getinfo(self::$curlHandle, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode !== 200) {
            return false;
        } else {
            $data = substr($response, curl_getinfo(self::$curlHandle, CURLINFO_HEADER_SIZE));
        }
        #Load page as DOM Document
        libxml_use_internal_errors(true);
        $page = new \DOMDocument();
        $page->loadHTML($data);
        #Get all links on page
        $as = $page->getElementsByTagName('a');
        #Iterrate links to find the one we need
        foreach ($as as $a) {
            #Filter only those that has proper value
            if (preg_match('/\s*Справочник БИК\s*/iu', $a->textContent) === 1) {
                #Get href attribute
                $href = $a->getAttribute('href');
                #Skip link for "current" library
                if (preg_match('/\/s\/newbik/iu', $href) === 0) {
                    $href = self::bicBaseHref.$href;
                    #Attempt to actually download the zip file
                    if (file_put_contents($fileName.'.zip', @fopen($href, 'r'))) {
                        #Unzip the file
                        if (file_exists($fileName.'.zip')) {
                            $zip = new \ZipArchive;
                            if ($zip->open($fileName.'.zip') === true) {
                                $zip->extractTo(sys_get_temp_dir());
                                $zip->close();
                            }
                            #Remove zip file
                            @unlink($fileName.'.zip');
                            #Check if ED807 file exists
                            if (file_exists($fileName)) {
                                return $fileName;
                            } else {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            }
        }
        #This means, that no file was found for the date (which is not necessarily a problem)
        return true;
    }











    #########
    #Old code
    #########


    #Function to update the BICs data in database
    public function dbUpdate_old(string $dataDir, string $date = 'DDMMYYYY'): bool|string
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
                            /** @noinspection SqlResolve */
                            $queries[] = [
                                'INSERT INTO `bic__'.(($file == 'bnkseek' || $file == 'bnkdel') ? 'list' : $file).'` SET '.$update.' ON DUPLICATE KEY UPDATE '.$update,
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

    /**
     * @throws \Exception
     */
    public function getCurrent(string $vkey): array
    {
        #Get general data
        $bicDetails = (new Controller)->selectRow('SELECT `biclist`.`VKEY`, `VKEYDEL`, `bic__keybaseb`.`BVKEY`, `bic__keybasef`.`FVKEY`, `ADR`, `AT1`, `AT2`, `CKS`, `DATE_CH`, `DATE_IN`, `DATEDEL`, `DT_IZM`, `IND`, `KSNP`, `NAMEP`, `bic__keybaseb`.`NAMEMAXB`, `bic__keybasef`.`NAMEMAXF`, `NEWKS`, biclist.`NEWNUM`, `bic__co`.`BIC_UF`, `bic__co`.`DT_ST`, `bic__co`.`DT_FIN`, `bic__bik_swif`.`KOD_SWIFT`, `bic__bik_swif`.`NAME_SRUS`, `NNP`, `OKPO`, `PERMFO`, `bic__pzn`.`NAME` AS `PZN`, `bic__real`.`NAME_OGR` AS `REAL`, `bic__rclose`.`NAMECLOSE` AS `R_CLOSE`, `REGN`, `bic__reg`.`NAME` AS `RGN`, `bic__reg`.`CENTER`, `RKC`, `SROK`, `TELEF`, `bic__tnp`.`FULLNAME` AS `TNP`, `bic__uerko`.`UERNAME` AS `UER`, `bic__prim`.`PRIM1`, `bic__prim`.`PRIM2`, `bic__prim`.`PRIM3`, `bic__rayon`.`NAME` AS `RAYON`, `bic__kgur`.`KGUR` FROM `bic__list` biclist
                LEFT JOIN `bic__bik_swif` ON `bic__bik_swif`.`KOD_RUS` = biclist.`NEWNUM`
                LEFT JOIN `bic__reg` ON `bic__reg`.`RGN` = biclist.`RGN`
                LEFT JOIN `bic__uerko` ON `bic__uerko`.`UERKO` = biclist.`UER`
                LEFT JOIN `bic__tnp` ON `bic__tnp`.`TNP` = biclist.`TNP`
                LEFT JOIN `bic__pzn` ON `bic__pzn`.`PZN` = biclist.`PZN`
                LEFT JOIN `bic__real` ON `bic__real`.`REAL` = biclist.`REAL`
                LEFT JOIN `bic__rclose` ON `bic__rclose`.`R_CLOSE` = biclist.`R_CLOSE`
                LEFT JOIN `bic__keybaseb` ON `bic__keybaseb`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `bic__keybasef` ON `bic__keybasef`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `bic__prim` ON `bic__prim`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `bic__rayon` ON `bic__rayon`.`VKEY` = biclist.`VKEY`
                LEFT JOIN `bic__co` ON `bic__co`.`BIC_CF` = biclist.`NEWNUM`
                LEFT JOIN `bic__kgur` ON `bic__kgur`.`NEWNUM` = biclist.`RKC`
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

    /**
     * @throws \Exception
     */
    public function Search(string $what = ''): array
    {
        return (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `DATEDEL` FROM `bic__list` WHERE `VKEY`=:name OR `NEWNUM`=:name OR `KSNP`=:name OR `REGN`=:name OR MATCH (`NAMEP`, `ADR`) AGAINST (:name IN BOOLEAN MODE) ORDER BY `NAMEP`', [':name'=>$what]);
    }

    #Function to get basic statistics

    /**
     * @throws \Exception
     */
    public function Statistics(int $lastChanges = 25): array
    {
        #Cache Controller
        $dbCon = (new Controller);
        $temp = $dbCon->selectAll('SELECT COUNT(*) as \'bics\' FROM `bic__list` WHERE `DATEDEL` IS NULL UNION ALL SELECT COUNT(*) as \'bics\' FROM `bic__list` WHERE `DATEDEL` IS NOT NULL');
        $statistics['bicactive'] = $temp[0]['bics'];
        $statistics['bicdeleted'] = $temp[1]['bics'];
        $statistics['bicchanges'] = $dbCon->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `DATEDEL` FROM `bic__list` ORDER BY `DT_IZM` DESC LIMIT '.$lastChanges);
        return $statistics;
    }

    #Function to prepare tables
    public function install(): bool
    {
        #Get contents from SQL file
        $sql = file_get_contents(__DIR__.'\install.sql');
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

    /**
     * @throws \Exception
     */
    private function predecessors(string $vkey): array
    {
        #Get initial list
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `DATEDEL` FROM `bic__list` WHERE `VKEYDEL` = :newnum ORDER BY `NAMEP`', [':newnum'=>$vkey]);
        if (empty($bank)) {
            $bank = array();
        } else {
            foreach ($bank as $key=>$item) {
                #Check for predecessors of predecessor
                $next = $this->predecessors($item['id']);
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

    /**
     * @throws \Exception
     */
    private function successors(string $vkey): array
    {
        #Get initial list
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `VKEYDEL`, `DATEDEL` FROM `bic__list` WHERE `VKEY` = :newnum ORDER BY `NAMEP`', [':newnum'=>$vkey]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get successors for each successor
            foreach ($bank as $key=>$item) {
                if (!empty($item[0]['VKEYDEL']) && $item[0]['VKEYDEL'] != $vkey && $bank[0]['VKEYDEL'] != $bank[0]['id']) {
                    $bank[$key] = array_merge($item, $this->successors($item[0]['id']));
                }
            }
        }
        return $bank;
    }

    #Function to get all RKCs for a bank as a chain

    /**
     * @throws \Exception
     */
    private function rkcChain(string $bic): array
    {
        #Get initial list
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `NEWNUM`, `RKC`, `DATEDEL` FROM `bic__list` WHERE `NEWNUM` = :newnum AND `DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
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

    /**
     * @throws \Exception
     */
    private function bicUf(string $bic): array
    {
        #Get initial list
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NAMEP` as `name`, `DATEDEL`, `bic__co`.`BIC_UF` FROM `bic__list` biclist LEFT JOIN `bic__co` ON `bic__co`.`BIC_CF` = biclist.`NEWNUM` WHERE biclist.`NEWNUM` = :newnum AND biclist.`DATEDEL` IS NULL LIMIT 1', [':newnum'=>$bic]);
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

    /**
     * @throws \Exception
     */
    private function filials(string $bic): array
    {
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `bic__list`.`VKEY` as `id`, `bic__list`.`NEWNUM`, `bic__list`.`NAMEP` as `name`, `bic__list`.`DATEDEL` FROM `bic__co` bicco LEFT JOIN `bic__list` ON `bic__list`.`NEWNUM` = bicco.`BIC_CF` WHERE `BIC_UF` = :newnum ORDER BY `bic__list`.`NAMEP`', [':newnum'=>$bic]);
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
