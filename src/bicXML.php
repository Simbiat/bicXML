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


        (new HomeTests)->testDump($dbController->selectAll('SELECT  *, (SELECT COUNT(*) FROM `bic__list` a WHERE a.`BIC` = b.`BIC`) as `count` FROM `bic__list` b WHERE `VKEYDEL` IS NOT NULL HAVING `count` > 1 ORDER BY `count` DESC;'));
        exit;

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

    #Function to return current data about the bank
    /**
     * @throws \Exception
     */
    public function getCurrent(string $vkey): array
    {
        #Get general data
        $bicDetails = (new Controller)->selectRow('SELECT `biclist`.`VKEY`, `VKEYDEL`, `BVKEY`, `FVKEY`, `Adr`, `AT1`, `AT2`, `CKS`, `DATE_CH`, `DateIn`, `DateOut`, `Updated`, `Ind`, `bic__srvcs`.`Description` AS `Srvcs`, `NameP`, `NAMEMAXB`, `NEWKS`, biclist.`BIC`, `PrntBIC`, `SWIFT_NAME`, `Nnp`, `OKPO`, `PERMFO`, `bic__pzn`.`NAME` AS `PtType`, `bic__rclose`.`NAMECLOSE` AS `R_CLOSE`, `RegN`, `bic__reg`.`NAME` AS `Rgn`, `bic__reg`.`CENTER`, `RKC`, `SROK`, `TELEF`, `Tnp`, `PRIM1`, `PRIM2`, `PRIM3` FROM `bic__list` biclist
                LEFT JOIN `bic__reg` ON `bic__reg`.`RGN` = biclist.`Rgn`
                LEFT JOIN `bic__pzn` ON `bic__pzn`.`PtType` = biclist.`PtType`
                LEFT JOIN `bic__rclose` ON `bic__rclose`.`R_CLOSE` = biclist.`R_CLOSE`
                LEFT JOIN `bic__srvcs` ON `bic__srvcs`.`Srvcs` = biclist.`Srvcs`
                WHERE biclist.`VKEY` = :vkey', [':vkey'=>$vkey]);
        if (empty($bicDetails)) {
            return [];
        } else {
            #Generating address from different fields
            $bicDetails['Adr'] = (!empty($bicDetails['Ind']) ? $bicDetails['Ind'].' ' : '').(!empty($bicDetails['Tnp']) ? $bicDetails['Tnp'].' ' : '').(!empty($bicDetails['Nnp']) ? $bicDetails['Nnp'].', ' : '').$bicDetails['Adr'];
            #Get list of phones
            if (!empty($bicDetails['TELEF'])) {
                $bicDetails['TELEF'] = $this->phoneList($bicDetails['TELEF']);
            } else {
                $bicDetails['TELEF'] = [];
            }
            #If RKC=BIC it means, that current bank is RKC and does not have bank above it
            if ($bicDetails['RKC'] == $bicDetails['BIC']) {
                $bicDetails['RKC'] = '';
            }
            #If we have an RKC - get the whole chain of RKCs
            if (!empty($bicDetails['RKC'])) {$bicDetails['RKC'] = $this->rkcChain($bicDetails['RKC']);}
            #Get authorized branch
            if (!empty($bicDetails['PrntBIC'])) {$bicDetails['PrntBIC'] = $this->bicUf($bicDetails['PrntBIC']);}
            #Get all branches of the bank (if any)
            $bicDetails['filials'] = $this->filials($bicDetails['BIC']);
            #Get the chain of predecessors (if any)
            $bicDetails['predecessors'] = $this->predecessors($bicDetails['VKEY']);
            #Get the chain of successors (if any)
            $bicDetails['successors'] = (empty($bicDetails['VKEYDEL']) ? [] : $this->successors($bicDetails['VKEYDEL']));
            return $bicDetails;
        }
    }

    #Function to search for BICs

    /**
     * @throws \Exception
     */
    public function Search(string $what = ''): array
    {
        return (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NameP` as `name`, `DateOut` FROM `bic__list` WHERE `VKEY`=:name OR `BIC`=:name OR `OLD_NEWNUM`=:name OR `RegN`=:name OR MATCH (`NameP`, `Adr`) AGAINST (:name IN BOOLEAN MODE) ORDER BY `NameP`', [':name'=>$what]);
    }

    #Function to get basic statistics

    /**
     * @throws \Exception
     */
    public function Statistics(int $lastChanges = 25): array
    {
        #Cache Controller
        $dbCon = (new Controller);
        $temp = $dbCon->selectAll('SELECT COUNT(*) as \'bics\' FROM `bic__list` WHERE `DateOut` IS NULL UNION ALL SELECT COUNT(*) as \'bics\' FROM `bic__list` WHERE `DateOut` IS NOT NULL');
        $statistics['bicactive'] = $temp[0]['bics'];
        $statistics['bicdeleted'] = $temp[1]['bics'];
        $statistics['bicchanges'] = $dbCon->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NameP` as `name`, `DateOut` FROM `bic__list` ORDER BY `Updated` DESC LIMIT '.$lastChanges);
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
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NameP` as `name`, `DateOut` FROM `bic__list` WHERE `VKEYDEL` = :BIC ORDER BY `NameP`', [':BIC'=>$vkey]);
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
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NameP` as `name`, `VKEYDEL`, `DateOut` FROM `bic__list` WHERE `VKEY` = :BIC ORDER BY `NameP`', [':BIC'=>$vkey]);
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
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, `VKEY` as `id`, `NameP` as `name`, `BIC`, `RKC`, `DateOut` FROM `bic__list` WHERE `BIC` = :BIC AND `DateOut` IS NULL LIMIT 1', [':BIC'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get RKC for RKC
            if (!empty($bank[0]['RKC']) && $bank[0]['RKC'] != $bic && $bank[0]['RKC'] != $bank[0]['BIC']) {
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
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`,`VKEY` as `id`,`NameP` as `name`, `DateOut`, `PrntBIC` FROM `bic__list` WHERE `BIC` = :BIC LIMIT 1', [':BIC'=>$bic]);
        if (empty($bank)) {
            $bank = [];
        } else {
            #Get authorized branch of authorized branch
            if (!empty($bank[0]['PrntBIC']) && $bank[0]['PrntBIC'] != $bic && isset($bank[0]['BIC']) && $bank[0]['PrntBIC'] != $bank[0]['BIC']) {
                $bank = array_merge($bank, $this->bicUf($bank[0]['PrntBIC']));
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
        $bank = (new Controller)->selectAll('SELECT \'bic\' as `type`, biclist.`VKEY` as `id`, biclist.`BIC`, biclist.`NameP` as `name`, biclist.`DateOut` FROM `bic__list` biclist LEFT JOIN `bic__list` bicco ON biclist.`BIC` = bicco.`PrntBIC` WHERE biclist.`PrntBIC` = :BIC ORDER BY biclist.`NameP`', [':BIC'=>$bic]);
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
