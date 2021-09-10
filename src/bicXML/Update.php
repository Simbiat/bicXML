<?php
declare(strict_types=1);
namespace Simbiat\bicXML;

use Simbiat\ArrayHelpers;
use Simbiat\Database\Controller;

class Update
{
    private string $prefix;
    private ?Controller $dbController;
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
    #cURL Handle is static to allow reuse of single instance, if possible and needed
    public static \CurlHandle|null|false $curlHandle = null;

    public function __construct(string $prefix = 'bic__')
    {
        #Set prefix for SQL
        $this->prefix = $prefix;
        #Cache DB controller
        $this->dbController = (new Controller);
    }


    #Function to update library in database
    /**
     * @throws \Exception
     */
    public function dbUpdate(): bool
    {
        if (empty($this->dbController)) {
            return false;
        }
        #Cache ArrayHelpers
        $arrayHelpers = (new ArrayHelpers());
        $currentDate = strtotime(date('d.m.Y', time()));
        #Get date of current library
        $libDate = $this->dbController->selectValue('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'date\';');
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
                $fileDate = $elements->evaluate('string(/*/@EDDate)');
                if ($fileDate !== date('Y-m-d', $libDate)) {
                    #Date mismatch. Stop processing to avoid loosing sequence
                    return false;
                }
                #Get entries
                $elements = $library->getElementsByTagName('BICDirectoryEntry');
                #List of BICs to compare against current database
                $bics = [];
                #Array for queries
                $queries = [];
                #Iterate entries
                foreach ($elements as $element) {
                    #Get BIC
                    $bic = $element->getAttribute('BIC');
                    $bics[] = $bic;
                    #Get general details
                    $details = $element->getElementsByTagName('ParticipantInfo')[0];
                    #Get restrictions
                    $restrictions = $element->getElementsByTagName('RstrList');
                    #Get SWIFT codes
                    $swifts = $element->getElementsByTagName('SWBICS');
                    #Get accounts
                    $accounts = $element->getElementsByTagName('Accounts');
                    #Generate array, which can be compared to what we can get from DB
                    $details = $arrayHelpers->attributesToArray($details, true, ['BIC', 'DateIn', 'NameP', 'EnglName', 'XchType', 'PtType', 'Srvcs', 'UID', 'PrntBIC', 'CntrCd', 'RegN', 'Ind', 'Rgn', 'Tnp', 'Nnp', 'Adr']);
                    $details['BIC'] = $bic;
                    #Ensure some old or unused fields are removed
                    unset($details['NPSParticipant'], $details['ParticipantStatus']);
                    ksort($details, SORT_NATURAL);
                    #Prepare bindings
                    $bindings = [];
                    foreach (array_keys($details) as $key) {
                        $bindings[':'.$key] = [
                            ($details[$key] === NULL ? NULL : $details[$key]),
                            ($details[$key] === NULL ? 'null' : 'string'),
                        ];
                    }
                    #Get current details
                    $currentDetails = $this->dbController->selectRow(
                        'SELECT `BIC`, `DateIn`, `NameP`, `EnglName`, `XchType`, `PtType`, `Srvcs`, `UID`, `PrntBIC`, `CntrCd`, `RegN`, `Ind`, `Rgn`, `Tnp`, `Nnp`, `Adr` FROM `bic__list` WHERE `BIC`=:BIC;',
                        [':BIC' => $bic,]
                    );
                    ksort($currentDetails, SORT_NATURAL);
                    #Check if BIC exists at all
                    if (empty($currentDetails)) {
                        #We need to INSERT
                        $queries[] = [
                            'INSERT INTO `bic__list` (`BIC`, `DateIn`, `NameP`, `EnglName`, `XchType`, `PtType`, `Srvcs`, `UID`, `PrntBIC`, `CntrCd`, `RegN`, `Ind`, `Rgn`, `Tnp`, `Nnp`, `Adr`) VALUES (:BIC, :DateIn, :NameP, :EnglName, :XchType, :PtType, :Srvcs, :UID, :PrntBIC, :CntrCd, :RegN, :Ind, :Rgn, :Tnp, :Nnp, :Adr);',
                            array_merge($bindings, [':fileDate' => $fileDate]),
                        ];
                    } else {
                        #Compare details
                        if ($details !== $currentDetails) {
                            #We need to update
                            $queries[] = [
                                'UPDATE `bic__list` SET `DateIn`=:DateIn, `Updated`=:fileDate, `NameP`=:NameP, `EnglName`=:EnglName, `XchType`=:XchType, `PtType`=:PtType, `Srvcs`=:Srvcs, `UID`=:UID, `PrntBIC`=:PrntBIC, `CntrCd`=:CntrCd, `RegN`=:CntrCd, `Ind`=:Ind, `Rgn`=:Rgn, `Tnp`=:Tnp, `Nnp`=:Nnp, `Adr`=:Adr WHERE `BIC`=:BIC;',
                                array_merge($bindings, [':fileDate' => $fileDate]),
                            ];
                        }
                    }
                    #Process restrictions
                    if (count($restrictions) > 0) {
                        #Convert to array
                        $libraryRest = [];
                        foreach ($restrictions as $restriction) {
                            $libraryRest[] = $arrayHelpers->attributesToArray($restriction);
                            ksort($libraryRest[array_key_last($libraryRest)]);
                        }
                        #Get current restrictions
                        $currentRest = $this->dbController->selectAll(
                            'SELECT `Rstr`, `RstrDate` FROM `bic__bic_rstr` WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [':BIC' => $bic,]
                        );
                        #Check if any of restrictions were removed
                        foreach ($currentRest as $restriction) {
                            if (array_search($restriction, $libraryRest, true) === false) {
                                #Update DateOut for restriction
                                $queries[] = [
                                    'UPDATE `bic__bic_rstr` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `Rstr`=:Rstr AND `RstrDate`=:RstrDate;',
                                    [
                                        ':BIC' => $bic,
                                        ':Rstr' => $restriction['Rstr'],
                                        ':RstrDate' => $restriction['RstrDate'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                            }
                        }
                        #Add all new restrictions
                        foreach ($libraryRest as $restriction) {
                            if (array_search($restriction, $currentRest, true) === false) {
                                #Insert restriction
                                $queries[] = [
                                    'INSERT IGNORE INTO `bic__bic_rstr` (`BIC`, `Rstr`, `RstrDate`) VALUES (:BIC, :Rstr, :RstrDate);',
                                    [
                                        ':BIC' => $bic,
                                        ':Rstr' => $restriction['Rstr'],
                                        ':RstrDate' => $restriction['RstrDate'],
                                    ]
                                ];
                            }
                        }
                    } else {
                        #Set end of restriction for all entries if any exist
                        $queries[] = [
                            'UPDATE `bic__bic_rstr` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                    }
                    #Process swifts
                    if (count($swifts) > 0) {
                        #Convert to array
                        $librarySwift = [];
                        foreach ($swifts as $swift) {
                            $librarySwift[] = $arrayHelpers->attributesToArray($swift);
                            ksort($librarySwift[array_key_last($librarySwift)]);
                        }
                        #Get current SWIFTs
                        $currentSwift = $this->dbController->selectAll(
                            'SELECT `DefaultSWBIC`, `SWBIC` FROM `bic__swift` WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [':BIC' => $bic,]
                        );
                        #Add all SWIFTs. Updating Default flag if already existing
                        foreach ($librarySwift as $swift) {
                            if (array_search($swift, $currentSwift, true) === false) {
                                #Insert restriction
                                $queries[] = [
                                    'INSERT INTO `bic__swift` (`BIC`, `SWBIC`, `DefaultSWBIC`, `DateIn`) VALUES (:BIC, :SWBIC, :DefaultSWBIC, :fileDate) ON DUPLICATE KEY UPDATE `DefaultSWBIC`=:DefaultSWBIC;',
                                    [
                                        ':BIC' => $bic,
                                        ':SWBIC' => $swift['SWBIC'],
                                        ':DefaultSWBIC' => $swift['DefaultSWBIC'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                            }
                        }
                        #"Remove" SWIFTs that do not match what we already have. If Default flag has been updated on previous step, there will be no update here, because it will no longer match the condition
                        foreach ($currentSwift as $swift) {
                            if (array_search($swift, $librarySwift, true) === false) {
                                #Update DateOut for restriction
                                $queries[] = [
                                    'UPDATE `bic__swift` SET `DateOut`=:fileDate, `DefaultSWBIC`=0 WHERE `BIC`=:BIC AND `SWBIC`=:SWBIC AND `DefaultSWBIC`=:DefaultSWBIC;',
                                    [
                                        ':BIC' => $bic,
                                        ':SWBIC' => $swift['SWBIC'],
                                        ':DefaultSWBIC' => $swift['DefaultSWBIC'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                            }
                        }
                    } else {
                        #Close all SWIFTs
                        $queries[] = [
                            'UPDATE `bic__swift` SET `DateOut`=:fileDate, `DefaultSWBIC`=0 WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                    }
                    #Process accounts
                    if (count($accounts) > 0) {
                        #Convert to array
                        $libraryAccounts = [];
                        $libraryAccountsRest = [];
                        foreach ($accounts as $account) {
                            #Convert account
                            $libraryAccounts[] = $arrayHelpers->attributesToArray($account, true, ['CK']);
                            #Set last key
                            $lastKey = array_key_last($libraryAccounts);
                            unset($libraryAccounts[$lastKey]['AccountStatus']);
                            ksort($libraryAccounts[$lastKey]);
                            #Convert restrictions
                            if (count($account->getElementsByTagName('AccRstrList')) > 0) {
                                foreach ($account->getElementsByTagName('AccRstrList') as $restriction) {
                                    $libraryAccountsRest[$libraryAccounts[$lastKey]['Account']][] = $arrayHelpers->attributesToArray($restriction, true, ['SuccessorBIC']);
                                    ksort($libraryAccountsRest[$libraryAccounts[$lastKey]['Account']]);
                                }
                            }
                        }
                        #Get current SWIFTs
                        $currentAccounts = $this->dbController->selectAll(
                            'SELECT `Account`, `AccountCBRBIC`, `CK`, `DateIn`, `RegulationAccountType` FROM `bic__accounts` WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [':BIC' => $bic,]
                        );
                        #"Remove" accounts
                        foreach ($currentAccounts as $account) {
                            if (array_search($account, $libraryAccounts, true) === false) {
                                #Close all restrictions
                                $queries[] = [
                                    'UPDATE `bic__acc_rstr` SET `DateOut`=:fileDate WHERE `DateOut` IS NULL AND `Account`=:Account;',
                                    [
                                        ':Account' => $account['Account'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                                #Close account
                                $queries[] = [
                                    'UPDATE `bic__accounts` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `Account`=:Account AND `DateOut` IS NULL;',
                                    [
                                        ':BIC' => $bic,
                                        ':Account' => $account['Account'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                            }
                        }
                        #Update accounts
                        foreach ($libraryAccounts as $account) {
                            #Update account
                            $queries[] = [
                                'INSERT INTO `bic__accounts` (`BIC`, `Account`, `AccountCBRBIC`, `RegulationAccountType`, `CK`, `DateIn`) VALUES (:BIC, :Account, :AccountCBRBIC, :RegulationAccountType, :CK, :fileDate) ON DUPLICATE KEY UPDATE `AccountCBRBIC`=:AccountCBRBIC, `RegulationAccountType`=:RegulationAccountType, `CK`=:CK;',
                                [
                                    ':BIC' => $bic,
                                    ':Account' => $account['Account'],
                                    ':AccountCBRBIC' => $account['AccountCBRBIC'],
                                    ':RegulationAccountType' => $account['RegulationAccountType'],
                                    ':CK' => $account['CK'],
                                    ':fileDate' => $fileDate,
                                ]
                            ];
                            if (!empty($libraryAccountsRest[$account['Account']])) {
                                #Get current restrictions
                                $currentRest = $this->dbController->selectAll(
                                    'SELECT `AccRstr`, `AccRstrDate`, `SuccessorBIC` FROM `bic__acc_rstr` WHERE `Account`=:Account;',
                                    [':Account' => $account['Account'],]
                                );
                                #Add all new restrictions
                                foreach ($libraryAccountsRest[$account['Account']] as $restriction) {
                                    if (array_search($restriction, $currentRest, true) === false) {
                                        #Insert restriction
                                        $queries[] = [
                                            'INSERT INTO `bic__acc_rstr` (`Account`, `AccRstr`, `AccRstrDate`, `SuccessorBIC`) VALUES (:Account, :AccRstr, :AccRstrDate, :SuccessorBIC) ON DUPLICATE KEY UPDATE `SuccessorBIC`=:SuccessorBIC;',
                                            [
                                                ':Account' => $account['Account'],
                                                ':AccRstr' => $restriction['AccRstr'],
                                                ':AccRstrDate' => $restriction['AccRstrDate'],
                                                ':SuccessorBIC' => $restriction['SuccessorBIC'],
                                            ]
                                        ];
                                    }
                                }
                                #Check if any of restrictions were removed
                                foreach ($currentRest as $restriction) {
                                    if (array_search($restriction, $libraryAccountsRest[$account['Account']], true) === false) {
                                        #Update DateOut for restriction
                                        $queries[] = [
                                            'UPDATE `bic__acc_rstr` SET `DateOut`=:fileDate WHERE `Account`=:Account AND `AccRstr`=:AccRstr AND `AccRstrDate`=:AccRstrDate;',
                                            [
                                                ':Account' => $account['Account'],
                                                ':AccRstr' => $restriction['AccRstr'],
                                                ':AccRstrDate' => $restriction['AccRstrDate'],
                                                ':fileDate' => $fileDate,
                                            ]
                                        ];
                                    }
                                }
                            } else {
                                #Remove all restrictions for the account
                                $queries[] = [
                                    'UPDATE `bic__acc_rstr` SET `DateOut`=:fileDate WHERE `Account`=:Account;',
                                    [
                                        ':Account' => $account['Account'],
                                        ':fileDate' => $fileDate,
                                    ]
                                ];
                            }
                        }
                    } else {
                        #Close all restrictions for previously open accounts
                        $queries[] = [
                            'UPDATE `bic__acc_rstr` SET `DateOut`=:fileDate WHERE `DateOut` IS NULL AND `Account` IN (SELECT `Account` FROM `bic__accounts` WHERE `BIC`=:BIC AND `DateOut` IS NULL);',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        #Close all open accounts
                        $queries[] = [
                            'UPDATE `bic__accounts` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                    }
                }
                #Check for removed BICs
                $currentBics = $this->dbController->selectColumn('SELECT `BIC` FROM `bic__list` WHERE `DateOut` IS NULL;');
                foreach ($currentBics as $bic) {
                    if (!in_array($bic, $bics)) {
                        #Set end of restriction for all entries if any exist
                        $queries[] = [
                            'UPDATE `bic__bic_rstr` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        #Close all SWIFTs
                        $queries[] = [
                            'UPDATE `bic__swift` SET `DateOut`=:fileDate, `DefaultSWBIC`=0 WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        #Close all restrictions for previously open accounts
                        $queries[] = [
                            'UPDATE `bic__acc_rstr` SET `DateOut`=:fileDate WHERE `DateOut` IS NULL AND `Account` IN (SELECT `Account` FROM `bic__accounts` WHERE `BIC`=:BIC AND `DateOut` IS NULL);',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        #Close all open accounts
                        $queries[] = [
                            'UPDATE `bic__accounts` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        #Close BIC itself
                        $queries[] = [
                            'UPDATE `bic__list` SET `DateOut`=:fileDate WHERE `BIC`=:BIC AND `DateOut` IS NULL;',
                            [
                                ':BIC' => $bic,
                                ':fileDate' => $fileDate,
                            ]
                        ];
                        (new HomeTests())->testDump($queries);
                    }
                }
                #Increase $libDate by 1 day
                $libDate = $libDate + 86400;
                $queries[] = [
                    'UPDATE `'.$this->prefix.'settings` SET `value`=:date WHERE `setting`=\'date\';',
                    [':date' => $libDate],
                ];
                (new HomeTests())->testDump($queries);
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
}
