<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;

class SpeedafwController extends Controller
{
    private $config;
    private $cryptoServices;
    
    public function __construct()
    {
        $this->config = [
            'app_code' => env('SPEEDAF_APPCODE', '880056'),
            'secret_key' => env('SPEEDAF_SECRETKEY', '5oQpOLF7'),
            'customer_code' => env('SPEEDAF_CUSTOMERCODE', ''),
            'base_path' => env('SPEEDAF_BASE_URL', 'https://apis.speedaf.com/'),
            'vip_path' => env('SPEEDAF_VIP_URL', 'https://csp.speedaf.com/'),
        ];
    }

    // Utility: Get current timestamp in milliseconds
    private function getCurrentTimestamp()
    {
        list($msec, $sec) = explode(' ', microtime());
        return ceil((floatval($msec) + floatval($sec)) * 1000);
    }

    // Utility: DES CBC encryption
    private function desEncrypt($data, $key)
    {
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        $padded = $this->pkcs5Pad($data, 8);
        $encrypted = openssl_encrypt($padded, 'DES-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    private function desDecrypt($data, $key)
    {
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        $decrypted = openssl_decrypt(base64_decode($data), 'DES-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $this->pkcs5Unpad($decrypted);
    }

    private function pkcs5Pad($text, $blocksize)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }

    private function pkcs5Unpad($text)
    {
        $len = strlen($text);
        if ($len === 0) return '';
        $pad = ord($text[$len - 1]);
        if ($pad <= 0 || $pad > 8) return false;
        $padStr = str_repeat(chr($pad), $pad);
        if (substr($text, -$pad) !== $padStr) return false;
        return substr($text, 0, $len - $pad);
    }

    // Generate sign for API authentication
    private function generateSign($timestamp, $secretKey, $data)
    {
        return md5($timestamp . $secretKey . $data);
    }

    // Build encrypted request body
    private function buildRequestBody($dataArr)
    {
        $timestamp = (string)$this->getCurrentTimestamp();
        $appCode = $this->config['app_code'];
        $secretKey = $this->config['secret_key'];
        
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

    // Decrypt API response
    private function decryptResponse($data)
    {
        $secretKey = $this->config['secret_key'];
        $iv = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";
        
        try {
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

            // Log decryption failure for debugging
            Log::error('Speedaf decryption failed', [
                'encrypted_data' => $data,
                'raw_decrypted' => $raw,
                'unpadded' => $unpadded
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Speedaf decryption exception', [
                'error' => $e->getMessage(),
                'encrypted_data' => $data
            ]);
            return null;
        }
    }

    /**
     * Create single order on Speedaf platform
     */
    public function createOrder(Request $request)
    {
        try {
            $orderData = $this->validateOrderData($request->all());
            
            $payload = array_merge($orderData, [
                'customerCode' => $this->config['customer_code'],
                'platformSource' => 'laravel-woocommerce',
                'parcelType' => 'PT01',
                'deliveryType' => 'DE01',
                'payMethod' => 'PA02',
                'pickUpAging' => 1,
                'piece' => 1,
            ]);

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/express/order/createOrder?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $rawResponseBody = $response->body();
            $result = $response->json();
            
            // Log the full request and response for debugging
            Log::debug('Speedaf createOrder request/response', [
                'request_payload' => $payload,
                'url' => $url,
                'raw_response_body' => $rawResponseBody,
                'response_status' => $response->status(),
                'parsed_result' => $result
            ]);
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                
                if ($decrypted === null) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Failed to decrypt response data',
                        'message' => 'Decryption failed',
                        'raw_response' => $rawResponseBody
                    ], 500);
                }
                
                // Log successful order creation
                Log::info('Speedaf order created successfully', [
                    'order_data' => $payload,
                    'response' => $decrypted
                ]);
                
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted,
                    'message' => 'Order created successfully'
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? $result ?? 'Unknown error',
                'message' => 'Failed to create order',
                'raw_response' => $rawResponseBody
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('Speedaf order creation failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Order creation failed'
            ], 500);
        }
    }

    /**
     * Create multiple orders in batch
     */
    public function batchCreateOrders(Request $request)
    {
        try {
            $orders = $request->input('orders', []);
            $processedOrders = [];
            
            foreach ($orders as $orderData) {
                $validatedOrder = $this->validateOrderData($orderData);
                $processedOrders[] = array_merge($validatedOrder, [
                    'customerCode' => $this->config['customer_code'],
                    'platformSource' => 'laravel-woocommerce',
                    'parcelType' => 'PT01',
                    'deliveryType' => 'DE01',
                    'payMethod' => 'PA02',
                    'pickUpAging' => 1,
                    'piece' => 1,
                ]);
            }

            $payload = ['orders' => $processedOrders];
            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/express/order/batchCreateOrders?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted,
                    'message' => 'Batch orders created successfully'
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Track order by waybill number
     */
    public function trackOrder(Request $request)
    {
        try {
            $trackingNumbers = $this->extractTrackingNumbers($request);
            
            if (empty($trackingNumbers)) {
                return response()->json([
                    'success' => false, 
                    'error' => 'No tracking numbers provided'
                ], 400);
            }

            $payload = [
                'trackingNoList' => $trackingNumbers,
                'customerCode' => $this->config['customer_code'],
                'platformSource' => 'laravel-woocommerce'
            ];

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/express/track/query?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->send('POST', $url, ['body' => $req['body']]);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancelOrder(Request $request)
    {
        try {
            $request->validate([
                'billCode' => 'required|string',
                'reason' => 'sometimes|string'
            ]);

            $payload = [
                'billCode' => $request->input('billCode'),
                'reason' => $request->input('reason', 'Order cancelled by customer'),
                'customerCode' => $this->config['customer_code'],
                'platformSource' => 'laravel-woocommerce'
            ];

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/express/order/cancelOrder?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted,
                    'message' => 'Order cancelled successfully'
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sorting code by waybill number
     */
    public function getSortingCodeByWaybill(Request $request)
    {
        try {
            $request->validate([
                'billCode' => 'required|string'
            ]);

            $payload = [
                'billCode' => $request->input('billCode'),
                'customerCode' => $this->config['customer_code']
            ];

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/network/threeSectionsCode/getByBillCode?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sorting code by address
     */
    public function getSortingCodeByAddress(Request $request)
    {
        try {
            $request->validate([
                'acceptCountryCode' => 'required|string',
                'acceptProvinceName' => 'required|string',
                'acceptCityName' => 'required|string'
            ]);

            $payload = [
                'acceptCountryCode' => $request->input('acceptCountryCode'),
                'acceptProvinceName' => $request->input('acceptProvinceName'),
                'acceptCityName' => $request->input('acceptCityName'),
                'acceptDistrictName' => $request->input('acceptDistrictName', ''),
                'customerCode' => $this->config['customer_code']
            ];

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/network/threeSectionsCode/getByAddress?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Print waybill/label
     */
    public function printLabel(Request $request)
    {
        try {
            $request->validate([
                'billCodes' => 'required|array',
                'labelType' => 'sometimes|integer|in:1,2,3,5'
            ]);

            $payload = [
                'billCodes' => $request->input('billCodes'),
                'labelType' => $request->input('labelType', 3), // Default: Double sheet with logo
                'customerCode' => $this->config['customer_code']
            ];

            $req = $this->buildRequestBody($payload);
            $url = $this->config['base_path'] . 'open-api/express/order/print?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $result = $response->json();
            
            if (isset($result['data'])) {
                $decrypted = $this->decryptResponse($result['data']);
                return response()->json([
                    'success' => true, 
                    'data' => $decrypted
                ]);
            }
            
            return response()->json([
                'success' => false, 
                'error' => $result['error'] ?? 'Unknown error'
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import orders from Excel and create them on Speedaf
     */
    public function importAndCreateOrders(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:2048'
        ]);

        try {
            $file = $request->file('file');
            $importedOrders = [];
            $createdOrders = [];
            $errors = [];

            // Import Excel data
            $import = new class implements \Maatwebsite\Excel\Concerns\ToCollection, \Maatwebsite\Excel\Concerns\WithStartRow {
                public function collection(\Illuminate\Support\Collection $rows)
                {
                    return $rows;
                }

                public function startRow(): int
                {
                    return 2; // Skip header row
                }
            };

            $rows = Excel::toCollection($import, $file)->first();

            foreach ($rows as $index => $row) {
                try {
                    // Map Excel columns to Speedaf order format
                    $orderData = [
                        'acceptName' => $row[1] ?? '', // Nom
                        'acceptMobile' => $row[2] ?? '', // Téléphone  
                        'acceptAddress' => $row[4] ?? '', // Adresse
                        'acceptCityName' => $row[3] ?? '', // Zone
                        'acceptProvinceName' => $row[3] ?? '', // Zone
                        'acceptCountryCode' => 'MA', // Default Morocco
                        'customOrderNo' => $row[5] ?? '', // S.O/Code
                        'goodsName' => $row[6] ?? '', // Marchandise
                        'parcelValue' => floatval($row[7] ?? 0), // Montant
                        'remark' => $row[9] ?? '', // Remarque
                        'parcelWeight' => 0.5, // Default weight
                        'goodsQTY' => 1, // Default quantity
                    ];

                    $validatedOrder = $this->validateOrderData($orderData);
                    $importedOrders[] = $validatedOrder;

                    // Create order on Speedaf
                    $speedafOrder = $this->createSpeedafOrder($validatedOrder);
                    $createdOrders[] = $speedafOrder;

                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $index + 2,
                        'error' => $e->getMessage(),
                        'data' => $row->toArray()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Import completed',
                'imported_count' => count($importedOrders),
                'created_count' => count($createdOrders),
                'error_count' => count($errors),
                'created_orders' => $createdOrders,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper Methods
    private function validateOrderData($data)
    {
        $required = [
            'acceptName' => $data['acceptName'] ?? '',
            'acceptMobile' => $data['acceptMobile'] ?? '',
            'acceptAddress' => $data['acceptAddress'] ?? '',
            'acceptCountryCode' => $data['acceptCountryCode'] ?? 'MA',
            'acceptProvinceName' => $data['acceptProvinceName'] ?? '',
            'acceptCityName' => $data['acceptCityName'] ?? '',
            'sendName' => $data['sendName'] ?? env('SPEEDAF_SENDER_NAME', 'Default Sender'),
            'sendAddress' => $data['sendAddress'] ?? env('SPEEDAF_SENDER_ADDRESS', 'Default Address'),
            'sendMobile' => $data['sendMobile'] ?? env('SPEEDAF_SENDER_PHONE', '0000000000'),
            'sendCityName' => $data['sendCityName'] ?? env('SPEEDAF_SENDER_CITY', 'Casablanca'),
            'parcelWeight' => floatval($data['parcelWeight'] ?? 0.5),
            'goodsQTY' => intval($data['goodsQTY'] ?? 1),
        ];

        $optional = [
            'customOrderNo' => $data['customOrderNo'] ?? '',
            'goodsName' => $data['goodsName'] ?? '',
            'parcelValue' => floatval($data['parcelValue'] ?? 0),
            'remark' => $data['remark'] ?? '',
            'acceptDistrictName' => $data['acceptDistrictName'] ?? '',
            'acceptPostCode' => $data['acceptPostCode'] ?? '',
        ];

        return array_merge($required, $optional);
    }

    private function extractTrackingNumbers($request)
    {
        $possibleKeys = ['trackingNoList', 'tracking_numbers', 'billCodes', 'waybillNumbers'];
        $trackingList = [];

        foreach ($possibleKeys as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                if (is_array($value)) {
                    $trackingList = array_merge($trackingList, $value);
                } elseif (is_string($value)) {
                    $trackingList = array_merge($trackingList, explode(',', $value));
                }
            }
        }

        return array_filter(array_map('trim', $trackingList));
    }

    private function createSpeedafOrder($orderData)
    {
        $payload = array_merge($orderData, [
            'customerCode' => $this->config['customer_code'],
            'platformSource' => 'laravel-woocommerce',
            'parcelType' => 'PT01',
            'deliveryType' => 'DE01',
            'payMethod' => 'PA02',
            'pickUpAging' => 1,
            'piece' => 1,
        ]);

        $req = $this->buildRequestBody($payload);
        $url = $this->config['base_path'] . 'open-api/express/order/createOrder?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
        
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->withBody($req['body'], 'application/json')->post($url);

        $result = $response->json();
        
        if (isset($result['data'])) {
            return $this->decryptResponse($result['data']);
        }
        
        throw new \Exception($result['error'] ?? 'Unknown error creating order');
    }

    /**
     * Debug method to test encryption/decryption
     */
    public function testEncryption(Request $request)
    {
        try {
            $testData = ['test' => 'Hello Speedaf', 'timestamp' => time()];
            $secretKey = $this->config['secret_key'];
            
            // Test encryption
            $dataJson = json_encode($testData, JSON_UNESCAPED_UNICODE);
            $encrypted = $this->desEncrypt($dataJson, $secretKey);
            
            // Test decryption
            $decrypted = $this->desDecrypt($encrypted, $secretKey);
            $decodedData = json_decode($decrypted, true);
            
            return response()->json([
                'success' => true,
                'original' => $testData,
                'encrypted' => $encrypted,
                'decrypted' => $decrypted,
                'decoded' => $decodedData,
                'config' => [
                    'app_code' => $this->config['app_code'],
                    'secret_key_length' => strlen($this->config['secret_key']),
                    'customer_code' => $this->config['customer_code']
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Debug method to test simple API call without encryption
     */
    public function testApiConnection(Request $request)
    {
        try {
            $timestamp = (string)$this->getCurrentTimestamp();
            $appCode = $this->config['app_code'];
            
            // Simple test payload
            $testPayload = [
                'acceptCountryCode' => 'MA',
                'acceptProvinceName' => 'Casablanca-Settat',
                'acceptCityName' => 'Casablanca',
                'customerCode' => $this->config['customer_code']
            ];
            
            $req = $this->buildRequestBody($testPayload);
            $url = $this->config['base_path'] . 'open-api/network/threeSectionsCode/getByAddress?timestamp=' . $req['timestamp'] . '&appCode=' . $req['appCode'];
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withBody($req['body'], 'application/json')->post($url);

            $rawResponseBody = $response->body();
            $result = $response->json();
            
            return response()->json([
                'success' => true,
                'url' => $url,
                'request_body' => $req['body'],
                'response_status' => $response->status(),
                'raw_response' => $rawResponseBody,
                'parsed_response' => $result,
                'config_check' => [
                    'app_code' => $this->config['app_code'],
                    'customer_code' => $this->config['customer_code'],
                    'base_url' => $this->config['base_path']
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}