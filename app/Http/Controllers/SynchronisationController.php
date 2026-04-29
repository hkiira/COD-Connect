<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use App\Models\City;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\Pickup;
use App\Models\Shipment;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Cookie\CookieJar;
use CloudflareBypass\CFCurlImpl;
use CloudflareBypass\Model\UAMOptions;

class SynchronisationController extends Controller
{
    use \Maatwebsite\Excel\Concerns\Exportable;
    public static $url = 'https://api.asapdelivery.ma';

    public function rest(Request $request, $entity, $id = null, $type = null)
    {

        switch ($entity) {
            case 'cities':
                return $this->cities();
            case 'check_cities':
                return $this->checkCities($request);
            case 'import':
                return $this->import($request);
            case 'update_cities':
                return $this->updateCities();
            case 'export':
                return $this->export($id);
            case 'orders':
                return $this->orders($id);
            case 'pickup':
                return $this->pickup($id);
            case 'sync_invoices':
                return $this->syncInvoices();
            case 'sync_orders':
                return $this->syncOrders();
            case 'sync_statuses':
                return $this->syncStatuses();
            case 'sync_returns':
                return $this->syncReturns();
            case 'invoices':
                return $this->invoices();
            case 'invoice_orders':
                return $this->invoiceOrders($id);
            case 'returns':
                return $this->returns();
            case 'return_orders':
                return $this->returnOrders($id);
            case 'create_pickup':
                return $this->createPickup();
            case 'print_pickup':
                return $this->printPickup($id);
            case 'orders':
                return $this->orders($id);
            case 'order_history':
                return $this->historyOrder($id);
            case 'create_order':
                return $this->createOrder($id);
            default:
                return "productsuppliers";
        }
    }
    public function pickup($id)
    {
        $sessionId = $this->login();
        $pickup = Pickup::where('id', $id)->first();
        if($pickup->carrier_id==22){
            foreach ($pickup->orders()->whereNull('shipping_code')->get() as $key => $order) {
                $total = 0;
                $qty = 0;
                $order->activePvas->map(function ($activePva) use (&$total, &$qty) {
                    $total += $activePva->pivot->quantity * $activePva->pivot->price;
                    $qty++;
                });
                $total=$total -$order->discount;
                $data = [
                    'nonce' => '86396d6332ae8331c3cebecb40c538db',
                    'phase' => 'shipping',
                    'state' => '1',
                    'id' => '0',
                    'client' => '5986',
                    'worker' => '',
                    'fullname' => $order->customer->name,
                    'phone' => $order->customer->phones->first()->title,
                    'code' => '',
                    'code2' => $order->code,
                    'city' => $order->customer->addresses->first()->city->title,
                    'address' => $order->customer->addresses->first()->title,
                    'fromstock' => '0',
                    'product' => implode("\n", $order->activePvas->map(function ($activePva) {
                        $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                            return $childVa->attribute->title;
                        });
                        return $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
                    })->toArray()),
                    'qty1' => $qty,
                    'price' => $total,
                    'note' => '',
                    'change' => '0',
                    'openpackage' => '1',
                    'express' => '0',
                    'action' => 'addramassage',
                ];
                    $order->update(['meta' => 1]);
                    $this->createOrder($data, $sessionId);
                    $asapOrder= $this->getOrder($order->code,$sessionId);
                    if ($asapOrder) {
                        $order->update(['meta' => $asapOrder[0]['id'], 'shipping_code' => $asapOrder[0]['asap_code']]);
                    }
            }
            return [
                'success' => true,
                'message' => 'Synchronisation effectuée avec succès.'
            ];
        }
    
        return [
            'success' => false,
            'message' => 'carrier non supporté pour la synchronisation des ramassages. Seul ASAP est supporté pour le moment.'
        ];

    }
    public function export($id)
    {
        $pickup = Pickup::where('id', $id)->first();
        if($pickup->carrier_id==24){
            $xlsx = new SpeedafController();
           return $xlsx->exportPickupOrders($id);
        }elseif($pickup->carrier_id==22){
            $xlsx = new AsapDeliveryController();
           return $xlsx->exportPickupOrders($id);
        }elseif($pickup->carrier_id==26){
            $xlsx = new AfraDeliveryController();
           return $xlsx->exportPickupOrders($id);
        }
    
        return [
            'success' => false,
            'message' => 'carrier non supporté pour la synchronisation des ramassages.'
        ];

    }
    public function import(Request $request){
        try {
            $file = $request->file('file');
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
            foreach ($importedData as $item) {
                $order=Order::where("code",$item[3])->whereNull('shipment_id')->first();
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
    public function returnOrders($returnId)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $data = [
            "url" => "https://app.asapdelivery.ma/exportbls.php?id=" . $returnId,
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $htmlContent = curl_exec($curl);

        $data = [];
        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($htmlContent);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 4) {
                $num = trim($cells->item(0)->textContent);
                $code = trim($cells->item(1)->textContent);
                $phone = trim($cells->item(2)->textContent);
                $city = trim($cells->item(3)->textContent);
                $price = trim($cells->item(4)->textContent);
                // Get action links from the last cell.
                $data[] = [
                    'num' => $num,
                    'code' => $code,
                    'phone' => $phone,
                    'city' => $city,
                    'price' => $price,
                ];
            }
        }
        return $data;
    }
    public function syncOrders(){
        $scrapController=new ScrapController();
        return $scrapController->syncOrders();
    }
    //katjib la commande men systeme dial ASAP b search
    public function getOrder($code, $sessionId)
    {
        $curl = curl_init();
        $body = [
            "state" => "1",
            "keyword" => $code,
            "client" => "",
            "worker" => "",
            "city" => "",
            "ids" => "",
            "st" => "",
            "change" => "",
            "stock" => "",
            "datestart" => "",
            "dateend" => "",
            "datestartupdate" => "",
            "dateendupdate" => "",
            "start" => "0",
            "nbpage" => "10",
            "sortby" => "dateadd",
            "orderby" => "ASC",
            "action" => "loadramassages"
        ];
        $body = http_build_query($body);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);
        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($uploadResponse);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');
        $data = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 12) {
                // Get invoice ID from the checkbox input's value.
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Extract the text content from each cell.
                $employee      = trim($cells->item(1)->textContent);
                $created          = trim($cells->item(2)->textContent);
                $receiver      = trim($cells->item(3)->textContent);
                $phone       = trim($cells->item(4)->textContent);
                $address        = trim($cells->item(5)->textContent);
                $city        = trim($cells->item(6)->textContent);
                $price  = trim($cells->item(7)->textContent);
                // Get action links from the last cell.
                $state  = trim($cells->item(8)->textContent);
                $note  = trim($cells->item(9)->textContent);
                $change  = trim($cells->item(10)->textContent);
                $asapCode  = trim($cells->item(11)->textContent);
                $spaceCode  = trim($cells->item(12)->textContent);
                $product  = trim($cells->item(13)->textContent);
                $stock  = trim($cells->item(14)->textContent);
                $data[] = [
                    'id'             => $id,
                    'employee'       => $employee,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'address'         => $address,
                    'city'         => $city,
                    'price'         => $price,
                    'state'         => $state,
                    'note'          => $note,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
                    'product'  => $product,
                    'stock'  => $stock,
                ];
            }
        }
        return $data;
    }
    //crée une commande dans le systéme de ASAP
    public function createOrder($dataOrder, $sessionId)
    {

        $sessionId = $sessionId ?? $this->login();
        // 2️⃣ Order submission
        $curl = curl_init();
        $body = http_build_query($dataOrder);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);
        if (trim($uploadResponse) === '') {
            return [
                'success' => true,
                'message' => 'Commande synchronisé avec succès.'
            ];
        } else {
            return [
                'success' => false,
                'message' => $uploadResponse
            ];
        }
    }
    // Récupérer l'historique d'une commande par Order Asap Id
    public function historyOrder($orderId)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $body = [
            'nonce' => '86396d6332ae8331c3cebecb40c538db',
            "id" => $orderId, //hna les ids dial les colis en attente
            'action' => 'showcolihistory',
        ];
        $body = http_build_query($body);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);
        return $uploadResponse;
    }
    //hadi makhedamach 7ta nchof blanha
    public function createPickup()
    {
        $client = new Client([
            'base_uri' => 'https://app.asapdelivery.ma',
            'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36']
        ]);

        // Créer un cookie jar pour stocker la session
        $jar = new CookieJar();

        // 1️⃣ Se connecter
        $loginResponse = $client->post('/login.php', [
            'form_params' => [
                'username' => 'styemen.ma@gmail.com',  // Vérifie que c'est bien "username" and not "email"
                'password' => 'demarrer',
            ],
            'cookies' => $jar,  // Stocker les cookies
        ]);

        // Récupérer les cookies de session
        $cookies = $jar->toArray();
        $sessionId = null;
        foreach ($cookies as $cookie) {
            if ($cookie['Name'] === 'PHPSESSID') {
                $sessionId = $cookie['Value'];
                break;
            }
        }

        if (!$sessionId) {
            return 'Erreur : Impossible de récupérer PHPSESSID';
        }

        // 2 Envoyer les données du formulaire
        $uploadResponse = $client->post('/inc/ramassage.php', [
            'headers' => [
                'Cookie' => "PHPSESSID=$sessionId",  // Garder la session active
                'Referer' => 'https://app.asapdelivery.ma/ramassage.php',
                'Origin' => 'https://app.asapdelivery.ma',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'form_params' => [
                'nonce' => '86396d6332ae8331c3cebecb40c538db',
                "ids" => [127343], //hna les ids dial les colis en attente
                'client' => '5986',
                'action' => 'createbr1',
            ],
        ]);
        return $uploadResponse->getBody()->getContents();
    }
    public function printPickup($id)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $data = [
            "url" => "https://app.asapdelivery.ma/printtickets.php?id=" . $id . "&model=3",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);

        return $uploadResponse;
    }

    public function returns()
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $body = [
            "state" => "1",
            "type" => "BRC",
            "keyword" => "",
            "client" => "",
            "worker" => "",
            "dlm" => "",
            "received" => "",
            "datestart" => "",
            "dateend" => "",
            "stock" => "0",
            "start" => "0",
            "nbpage" => "100",
            "sortby" => "",
            "orderby" => "DESC",
            "action" => "loadbls"
        ];
        $body = http_build_query($body);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/bls.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);

        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($uploadResponse);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');

        $data = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 7) {
                // Get invoice ID from the checkbox input's value.
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Extract the text content from each cell.
                $employee      = trim($cells->item(1)->textContent);
                $code          = trim($cells->item(2)->textContent);
                $nb_colis      = trim($cells->item(3)->textContent);
                $note       = trim($cells->item(4)->textContent);
                $status        = trim($cells->item(5)->textContent);
                $dateCreation  = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $actionCell  = $cells->item(7);
                $links       = $actionCell->getElementsByTagName('a');
                $printLink   = $links->length > 0 ? $links->item(0)->getAttribute('href') : null;
                $exportLink  = "exportbls.php?id=" . $id . "&type=BRC&code=" . $code;
                $data[] = [
                    'id'             => $id,
                    'employee'       => $employee,
                    'code'           => $code,
                    'nb_colis'       => $nb_colis,
                    'note'        => $note,
                    'status'         => $status,
                    'date_creation'  => $dateCreation,
                    'print_link'     => $printLink,
                    'export_link'    => $exportLink,
                ];
            }
        }
        return $data;
    }

    public function instanceOrders($statusId)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $body = [
            "state" => "1",
            "keyword" => "",
            "client" => "",
            "worker" => "",
            "city" => "",
            "ids" => "",
            "st" => "",
            "status" => "" . $statusId,
            "change" => "",
            "stock" => "",
            "start" => "0",
            "nbpage" => "20",
            "sortby" => "",
            "orderby" => "DESC",
            "action" => "loadramassages"
        ];
        $body = http_build_query($body);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);

        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($uploadResponse);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');

        $data = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 12) {
                // Get invoice ID from the checkbox input's value.
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Extract the text content from each cell.
                $employee      = trim($cells->item(1)->textContent);
                $created          = trim($cells->item(2)->textContent);
                $receiver      = trim($cells->item(3)->textContent);
                $phone       = trim($cells->item(4)->textContent);
                $city        = trim($cells->item(5)->textContent);
                $price  = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $state  = trim($cells->item(7)->textContent);
                $change  = trim($cells->item(8)->textContent);
                $asapCode  = trim($cells->item(9)->textContent);
                $spaceCode  = trim($cells->item(10)->textContent);
                $product  = trim($cells->item(11)->textContent);
                $stock  = trim($cells->item(12)->textContent);
                $data[] = [
                    'id'             => $id,
                    'employee'       => $employee,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'city'         => $city,
                    'price'         => $price,
                    'state'         => $state,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
                    'product'  => $product,
                    'stock'  => $stock,
                ];
            }
        }
        return $data;
    }
    public function getLastStatuses($keyword, $sessionId)
    {
        $curl = curl_init();
        $body = [
            "state" => "1",
            "keyword" => $keyword,
            "client" => "",
            "worker" => "",
            "city" => "",
            "ids" => "",
            "st" => "",
            "change" => "",
            "stock" => "",
            "datestart" => "",
            "dateend" => "",
            "datestartupdate" => "",
            "dateendupdate" => "",
            "start" => "0",
            "nbpage" => "10",
            "sortby" => "dateadd",
            "orderby" => "ASC",
            "action" => "loadcolis"
        ];
        $body = http_build_query($body);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);

        if (empty($uploadResponse)) {
            return [];
        }

        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($uploadResponse);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');
        $data = [];
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 12) {
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Get invoice ID from the checkbox input's value.
                $created          = trim($cells->item(2)->textContent);
                $receiver      = utf8_decode(trim($cells->item(3)->textContent));
                $phone       = trim($cells->item(4)->textContent);
                $address        = trim($cells->item(5)->textContent);
                $city        = trim($cells->item(6)->textContent);
                $price  = trim($cells->item(7)->textContent);
                // Get action links from the last cell.
                $state  = trim($cells->item(8)->textContent);
                $note  = trim($cells->item(9)->textContent);
                $change  = trim($cells->item(11)->textContent);
                $asapCode  = trim($cells->item(12)->textContent);
                $spaceCode  = trim($cells->item(13)->textContent);
                $product  = trim($cells->item(14)->textContent);
                $stock  = trim($cells->item(15)->textContent);
                $data[] = [
                    'id'             => $id,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'address'      => $address,
                    'city'         => $city,
                    'price'         => $price,
                    'state'         => $state,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
                    'product'  => $product,
                    'stock'  => $stock,
                ];
            }
        }
        return $data;
    }
    function getHeadersOnly($response)
    {
        // Use the header size to split headers from body
        $headerSize = strpos($response, "\r\n\r\n");

        if ($headerSize !== false) {
            $headerText = substr($response, 0, $headerSize);
        } else {
            $headerText = $response;
        }

        // Split into lines and convert to associative array
        $headers = [];
        $lines = explode("\n", $headerText);
        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'HTTP/') === 0) {
                $headers['http_status'] = $line;
            } elseif (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }
    public function login()
    {
        $curl = curl_init();
        $body = [
            'username' => 'styemen.ma@gmail.com',
            'password' => 'azerty',
        ];
        $body = http_build_query($body);
        $data = [
            "url" => "https://app.asapdelivery.ma/login.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "disableRedirection" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
        ));
        curl_setopt($curl, CURLOPT_HEADER, true); // Get headers
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);      // Include headers in output
        $response = curl_exec($curl);
        curl_close($curl);
        $header = $this->getHeadersOnly($response);
        return $header['scrape.do-cookies'];
    }
    public function orders($id)
    {
        return [
            "success" => true,
            "message" => "Synchronisation éffectuée."
        ];
    }

    public function invoices()
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $body = [
            "state" => "1",
            "type" => "FC",
            "keyword" => "",
            "start" => "0",
            "nbpage" => "20",
            "orderby" => "DESC",
            "action" => "loadfactures"
        ];
        $body = http_build_query($body);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/factures.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $uploadResponse = curl_exec($curl);


        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($uploadResponse);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');

        $data = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 11) {
                // Get invoice ID from the checkbox input's value.
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;

                // Extract the text content from each cell.
                $employee      = trim($cells->item(1)->textContent);
                $code          = trim($cells->item(2)->textContent);
                $nb_colis      = trim($cells->item(3)->textContent);
                $montant       = trim($cells->item(4)->textContent);
                $mas_ch        = trim($cells->item(5)->textContent);
                $note          = trim($cells->item(6)->textContent);
                $dateCreation  = trim($cells->item(7)->textContent);
                $dateVersement = trim($cells->item(8)->textContent);
                $status        = trim($cells->item(9)->textContent);
                // Get action links from the last cell.
                $actionCell  = $cells->item(10);
                $links       = $actionCell->getElementsByTagName('a');
                $printLink   = $links->length > 0 ? $links->item(0)->getAttribute('href') : null;
                $exportLink  = $links->length > 1 ? $links->item(1)->getAttribute('href') : null;
                $data[] = [
                    'id'             => $id,
                    'employee'       => $employee,
                    'code'           => $code,
                    'nb_colis'       => $nb_colis,
                    'montant'        => $montant,
                    'mas_ch'         => $mas_ch,
                    'note'           => $note,
                    'date_creation'  => $dateCreation,
                    'date_versement' => $dateVersement,
                    'status'         => $status,
                    'print_link'     => $printLink,
                    'export_link'    => $exportLink,
                ];
            }
        }

        // $data now contains an array of all invoice rows.
        return $data;
    }
    public function syncStatuses()
    {
        $pickups = Pickup::where('carrier_id', 22)->pluck('id')->toArray();
        $orders = Order::with('activePhones')->where('account_id', getAccountUser()->account_id)->whereIn('pickup_id', $pickups)->whereNull('shipment_id')->whereIn('order_status_id', [6,9])->orderByDesc('created_at')->get();
        // $orders = Order::where('account_id', getAccountUser()->account_id)->where('shipping_code','Non')->whereIn('pickup_id', $pickups)->whereNull('shipment_id')->orderByDesc('created_at')->get();
        $sessionId = $this->login();
        foreach ($orders as $key => $order) {
            // if($order->updated_at->diffInDays(now()) > 0) {
            $phone = $order->activePhones->first();
            if (!$phone) {
                continue;
            }
            $asapHistory = collect($this->getLastStatuses($phone->title, $sessionId))->first();
            if ($asapHistory) {
                $id = 64;
                switch ($asapHistory['state']) {
                    case 'En attente de ramassage':
                        break;
                    case 'Ramassé':
                        $id = 65;
                        break;
                    case 'Receptionné':
                        $id = 64;
                        break;
                    case 'Receptionné Programé: Lundi, Mardi, Mercredi, Jeudi, Vendredi, Samedi, Dimanche':
                        $id = 64;
                        break;
                    case 'Expédié':
                        $id = 29;
                        break;
                    case 'Reçu par livreur':
                        $id = 64;
                        break;
                    case 'Faux destination':
                        $id = 62;
                        break;
                    case 'Hors zone':
                        $id = 62;
                        break;
                    case 'Hors zone 1':
                        $id = 62;
                        break;
                    case 'En distribution':
                        $id = 64;
                        break;
                    case 'Injoignable':
                        $id = 31;
                        break;
                    case 'Injoignable Programé: Lundi, Mardi, Mercredi, Jeudi, Vendredi, Samedi':
                        $id = 31;
                        break;
                    case 'Pas de réponse':
                        $id = 42;
                        break;
                    case 'Annulé':
                        $id = 33;
                        break;
                    case 'Annulé 1':
                        $id = 33;
                        break;
                    case 'Refusé':
                        $id = 34;
                        break;
                    case 'Refusé 1':
                        $id = 34;
                        break;
                    case 'Changement client':
                        $id = 64;
                        break;
                    case 'Demande de retour':
                        $id = 33;
                        break;
                    case 'Reporté':
                        $id = 28;
                        break;
                    case 'Livré':
                        $id = 25;
                        break;
                    case 'Livré (Changé)':
                        $id = 25;
                        break;
                    case 'Livré (Payé)':
                        $id = 25;
                        break;
                    case 'Retour vers agence casa':
                        $id = 33;
                        break;
                    case 'Retour reçu agence casa':
                        $id = 33;
                        break;
                    case 'Retour client expédié':
                        $id = 33;
                        break;
                    case 'Retour client reçu':
                        $id = 33;
                        break;
                    case 'Interessé':
                        $id = 64;
                        break;
                    case 'Demande de suivi':
                        $id = 64;
                        break;
                    case 'En attente de retour':
                        $id = 33;
                        break;
                    case 'Change':
                        $id = 64;
                        break;
                    case 'Programmé':
                        $id = 64;
                        break;
                    case 'A retourner vers agence principal casa':
                        $id = 33;
                        break;
                    case 'en voyage':
                        $id = 35;
                        break;
                    case 'Pas de réponse 1':
                        $id = 31;
                        break;
                    case 'pas de réponse 2 fois':
                        $id = 31;
                        break;
                    case 'pas de réponse 3 fois':
                        $id = 31;
                        break;
                    case 'pas de réponse 4 fois':
                        $id = 31;
                        break;
                    case 'Pas de réponse LV':
                        $id = 31;
                        break;
                    case 'Pas de réponse 5 fois':
                        $id = 31;
                        break;
                    case 'Pas de réponse ( suivi )':
                        $id = 31;
                        break;
                    case 'Annuler ( suivi ) 1':
                        $id = 33;
                        break;
                    case 'Annuler ( suivi )' :
                        $id = 33;
                        break;
                    case 'Reporté ( suivi )':
                        $id = 28;
                        break;
                    case 'Changement numéro':
                        $id = 64;
                        break;
                    case 'En attente d\'appel du client':
                        $id = 64;
                        break;
                    case 'Numéro Incorrect':
                        $id = 64;
                        break;
                    case 'Injoignable ( suivi )':
                        $id = 31;
                        break;
                    case 'Injoignable 1':
                        $id = 31;
                        break;
                    case 'Double Commande':
                        $id = 58;
                        break;
                    default:
                        $id = 28;
                        break;
                }

                if (str_contains($asapHistory['state'], "Reporté")) {
                    $id = 28;
                }
                if (str_contains($asapHistory['state'], "Programmé")) {
                    $id = 64;
                }
                if (str_contains($asapHistory['state'], "pas de réponse")) {
                    $id = 31;
                }
                if (str_contains($asapHistory['state'], "Mise en distribution")) {
                    $id = 64;
                }
                if ($id==0) {
                    return [
                        "statut" => 0,
                        "data" => "Nouveau statut non défini : ".$asapHistory['state'] . " pour la commande " . $order->code
                    ];
                }
                $orderData = [
                    [
                        "id" => $order->id,
                        'meta' => $asapHistory['id']? $asapHistory['id']:$order->meta, 
                        'shipping_code' => $order->shipping_code?$order->shipping_code:$asapHistory['asap_code'],
                        "comment" => [
                            "id" => $id,
                            "title" => $asapHistory['state']
                        ]
                    ]
                ];
                OrderController::update(new Request($orderData));
            }else{
                // $orderData = [
                //     [
                //         "id" => $order->id,
                //         'meta' => null, 
                //         'shipping_code' => $asapHistory['asap_code'],
                //         "comment" => [
                //             "id" => 64,
                //             "title" => 'Non traité'
                //         ]
                //     ]
                // ];
                // OrderController::update(new Request($orderData));
            }
            // }
        }
        return [
            "statut" => 1,
            "data" => "Commandes synchronisés avec succès."
        ];
        //2,3,4,5,6,9,10,11,14,16,25,26,30,39,40,41,42,58,59 en cours
        //17 livré
        //7,8,12,13,15,18,19,20,21,22,23,24,27,28,29,31 annulées
    }
    public function invoiceOrders($invoiceId)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $data = [
            "url" => "https://app.asapdelivery.ma/exportfactures.php?id=" . $invoiceId,
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        $htmlContent = curl_exec($curl);

        $data = [];
        // Create a new DOMDocument and load the HTML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($htmlContent);
        libxml_clear_errors();

        // Use DOMXPath to query the document.
        $xpath = new \DOMXPath($dom);

        // Get all rows except the header row.
        $rows = $xpath->query('//table/tr[not(contains(@class, "lx-first-tr"))]');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            // Ensure the row has the expected number of cells.
            if ($cells->length >= 7) {
                $num = trim($cells->item(0)->textContent);
                $code = trim($cells->item(1)->textContent);
                $phone = trim($cells->item(2)->textContent);
                $city = trim($cells->item(3)->textContent);
                $status = trim($cells->item(4)->textContent);
                $price = trim($cells->item(5)->textContent);
                $shipping  = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $data[] = [
                    'num'             => $num,
                    'code'       => $code,
                    'phone'           => $phone,
                    'city'       => $city,
                    'status'         => $status,
                    'price'  => $price,
                    'shipping'     => $shipping,
                ];
            }
        }
        return $data;
    }
    public function syncInvoices()
    {
        $scrapedAsap= new ScrapController();
        return $scrapedAsap->syncInvoices();
    }
    public function syncReturns()
    {
        $datas = $this->returns();
        foreach ($datas as $key => $data) {
            $hasInvoice = Shipment::where('title', $data['code'])->first();
            if (!$hasInvoice) {
                $getAsapOrders = $this->returnOrders($data['id']);
                $orders = [];
                foreach ($getAsapOrders as $key => $asapOrder) {
                    $order = null;
                    if ($asapOrder['code'])
                        $order = Order::where('shipping_code', $asapOrder['code'])->first();
                    if ($order)
                        $orders[] = ['id' => $order->id, 'carrier_price' => 0];
                }
                $requestData = new Request([['carrier_id' => 22, 'shipment_type_id' => 2, 'warehouse_id' => 30, 'statut' => 1, 'title' => $data['code'], 'orders' => $orders]]);
                ShipmentController::store($requestData);
            }
        }
        
        return [
            "statut" => 1,
            "data" => "Retours synchronisés avec succès."
        ];
    }


    public function cities()
    {
        $response = Http::get(self::$url . "/cities.php");
        $cities = $response->json();
        return [
            "statut" => 1,
            "data" => $cities
        ];
    }

    public function updateCities()
    {
        $response = Http::get(self::$url . "/cities.php");
        $decoded = $response->json();
        if ($decoded) {
            $cities = collect($decoded)->map(function ($defaultCity) {
                $city = DefaultCarrier::where('city_id_carrier', $defaultCity['ID'])->where('carrier_id', 22)->first();
                if ($city) {
                    $city->update([
                        'statut' => 1,
                        'name' => $defaultCity['City'],
                    ]);
                    return $city->city_id;
                } else {
                    $city = DefaultCarrier::where('name', $defaultCity['City'])->where('carrier_id', 22)->first();
                    if ($city) {
                        $city->update([
                            'statut' => 1,
                            'city_id_carrier' => $defaultCity['ID'],
                        ]);
                        return $city->city_id;
                    } else {
                        $city = City::where('title', 'like', "%{$defaultCity['City']}%")->first();
                        if ($city) {
                            $defaultCity = DefaultCarrier::create([
                                'carrier_id' => 22,
                                'city_id' => $city->id,
                                'name' => $defaultCity['City'],
                                'city_id_carrier' => $defaultCity['ID'],
                                'price' => $defaultCity['Delivered_Fees'],
                                'return' => $defaultCity['Returned_Fees'],
                                'delivery_time' => 1,
                            ]);
                            return $defaultCity->city_id;
                        } else {
                            $newCity = City::create([
                                'title' => $defaultCity['City'],
                            ]);
                            DefaultCarrier::create([
                                'carrier_id' => 22,
                                'city_id' => $newCity->id,
                                'name' => $defaultCity['City'],
                                'city_id_carrier' => $defaultCity['ID'],
                                'price' => $defaultCity['Delivered_Fees'],
                                'return' => $defaultCity['Returned_Fees'],
                                'delivery_time' => 1,
                            ]);
                            return $defaultCity->city_id;
                        }
                    }
                }
            })->filter()->values()->toArray();
            return [
                "statut" => 1,
                "data" => [$cities]
            ];
        } else {
            return [
                "statut" => 0,
                "data" => "probléme de connexion"
            ];
        }
    }
    public function checkCities(Request $request)
    {
        $validator = Validator::make($request->except('_method'), [
            'orders.*' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $order = Order::where('id', $value)->whereIn('order_status_id', [1, 2, 3, 4])->first();
                    if (!$order) {
                        $fail("Déja envoyée");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };

        $cityUpdated = $this->updateCities();
        if (1 == $cityUpdated['statut']) {
            $orders = collect($request['orders'])->map(function ($orderId) {
                $order = Order::find($orderId);
                $cityExist = $order->city->defaultCarriers()->where(['carrier_id' => 22, 'statut' => 1])->first();
                if (!$cityExist)
                    return $cityExist;
            })->filter()->values()->toArray();
            return [
                "statut" => 1,
                "data" => $orders
            ];
        }
        return [
            "statut" => 0,
            "data" => "probléme data"
        ];
    }
}
