<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;

class SpeedafController extends Controller
{
    // Utility: Get config from env
    private function getAppCode() {
        return env('SPEEDAF_APPCODE', 'YOUR_APPCODE');
    }
    private function getSecretKey() {
        return env('SPEEDAF_SECRETKEY', 'YOUR_SECRETKEY');
    }
    private function getBaseUrl() {
        return env('SPEEDAF_BASE_URL', 'https://apis.speedaf.com/open-api/express/');
    }

    // Utility: DES CBC PKCS5Padding encryption (OpenSSL)
    private function desEncrypt($data, $key) {
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        $padded = $this->pkcs5Pad($data, 8);
        $encrypted = openssl_encrypt($padded, 'DES-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }
    private function desDecrypt($data, $key) {
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        $decrypted = openssl_decrypt(base64_decode($data), 'DES-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $this->pkcs5Unpad($decrypted);
    }
    private function pkcs5Pad($text, $blocksize) {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }
    private function pkcs5Unpad($text) {
        $len = strlen($text);
        if ($len === 0) return '';
        $pad = ord($text[$len - 1]);
        // PKCS#5 padding must be between 1 and block size (8)
        if ($pad <= 0 || $pad > 8) return false;
        $padStr = str_repeat(chr($pad), $pad);
        if (substr($text, -$pad) !== $padStr) return false;
        return substr($text, 0, $len - $pad);
    }
    // Utility: Generate sign
    private function generateSign($timestamp, $secretKey, $data) {
        return md5($timestamp . $secretKey . $data);
    }
    // Utility: Build encrypted request body
    private function buildRequestBody($dataArr) {
        $timestamp = (string)(int)(microtime(true) * 1000);
        $appCode = $this->getAppCode();
        $secretKey = $this->getSecretKey();
        $dataJson = json_encode($dataArr, JSON_UNESCAPED_UNICODE);
        $sign = $this->generateSign($timestamp, $secretKey, $dataJson);
        $body = [
            'sign' => $sign,
            'data' => $dataJson
        ];
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $encryptedBody = $this->desEncrypt($bodyJson, $secretKey);
        return [
            'body' => $encryptedBody,
            'timestamp' => $timestamp,
            'appCode' => $appCode
        ];
    }
    // Utility: Decrypt response
    private function decryptResponse($data) {
        $secretKey = $this->getSecretKey();
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        // First try direct openssl decrypt (some responses may already be unpadded)
        $raw = openssl_decrypt(base64_decode($data), 'DES-CBC', $secretKey, OPENSSL_RAW_DATA, $iv);

        // Try decoding raw directly
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        // If raw failed, try unpadding and decode
        $unpadded = $this->pkcs5Unpad($raw);
        if ($unpadded !== false) {
            $json = json_decode($unpadded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
            // try object decode as fallback
            $jsonObj = json_decode($unpadded);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $jsonObj;
            }
        }

        // As last resort, return null
        return null;
    }
    // Example: Order creation
    public function createOrder(Request $request) {
        // Normalize payload and ensure required fields
        $incoming = $request->all();
        $payload = $incoming;
        $payload['customerCode'] = $payload['customerCode'] ?? env('SPEEDAF_CUSTOMERCODE');
        $payload['platformSource'] = $payload['platformSource'] ?? env('SPEEDAF_PLATFORMSOURCE');

        $req = $this->buildRequestBody($payload);
        $url = $this->getBaseUrl() . 'order/createOrder?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
        // Send raw encrypted body string to avoid Guzzle treating body as array
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->withBody($req['body'], 'application/json')->post($url);

        $rawResponseBody = $response->body();
        try {
            Log::debug('Speedaf createOrder request', [
                'request_payload' => $payload,
                'url' => $url,
                'raw_response_body' => $rawResponseBody,
            ]);
        } catch (\Throwable $e) {}

        $result = $response->json();
        if (isset($result['data'])) {
            $decrypted = $this->decryptResponse($result['data']);
            return response()->json(['success' => true, 'data' => $decrypted]);
        }
        return response()->json(['success' => false, 'error' => $result['error'] ?? 'Unknown error']);
    }
    // Example: Track order
    public function trackOrder(Request $request) {
        // Robustly collect tracking numbers from many possible input keys
        $incoming = $request->all();
        $possibleKeys = ['trackingNoList','tracking_no_list','trackingNos','trackingNosList','trackingNo','tracking_numbers','trackingNumbers','mailNoList','mailNo','mail_no','billCode','waybillNo','waybill_no'];
        $trackingList = [];
        foreach ($possibleKeys as $k) {
            if (!isset($incoming[$k])) continue;
            $val = $incoming[$k];
            if (is_array($val)) {
                $trackingList = array_merge($trackingList, $val);
                continue;
            }
            if (is_string($val)) {
                if (strpos($val, ',') !== false) {
                    $parts = array_map('trim', explode(',', $val));
                    $trackingList = array_merge($trackingList, $parts);
                } else {
                    $trackingList[] = trim($val);
                }
            }
        }
        // normalize, unique and remove empty
        $trackingList = array_values(array_filter(array_map('strval', array_unique($trackingList))));

        if (count($trackingList) === 0) {
            return response()->json(['success' => false, 'error' => 'No tracking numbers provided']);
        }

        // Build payload the Speedaf API expects. Include both plural and singular forms
        $payload = $incoming;
        $payload['trackingNoList'] = $trackingList;
        $payload['trackingNo'] = count($trackingList) === 1 ? $trackingList[0] : null;
        $payload['mailNoList'] = $trackingList; // compatibility alias
        $payload['mailNo'] = count($trackingList) === 1 ? $trackingList[0] : null;
        $payload['billCode'] = count($trackingList) === 1 ? $trackingList[0] : ($payload['billCode'] ?? null);
        $payload['customerCode'] = $payload['customerCode'] ?? env('SPEEDAF_CUSTOMERCODE');
        $payload['platformSource'] = $payload['platformSource'] ?? env('SPEEDAF_PLATFORMSOURCE');

        // Build encrypted request using normalized payload
        $req = $this->buildRequestBody($payload);
        $url = $this->getBaseUrl() . 'track/query?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->send('POST', $url, [
            'body' => $req['body']
        ]);
        $rawResponseBody = $response->body();
        $result = $response->json();

        // Log request/response at debug level for troubleshooting
        try {
            Log::debug('Speedaf track request', [
                'request_payload' => $request->all(),
                'url' => $url,
                'raw_response_body' => $rawResponseBody,
            ]);
        } catch (\Throwable $e) {
            // ignore logging errors
        }

        if (isset($result['data'])) {
            $decrypted = $this->decryptResponse($result['data']);
            // Normalize to array
            if (is_object($decrypted)) {
                $decrypted = json_decode(json_encode($decrypted), true);
            }
            if (!is_array($decrypted)) {
                return response()->json(['success' => false, 'error' => 'Unexpected response format']);
            }

            // Annotate each item with whether tracks were found
            $anyFound = false;
            foreach ($decrypted as &$item) {
                $tracks = $item['tracks'] ?? [];
                $found = is_array($tracks) && count($tracks) > 0;
                $item['found'] = $found;
                if ($found) $anyFound = true;
            }

            // If caller requested strict mode and nothing was found, return as error
            $strict = filter_var($request->input('strict', false), FILTER_VALIDATE_BOOLEAN);
            // Prepare optional debug info if app debug and request debug true
            $wantsDebug = filter_var($request->input('debug', false), FILTER_VALIDATE_BOOLEAN);
            $appDebug = config('app.debug', false);
            $debugInfo = null;
            if ($wantsDebug && $appDebug) {
                $secretKey = $this->getSecretKey();
                $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
                $rawDecrypted = null;
                try {
                    $rawDecrypted = openssl_decrypt(base64_decode($result['data']), 'DES-CBC', $secretKey, OPENSSL_RAW_DATA, $iv);
                } catch (\Throwable $_e) {
                    $rawDecrypted = null;
                }
                $unpadded = $this->desDecrypt($result['data'], $secretKey);
                $debugInfo = [
                    'raw_response_body' => $rawResponseBody,
                    'raw_decrypted' => $rawDecrypted,
                    'unpadded_decrypted' => $unpadded,
                ];
            }

            if ($strict && !$anyFound) {
                $payload = ['success' => false, 'error' => 'No tracking events found for provided tracking numbers', 'data' => $decrypted];
                if ($debugInfo !== null) $payload['debug'] = $debugInfo;
                return response()->json($payload);
            }

            $payload = ['success' => true, 'anyFound' => $anyFound, 'data' => $decrypted];
            if ($debugInfo !== null) $payload['debug'] = $debugInfo;
            return response()->json($payload);
        }
        return response()->json(['success' => false, 'error' => $result['error'] ?? 'Unknown error']);
    }
    
    // CSP public endpoint (no encryption) passthrough for tracking
    public function trackCsp(Request $request) {
        $body = $request->all();
        // Ensure mailNoList exists
        if (!isset($body['mailNoList']) && isset($body['mailNo'])) {
            $body['mailNoList'] = is_array($body['mailNo']) ? $body['mailNo'] : [$body['mailNo']];
        }
        if (!isset($body['mailNoList']) || !is_array($body['mailNoList']) || count($body['mailNoList'])===0) {
            return response()->json(['success' => false, 'error' => 'No mailNoList provided']);
        }

        $url = 'https://csp.speedaf.com/v1/api/express/track/getExpressTrack';
        $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $body);
        return response()->json($response->json());
    }
    // TODO: Add more methods for update, cancel, city/province, tariff, waybill, etc.

