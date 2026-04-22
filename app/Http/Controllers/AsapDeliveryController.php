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

class AsapDeliveryController extends Controller implements FromCollection, WithHeadings
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
            case 'update_cities':
                return $this->updateCities();
            case 'export':
                return $this->exportOrdersToXlsx();
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
            // 'Destinataire',
            // 'Téléphone',
            // 'Ville',
            // 'Adresse',
            // 'Prix',
            // 'Produit Ref',
            // 'Qté',
            // 'ID Intern',
            // 'Change (0/1)',
            // 'Ouvrir Colis (0/1)',
            return [
                'Destinataire' => $order->customer->name."-".$order->code,
                'Téléphone' => $order->customer->activePhones->last()->title,
                'Ville' => $order->customer->activeAddresses->first()->city->title ?? '',
                'Adresse' => $order->customer->activeAddresses->first()->title,
                'Prix' => $order->calculateActivePvasTotalValue()-$order->discount+$order->shipping_price,
                'Produit Ref' => implode(" \n ", collect($order->orderPvaTtitle()->map(function ($item) {
                    return $item['product'] . ' ' . implode(' ', $item['attributes']);
                }))->map(fn($item) => $item)->toArray()),
                'Qté' => $order->activeOrderPvas->sum('quantity'),
                'ID Intern' => $order->code,
                'Change (0/1)' => 0,
                'Ouvrir Colis (0/1)' => 1,
                'commentaire' => $order->comment,
                'date_creation' => $order->created_at->format('Y-m-d H:i:s'),
                
            ];
        });
        // Single row header
        $headings = [
            'Destinataire', 'Téléphone', 'Ville', 'Adresse', 'Prix',  'Produit Ref', 'Qté', "ID Intern", 'Change (0/1)', 'Ouvrir Colis (0/1)', 'commentaire', 'date_creation'
        ];

        $export = new class($data, $headings) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithStyles {
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
                return $this->headings;
            }
            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet) {
                return [
                    1 => [
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '366092']],
                        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
                    ]
                ];
            }
        };
        return Excel::download($export, 'orders_export.xlsx');
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


    public function collection()
    {
        $orders = Order::where('account_id', getAccountUser()->account_id)->limit(10)
            ->get();
        return  $orders->map(function ($order) {
            return [
                'Destinataire' => $order->customer->name,
                'Téléphone' => $order->customer->activePhones->first()->title,
                'Ville' => $order->city->title,
                'Adresse' => $order->customer->activeAddresses->first()->title,
                'Prix' => $order->calculateActivePvasTotalValue(),
                'Produit Ref' => implode(" \n ", collect($order->orderPvaTtitle()->map(function ($item) {
                    return $item['product'] . ' ' . implode(' ', $item['attributes']);
                }))->map(fn($item) => $item)->toArray()),
                'Qté' => $order->calculateActivePvasQte(),
                'ID Intern' => $order->code,
                'Change (0/1)' => 0,
                'Ouvrir Colis (0/1)' => "1",
            ];
        });
    }

    public function pickup($id)
    {
        $pickup = Pickup::where('id', $id)->first();

        foreach ($pickup->orders()->get() as $key => $order) {
            $total = 0;
            $qty = 0;
            $order->activePvas->map(function ($activePva) use (&$total, &$qty) {
                $total += $activePva->pivot->quantity * $activePva->pivot->price;
                $qty++;
            });
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
                'city' => $order->city->title,
                'address' => $order->customer->addresses->first()->title,
                'fromstock' => '0',
                'product' => implode("\n", $order->activePvas->map(function ($activePva) {
                    $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                        return $childVa->attribute->title;
                    });
                    return $activePva->pivot->quantity . " x " . $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
                })->toArray()),
                'qty' => $qty,
                'price' => $total,
                'note' => '',
                'change' => '0',
                'openpackage' => '0',
                'express' => '0',
                'action' => 'addramassage',
            ];
            if ($order->meta) {
                $order->update(['meta' => 1]);
                $this->createOrder($data);
            }
            $asapOrder= $this->getLastStatuses($order->code,$sessionId);
            if ($asapOrder) {
                $order->update(['meta' => $asapOrder[0]['id'], 'shipping_code' => $asapOrder[0]['asap_code']]);
            }
        }
        return $pickup->orders;
    }
    public function syncOrders(){
        $sessionId = $this->login();
        $orders = Order::where('account_id', getAccountUser()->account_id)->whereNull('shipment_id')->whereNotNull('pickup_id')->whereNull('shipping_code')->whereNotIn('order_status_id', [2, 3])->limit(10)->get();
        $updatedCode=0;
        foreach ($orders as $order) {
            $asapOrder= $this->getLastStatuses($order->code,$sessionId);
        return $asapOrder;
            if($asapOrder){
                // Update the order with ASAP order ID and shipping code
                $order->update(['meta' => $asapOrder[0]['id'], 'shipping_code' => $asapOrder[0]['asap_code']]);
                $updatedCode++;
            }
        }
        return $updatedCode;
    }
    //katjib la commande men systeme dial ASAP b search
    public function getOrder($code)
    {
        $curl = curl_init();
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "token" => "8a355170e5de449db59061cef47bb515405addc24cd",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Get invoice ID from the checkbox input's value.
                $created          = trim($cells->item(2)->textContent);
                $receiver      = utf8_decode(trim($cells->item(3)->textContent));
                $phone       = trim($cells->item(4)->textContent);
                $address       = utf8_decode(trim($cells->item(5)->textContent));
                $city        = trim($cells->item(6)->textContent);
                preg_match('/L:\s*(\d+)/', $city, $matches);
                $delivery        = $matches[1] ?? null;
                $price  = trim($cells->item(7)->textContent);
                $status  = utf8_decode(trim($cells->item(8)->textContent));
                $hasInvoice  = trim($cells->item(9)->textContent);
                $change  = trim($cells->item(10)->textContent);
                $asapCode  = trim($cells->item(11)->textContent);
                $spaceCode  = trim($cells->item(12)->textContent);
                $cleaned = preg_replace('/\s+/', ' ', $status);
                $data[] = [
                    'id'             => $id,
                    'created'           => $created,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'delivery'         => $delivery,
                    'price'         => $price,
                    'state'         => $cleaned,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
                ];
            }
        }
        return $data;
    }
    //crée une commande dans le systéme de ASAP
    public function createOrder($data)
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
                'username' => 'achkar.abder@gmail.com',  // Vérifie que c'est bien "username" and not "email"
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
            'form_params' => $data,
        ]);
        return $uploadResponse->getBody()->getContents();
    }
    // Récupérer l'historique d'une commande par Order Asap Id
    public function historyOrder($orderId)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $headers[] = 'Content-Type: application/json';
        $body = [
            'nonce' => '86396d6332ae8331c3cebecb40c538db',
            "id" => $orderId, //hna les ids dial les colis en attente
            'action' => 'showcolihistory',
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
                'username' => 'achkar.abder@gmail.com',  // Vérifie que c'est bien "username" and not "email"
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
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/bls.php",
            "token" => "8a355170e5de449db59061cef47bb515405addc24cd",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
    public function getLastStatuses($code, $sessionId)
    {
        $curl = curl_init();
        $headers[] = 'Content-Type: application/json';
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
            "action" => "loadcolis"
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "token" => "8a355170e5de449db59061cef47bb515405addc24cd",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
                $input = $cells->item(0)->getElementsByTagName('input')->item(0);
                $id = $input ? $input->getAttribute('value') : null;
                // Get invoice ID from the checkbox input's value.
                $created          = trim($cells->item(2)->textContent);
                $receiver      = utf8_decode(trim($cells->item(3)->textContent));
                $phone       = trim($cells->item(4)->textContent);
                $address       = utf8_decode(trim($cells->item(5)->textContent));
                $city        = trim($cells->item(6)->textContent);
                preg_match('/L:\s*(\d+)/', $city, $matches);
                $delivery        = $matches[1] ?? null;
                $price  = trim($cells->item(7)->textContent);
                $status  = utf8_decode(trim($cells->item(8)->textContent));
                $hasInvoice  = trim($cells->item(9)->textContent);
                $change  = trim($cells->item(10)->textContent);
                $asapCode  = trim($cells->item(11)->textContent);
                $spaceCode  = trim($cells->item(12)->textContent);
                $data[] = [
                    'id'             => $id,
                    'created'           => $created,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'delivery'         => $delivery,
                    'price'         => $price,
                    'state'         => $status,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
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
        $headers[] = 'Content-Type: application/json';
        $body = [
            'username' => 'styemen@gmail.com',
            'password' => 'azerty',
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $data = [
            "url" => "https://app.asapdelivery.ma/login.php",
            "token" => "8a355170e5de449db59061cef47bb515405addc24cd",
            "disableRedirection" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
    public function orders($statusValue)
    {

        $sessionId = $this->login();

        $curl = curl_init();
        $headers[] = 'Content-Type: application/json';
        $body = [
            "state" => "1",
            "keyword" => "",
            "client" => "",
            "worker" => "",
            "city" => "",
            "ids" => "",
            "st" => "",
            "statee" => $statusValue,
            "change" => "",
            "stock" => "",
            "start" => "0",
            "nbpage" => "20",
            "sortby" => "",
            "orderby" => "DESC",
            "action" => "loadcolis"
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
                $employee      = trim($cells->item(1)->textContent);
                $created          = trim($cells->item(2)->textContent);
                $receiver      = trim($cells->item(3)->textContent);
                $phone       = trim($cells->item(4)->textContent);
                $address       = trim($cells->item(5)->textContent);
                $city        = trim($cells->item(6)->textContent);
                $price  = trim($cells->item(7)->textContent);
                $status  = trim($cells->item(8)->textContent);
                $hasInvoice  = trim($cells->item(9)->textContent);
                $change  = trim($cells->item(10)->textContent);
                $asapCode  = trim($cells->item(11)->textContent);
                $spaceCode  = trim($cells->item(12)->textContent);
                $data[] = [
                    'id'             => $id,
                    'employee'       => $employee,
                    'created'           => $created,
                    'receiver'       => $receiver,
                    'phone'        => $phone,
                    'address'         => $address,
                    'city'         => $city,
                    'price'         => $price,
                    'state'         => $status,
                    'hasInvoice'         => $hasInvoice,
                    'change'         => $change,
                    'asap_code'  => $asapCode,
                    'space_code'  => $spaceCode,
                ];
            }
        }
        return $data;
    }

    public function invoices()
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/factures.php",
            "token" => "328893f698c34a058fd070d119731957b909c885d63",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, "https://api.scrape.do/?" . http_build_query($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
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
        $orders = Order::where('account_id', getAccountUser()->account_id)->whereIn('pickup_id', $pickups)->whereNull('shipment_id')->whereIn('order_status_id', [6,9])->get();
        $sessionId = $this->login();
        foreach ($orders as $key => $order) {
            $asapHistory = collect($this->getLastStatuses($order->code, $sessionId))->first();
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
                    case 'En distribution':
                        $id = 64;
                        break;
                    case 'Injoignable':
                        $id = 31;
                        break;
                    case 'Pas de réponse':
                        $id = 42;
                        break;
                    case 'Annulée':
                        $id = 33;
                        break;
                    case 'Refusé':
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
                    case 'Livré (Payé)':
                        $id = 25;
                        break;
                    case 'Livré':
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
                    case 'Annuler ( suivi )':
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
                    case 'Double Commande':
                        $id = 58;
                        break;
                    default:
                        $id = 64;
                        break;
                }

                $orderData = [
                    [
                        "id" => $order->id,
                        'meta' => $asapHistory['id']? $asapHistory['id']:$asapHistory['asap_code'], 
                        'shipping_code' => $asapHistory['asap_code'],
                        "comment" => [
                            "id" => $id,
                            "title" => $asapHistory['state']
                        ]
                    ]
                ];
                OrderController::update(new Request($orderData));
            }else{
                $orderData = [
                    [
                        "id" => $order->id,
                        'meta' => null, 
                        'shipping_code' => null,
                        'pickup_id' => null,
                        "comment" => [
                            "id" => 64,
                            "title" => 'Non traité'
                        ]
                    ]
                ];
                OrderController::update(new Request($orderData));
            }
        }

        return 'ok';
        //2,3,4,5,6,9,10,11,14,16,25,26,30,39,40,41,42,58,59 en cours
        //17 livré
        //7,8,12,13,15,18,19,20,21,22,23,24,27,28,29,31 annulées
    }
    public function syncInvoices()
    {
        $datas = $this->invoices();
        foreach ($datas as $key => $data) {
            $hasInvoice = Shipment::where('title', $data['code'])->first();
            if (!$hasInvoice) {

                $getAsapOrders = $this->invoiceOrders($data['id']);
                $orders = [];
                foreach ($getAsapOrders as $key => $asapOrder) {
                    $order = null;
                    return $asapOrder;
                    if ($asapOrder['code'])
                        $order = Order::where('shipping_code', $asapOrder['code'])->first();
                    if ($order)
                        $orders[] = ['id' => $order->id, 'carrier_price' => $asapOrder['shipping']];
                }
                $requestData = new Request([['carrier_id' => 22, 'shipment_type_id' => 1, 'warehouse_id' => 30, 'statut' => 1, 'title' => $data['code'], 'orders' => $orders]]);
                ShipmentController::store($requestData);
            }
        }
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
    }
    public function headings(): array
    {
        return [
            'Destinataire',
            'Téléphone',
            'Ville',
            'Adresse',
            'Prix',
            'Produit Ref',
            'Qté',
            'ID Intern',
            'Change (0/1)',
            'Ouvrir Colis (0/1)',
        ];
    }

    public function exportOrdersToXlsx()
    {
        return Excel::download($this, 'orders.xlsx');
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
