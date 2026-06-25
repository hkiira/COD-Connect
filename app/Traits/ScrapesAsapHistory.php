<?php namespace App\Traits; trait ScrapesAsapHistory {

    public function login()
    {
        return \Illuminate\Support\Facades\Cache::remember('asap_session_id', 3600, function () {
            $curl = curl_init();
            $body = [
                'username' => 'styemen.ma@gmail.com',
                'password' => 'azerty',
            ];
            $body = http_build_query($body);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            $data = [
                "url" => "https://app.asapdelivery.ma/login.php",
                "disableRedirection" => "true"
            ];
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Content-Type: application/x-www-form-urlencoded",
                "Accept: */*",
            ));
            curl_setopt($curl, CURLOPT_HEADER, true); // Get headers
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLINFO_HEADER_OUT, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);      // Include headers in output
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            $response = \App\Services\ScrapeDoService::executeCurl($curl, $data);
            curl_close($curl);

            // Use the header size to split headers from body
            $headerSize = strpos($response, "\r\n\r\n");
            $headerText = ($headerSize !== false) ? substr($response, 0, $headerSize) : $response;

            // Parse Set-Cookie for PHPSESSID
            preg_match('/PHPSESSID=([^;]+)/i', $headerText, $matches);
            if (isset($matches[1])) {
                return "PHPSESSID=" . trim($matches[1]);
            }

            return null;
        });
    }

    public function scrapeColisHistoryData($orderId, $sessionId)
    {
        ini_set('memory_limit', '512M');
        
        $curl = curl_init();
        $body = [
            'id' => $orderId,
            'action' => 'showcolihistory',
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "Accept: */*",
            "Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7,ar;q=0.6",
            "Origin: https://app.asapdelivery.ma",
            "Referer: https://app.asapdelivery.ma/colisu.php",
            "X-Requested-With: XMLHttpRequest",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

        // If the response is empty or fails, return an empty structured array
        if (empty($uploadResponse)) {
            return [
                'meta' => [],
                'state_history' => [],
                'address_history' => [],
                'call_history' => [],
                'error' => 'Empty response from ASAP Delivery'
            ];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $uploadResponse);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $meta = [];
        // The XPath was failing due to bad string escaping when I generated this trait
        $paragraphs = $xpath->query('//div[contains(@class, "lx-command-history")]/p');
        
        if ($paragraphs) {
            foreach ($paragraphs as $p) {
                $nodes = $p->childNodes;
                $key = '';
                $value = '';
                foreach ($nodes as $node) {
                    if ($node->nodeName === 'strong') {
                        $key = trim(str_replace(':', '', $node->nodeValue));
                    } elseif ($node->nodeType === XML_TEXT_NODE) {
                        $value .= $node->nodeValue;
                    }
                }
                if ($key) {
                    $meta[$key] = trim($value);
                }
            }
        }

        $stateHistory = [];
        $addressHistory = [];
        $callHistory = [];

        $tables = $xpath->query('//div[contains(@class, "lx-table")]/table');
        if ($tables && $tables->length >= 1) {
            $rows = $xpath->query('./tr[position()>1]', $tables->item(0));
            if ($rows) {
                foreach ($rows as $row) {
                    $cells = $row->getElementsByTagName('td');
                    if ($cells->length >= 5) {
                        $stateHistory[] = [
                            'date' => trim($cells->item(0)->textContent),
                            'etat' => trim($cells->item(1)->textContent),
                            'date_reporte' => trim($cells->item(2)->textContent),
                            'description' => trim($cells->item(3)->textContent),
                            'utilisateur' => trim($cells->item(4)->textContent),
                        ];
                    }
                }
            }
        }

        if ($tables && $tables->length >= 2) {
            $rows = $xpath->query('./tr[position()>1]', $tables->item(1));
            if ($rows) {
                foreach ($rows as $row) {
                    $cells = $row->getElementsByTagName('td');
                    if ($cells->length >= 4) {
                        $addressHistory[] = [
                            'date' => trim($cells->item(0)->textContent),
                            'client' => trim($cells->item(1)->textContent),
                            'adresse' => trim($cells->item(2)->textContent),
                            'telephone' => trim($cells->item(3)->textContent),
                        ];
                    }
                }
            }
        }

        if ($tables && $tables->length >= 3) {
            $rows = $xpath->query('./tr[position()>1]', $tables->item(2));
            if ($rows) {
                foreach ($rows as $row) {
                    $cells = $row->getElementsByTagName('td');
                    if ($cells->length >= 3) {
                        $callHistory[] = [
                            'date' => trim($cells->item(0)->textContent),
                            'action' => trim($cells->item(1)->textContent),
                            'utilisateur' => trim($cells->item(2)->textContent),
                        ];
                    }
                }
            }
        }

        return [
            'meta' => $meta,
            'state_history' => $stateHistory,
            'address_history' => $addressHistory,
            'call_history' => $callHistory,
        ];
    }
}