    /**
     * Import orders from .xlsx file, ignoring the first row
     */
    public function importOrders(Request $request)
    {
        
        try {
            $file = $request->file('file');
            return response()->json([
                'success' => true, 
                'message' => 'File received',
                'file_info' => [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]
            ]);
            // Create a custom import class that skips the first row
            $import = new class implements \Maatwebsite\Excel\Concerns\ToCollection, \Maatwebsite\Excel\Concerns\WithStartRow {
                public function collection(\Illuminate\Support\Collection $rows)
                {
                    $data = [];
                    foreach ($rows as $row) {
                        // Process each row according to the 16-column structure
                        $data[] = [
                            'ville_destinataire' => $row[0] ?? '',
                            'statut' => $row[1] ?? '',
                            'waybill' => $row[2] ?? '',
                            'ordre_de_client' => $row[3] ?? '',
                            'fret' => $row[4] ?? '',
                            'client' => $row[5] ?? '',
                            'type_express' => $row[6] ?? '',
                            'montant_total' => $row[7] ?? '',
                            'telephone_destinataire' => $row[8] ?? '',
                            'temps_ramassage' => $row[9] ?? '',
                            'date_collection' => $row[10] ?? '',
                            'autoriser_ouverture' => $row[11] ?? '',
                            'adresse_destinataire' => $row[12] ?? '',
                            'statut_facturation' => $row[13] ?? '',
                            'retourne' => $row[14] ?? '',
                            'remarque' => $row[15] ?? '',
                        ];
                        
                    }
                    return $data;
                }

                public function startRow(): int
                {
                    return 2; // Start from row 2, skipping the header row
                }
            };

            Excel::import($import, $file);
            
            // Get the processed data
            $importedData = Excel::toCollection($import, $file)->first();
            return $importedData;
            foreach ($importedData as $item) {
                $order=Order::where("code",$item[3])->first();
                if(!$order) continue;
                $id = 64;
                switch ($item[1]) {
                    case 'Livré':
                        $id = 25;
                        break;
                    case 'Annuler':
                        $id = 33;
                        break;
                    default:
                        $id = 64;
                        break;
                }

                $orderData = [
                    [
                        "id" => $order->id,
                        'shipping_code' => $item[2],
                        "comment" => [
                            "id" => $id,
                            "title" => $item[1]
                        ]
                    ]
                ];
                OrderController::update(new Request($orderData));
            }
            return response()->json([
                'success' => true, 
                'message' => 'File imported successfully',
                'data' => $importedData,
                'count' => $importedData->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Import failed: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Export orders as .xlsx file with columns:
     * customer name, last phone, last address, last address city title, order code, order products
     * Example columns: Nom, Téléphone, Adresse complète, Zone, Code commande, Produits
     */
    public function exportPickupOrders($id)
    {
        // You may want to filter orders, here we get all for example
        $orders = \App\Models\Order::with(['customer', 'city'])
            ->orderByDesc('id')
            ->where("pickup_id", $id) // Filter by pickup_id
            ->get();
        
        $data = $orders->map(function ($order) {
            return [
                'Waybill'=>"",
                'Nom' => $order->customer->name,
                'Téléphone' => $order->customer->activePhones->last()->title,
                'Zone' => $order->customer->activeAddresses->first()->city->title ?? '',
                'Adresse' => $order->customer->activeAddresses->first()->title,
                'S.O' => $order->code,
                'Nom de la marchandise' => implode(" \n ", collect($order->orderPvaTtitle()->map(function ($item) {
                    return $item['product'] . ' ' . implode(' ', $item['attributes']);
                }))->map(fn($item) => $item)->toArray()),
                'Montant total' => $order->calculateActivePvasTotalValue()-$order->discount+$order->shipping_price,
                'Autoriser l\'ouverture du colis ou non' => "Yes",
                'Remarque' => $order->comment
            ];
        });
        // 2-row header: first row is a title, second row is the column names
        $headings = [
            [
                'Waybill', 'Nom', 'Téléphone', 'Zone', 'Adresse', 'S.O', 'Nom de la marchandise', 'Montant total', "Autoriser l'ouverture du colis ou non", 'Remarque'
            ],
            [
                'Waybill', 'Nom', 'Téléphone', 'Zone', 'Adresse', 'S.O', 'Nom de la marchandise', 'Montant total', "Autoriser l'ouverture du colis ou non", 'Remarque'
            ]
        ];

        $export = new class($data, $headings) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithEvents {
            protected $data;
            protected $headings;
            public function __construct($data, $headings) {
                $this->data = $data;
                $this->headings = $headings;
            }
            public function collection() {
                return $this->data;
            }
            public function headings(): array {
                // Only return the second row for headings, the first row will be added by event
                return $this->headings[1];
            }
            public function registerEvents(): array {
                return [
                    \Maatwebsite\Excel\Events\BeforeSheet::class => function(\Maatwebsite\Excel\Events\BeforeSheet $event) {
                        // Insert the first row as a custom header
                        $event->sheet->insertNewRowBefore(1, 1);
                        $event->sheet->getDelegate()->fromArray([$this->headings[0]], null, 'A1', false, false);
                    },
                ];
            }
        };
        return Excel::download($export, 'orders_export.xlsx');
    }
}
