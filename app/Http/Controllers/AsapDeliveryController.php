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
use App\Services\AsapDeliveryService;
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
    use \App\Traits\ScrapesAsapHistory;
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
            case 'login':
                return $this->login();
            case 'pickup':
                return $this->pickup($id);
            case 'asap-history':
                return $this->asapHistory($id, $type);
            case 'sync_invoices':
                return $this->syncInvoices();
            case 'get_order':
                return $this->getOrder($id);
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
            case 'bls':
                return $this->bls($request->input('id'), $request->input('colis'));
            case 'create_pickup':
                return $this->createPickup();
            case 'print_pickup':
                return $this->printPickup($id);
            case 'orders':
                return $this->orders($id);
            case 'raw_html':
                return $this->rawHtml($id);
            case 'order_history':
                return $this->historyOrder($id);
            case 'create_order':
                $order = Order::find($id);
                if (!$order) {
                    return ["statut" => 0, "data" => "Commande introuvable"];
                }
                $total = 0;
                $qty = 0;
                $order->activePvas->map(function ($activePva) use (&$total, &$qty) {
                    $total += $activePva->pivot->quantity * $activePva->pivot->price;
                    $qty++;
                });
                $total = $total - $order->discount;
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
                    'city' => $order->customer->addresses->first()->city->title ?? $order->city->title,
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
                return $this->createOrder($data);
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
                'Destinataire' => $order->customer->name . "-" . $order->code,
                'Téléphone' => $order->customer->activePhones->last()->title,
                'Ville' => $order->customer->activeAddresses->first()->city->title ?? '',
                'Adresse' => $order->customer->activeAddresses->first()->title,
                'Prix' => $order->calculateActivePvasTotalValue() - $order->discount + $order->shipping_price,
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
            'Destinataire',
            'Téléphone',
            'Ville',
            'Adresse',
            'Prix',
            'Produit Ref',
            'Qté',
            "ID Intern",
            'Change (0/1)',
            'Ouvrir Colis (0/1)',
            'commentaire',
            'date_creation'
        ];

        $export = new class ($data, $headings) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithStyles {
            protected $data;
            protected $headings;
            public function __construct($data, $headings)
            {
                $this->data = $data;
                $this->headings = $headings;
            }
            public function collection()
            {
                return $this->data;
            }
            public function headings(): array
            {
                return $this->headings;
            }
            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
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
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $htmlContent = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
                $shipping = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $data[] = [
                    'num' => $num,
                    'code' => $code,
                    'phone' => $phone,
                    'city' => $city,
                    'status' => $status,
                    'price' => $price,
                    'shipping' => $shipping,
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
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $htmlContent = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
        return $orders->map(function ($order) {
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
        $sessionId = $this->login();
        $pickup = Pickup::where('id', $id)->first();

        foreach ($pickup->orders()->whereNull('shipping_code')->get() as $key => $order) {
            $total = 0;
            $qty = 0;
            $order->activePvas->map(function ($activePva) use (&$total, &$qty) {
                $total += $activePva->pivot->quantity * $activePva->pivot->price;
                $qty++;
            });
            $total = $total - $order->discount;
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
                'city' => $order->customer->addresses->first()->city->title ?? $order->city->title,
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
            $order->update(['meta' => 1]);
            $this->createOrder($data, $sessionId);
            $asapOrder = $this->getLastStatuses($order->code, $sessionId);
            if ($asapOrder) {
                $order->update(['meta' => $asapOrder[0]['id'], 'shipping_code' => $asapOrder[0]['asap_code']]);
            }
        }
        return $pickup->orders;
    }
    public function syncOrders()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user) {
            \App\Jobs\SyncAsapOrdersJob::dispatch($user);
            return response()->json([
                'success' => true,
                'message' => 'La synchronisation a été lancée en arrière-plan. Les commandes seront mises à jour sous peu.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Utilisateur non authentifié.',
        ], 401);
    }

    public function runSync()
    {
        $sessionId = $this->login();
        $updatedCount = 0;
        $ordersQuery = Order::where('account_id', getAccountUser()->account_id)
            ->whereNull('shipment_id')
            ->whereNull('shipping_code')
            ->whereIn('order_status_id', [4]);

        $totalOrders = $ordersQuery->count();

        $ordersQuery->chunkById(50, function ($orders) use (&$updatedCount, $sessionId) {
            $orderData = $orders->map(function ($order) use (&$updatedCount, $sessionId) {
                try {
                    $asapHistory = $this->getOrder($order->code, $sessionId);
                    if ($asapHistory) {
                        // Update the order with ASAP order ID and shipping code
                        $updatedCount++;
                        return [
                            "id" => $order->id,
                            'meta' => $asapHistory[0]['id'] ?: $order->meta,
                            'shipping_code' => $asapHistory[0]['asap_code'],
                            "comment" => [
                                "id" => "29",
                                "title" => "ajout du code : " . $asapHistory[0]['asap_code']
                            ]
                        ];
                    } else {
                        return [
                            "id" => $order->id,
                            'sync' => $order->sync + 1,
                        ];
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to sync order {$order->code}: " . $e->getMessage(), [
                        'exception' => $e
                    ]);
                    return null;
                }
            })->filter()->values();

            if ($orderData->isNotEmpty()) {
                OrderController::update(new Request($orderData->toArray()), $local = 2);
            }
        });


        return [
            'success' => true,
            'message' => "Synchronisation effectuée avec succès. $updatedCount / $totalOrders commandes mises à jour.",
        ];
    }
    //katjib la commande men systeme dial ASAP b search
    public function getOrder($code, $sessionId = null)
    {
        if (!$sessionId) {
            $sessionId = $this->login();
        }
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
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);
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
                $created = trim($cells->item(2)->textContent);
                $receiver = utf8_decode(trim($cells->item(3)->textContent));
                $phone = trim($cells->item(4)->textContent);
                $city = trim($cells->item(5)->textContent);
                $price = trim($cells->item(6)->textContent);
                $status = utf8_decode(trim($cells->item(7)->textContent));
                $change = trim($cells->item(8)->textContent);
                $asapCode = trim($cells->item(9)->textContent);
                $spaceCode = trim($cells->item(10)->textContent);
                $cleaned = preg_replace('/\s+/', ' ', $status);
                $data[] = [
                    'id' => $id,
                    'created' => $created,
                    'receiver' => $receiver,
                    'phone' => $phone,
                    'city' => $city,
                    'price' => $price,
                    'state' => $cleaned,
                    'change' => $change,
                    'asap_code' => $asapCode,
                    'space_code' => $spaceCode,
                ];
            }
        }
        return $data;
    }
    //crée une commande dans le systéme de ASAP
    public function createOrder($data, $sessionId = null)
    {
        $sessionId = $sessionId ?? $this->login();
        $curl = curl_init();
        $body = http_build_query($data);
        $scrapeData = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $scrapeData);
        curl_close($curl);
        return $uploadResponse;
    }
    // Récupérer l'historique d'une commande par Order Asap Id
    public function historyOrder($orderId)
    {
        $sessionId = $this->login();
        return $this->scrapeColisHistoryData($orderId, $sessionId);
    }

    public function asapHistory($startId, $endId)
    {
        $log = \Illuminate\Support\Facades\Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/asap_scrape.log'),
        ]);
        $sessionId = $this->login();
        if (!$sessionId) {
            $log->error("API Test: Failed to login");
            return response()->json(['error' => 'Failed to login']);
        }

        $results = [];
        for ($id = $startId; $id <= $endId; $id++) {
            try {
                $res = $this->processColisHistory($id, $sessionId, $log);
                if ($res['status'] === 'success') {
                    $results[$id] = [
                        'status' => 'Success',
                        'meta' => $res['meta']->toArray()
                    ];
                } else {
                    $results[$id] = $res['message'] ?? $res['status'];
                }
            } catch (\Exception $e) {
                $log->error("API Test (Colis ID {$id}): Exception Caught! " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
                $results[$id] = 'Exception: ' . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 'Completed',
            'results' => $results
        ]);
    }

    public function rawHtml($id)
    {
        $sessionId = $this->login();
        if (!$sessionId) {
            return response()->json(['error' => 'Failed to login'], 500);
        }

        $html = $this->fetchRawColisHistoryHtml($id, $sessionId);

        return response($html)->header('Content-Type', 'text/html');
    }

    //hadi makhedamach 7ta nchof blanha
    public function createPickup()
    {
        $sessionId = $this->login();
        if (!$sessionId) {
            return 'Erreur : Impossible de récupérer PHPSESSID';
        }

        $curl = curl_init();
        $body = http_build_query([
            'nonce' => '86396d6332ae8331c3cebecb40c538db',
            "ids" => [127343], //hna les ids dial les colis en attente
            'client' => '5986',
            'action' => 'createbr1',
        ]);
        $scrapeData = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $scrapeData);
        curl_close($curl);
        return $uploadResponse;
    }
    public function printPickup($id)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $data = [
            "url" => "https://app.asapdelivery.ma/printtickets.php?id=" . $id . "&model=3",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/bls.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
                $employee = trim($cells->item(1)->textContent);
                $code = trim($cells->item(2)->textContent);
                $nb_colis = trim($cells->item(3)->textContent);
                $note = trim($cells->item(4)->textContent);
                $status = trim($cells->item(5)->textContent);
                $dateCreation = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $actionCell = $cells->item(7);
                $links = $actionCell->getElementsByTagName('a');
                $printLink = $links->length > 0 ? $links->item(0)->getAttribute('href') : null;
                $exportLink = "exportbls.php?id=" . $id . "&type=BRC&code=" . $code;
                $data[] = [
                    'id' => $id,
                    'employee' => $employee,
                    'code' => $code,
                    'nb_colis' => $nb_colis,
                    'note' => $note,
                    'status' => $status,
                    'date_creation' => $dateCreation,
                    'print_link' => $printLink,
                    'export_link' => $exportLink,
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
        $data = [
            "url" => "https://app.asapdelivery.ma/inc/ramassage.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
                $employee = trim($cells->item(1)->textContent);
                $created = trim($cells->item(2)->textContent);
                $receiver = trim($cells->item(3)->textContent);
                $phone = trim($cells->item(4)->textContent);
                $city = trim($cells->item(5)->textContent);
                $price = trim($cells->item(6)->textContent);
                // Get action links from the last cell.
                $state = trim($cells->item(7)->textContent);
                $change = trim($cells->item(8)->textContent);
                $asapCode = trim($cells->item(9)->textContent);
                $spaceCode = trim($cells->item(10)->textContent);
                $product = trim($cells->item(11)->textContent);
                $stock = trim($cells->item(12)->textContent);
                $data[] = [
                    'id' => $id,
                    'employee' => $employee,
                    'created' => $created,
                    'receiver' => $receiver,
                    'phone' => $phone,
                    'city' => $city,
                    'price' => $price,
                    'state' => $state,
                    'change' => $change,
                    'asap_code' => $asapCode,
                    'space_code' => $spaceCode,
                    'product' => $product,
                    'stock' => $stock,
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

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);
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
                $created = trim($cells->item(2)->textContent);
                $receiver = utf8_decode(trim($cells->item(3)->textContent));
                $phone = trim($cells->item(4)->textContent);
                $address = utf8_decode(trim($cells->item(5)->textContent));
                $city = trim($cells->item(6)->textContent);
                preg_match('/L:\s*(\d+)/', $city, $matches);
                $delivery = $matches[1] ?? null;
                $price = trim($cells->item(7)->textContent);
                $status = utf8_decode(trim($cells->item(8)->textContent));
                $hasInvoice = trim($cells->item(9)->textContent);
                $change = trim($cells->item(10)->textContent);
                $asapCode = trim($cells->item(11)->textContent);
                $spaceCode = trim($cells->item(12)->textContent);
                $data[] = [
                    'id' => $id,
                    'created' => $created,
                    'created' => $created,
                    'receiver' => $receiver,
                    'phone' => $phone,
                    'delivery' => $delivery,
                    'price' => $price,
                    'state' => $status,
                    'change' => $change,
                    'asap_code' => $asapCode,
                    'space_code' => $spaceCode,
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

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/colis.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
                $employee = trim($cells->item(1)->textContent);
                $created = trim($cells->item(2)->textContent);
                $receiver = trim($cells->item(3)->textContent);
                $phone = trim($cells->item(4)->textContent);
                $address = trim($cells->item(5)->textContent);
                $city = trim($cells->item(6)->textContent);
                $price = trim($cells->item(7)->textContent);
                $status = trim($cells->item(8)->textContent);
                $hasInvoice = trim($cells->item(9)->textContent);
                $change = trim($cells->item(10)->textContent);
                $asapCode = trim($cells->item(11)->textContent);
                $spaceCode = trim($cells->item(12)->textContent);
                $data[] = [
                    'id' => $id,
                    'employee' => $employee,
                    'created' => $created,
                    'receiver' => $receiver,
                    'phone' => $phone,
                    'address' => $address,
                    'city' => $city,
                    'price' => $price,
                    'state' => $status,
                    'hasInvoice' => $hasInvoice,
                    'change' => $change,
                    'asap_code' => $asapCode,
                    'space_code' => $spaceCode,
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

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/factures.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);


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
                $employee = trim($cells->item(1)->textContent);
                $code = trim($cells->item(2)->textContent);
                $nb_colis = trim($cells->item(3)->textContent);
                $montant = trim($cells->item(4)->textContent);
                $mas_ch = trim($cells->item(5)->textContent);
                $note = trim($cells->item(6)->textContent);
                $dateCreation = trim($cells->item(7)->textContent);
                $dateVersement = trim($cells->item(8)->textContent);
                $status = trim($cells->item(9)->textContent);
                // Get action links from the last cell.
                $actionCell = $cells->item(10);
                $links = $actionCell->getElementsByTagName('a');
                $printLink = $links->length > 0 ? $links->item(0)->getAttribute('href') : null;
                $exportLink = $links->length > 1 ? $links->item(1)->getAttribute('href') : null;
                $data[] = [
                    'id' => $id,
                    'employee' => $employee,
                    'code' => $code,
                    'nb_colis' => $nb_colis,
                    'montant' => $montant,
                    'mas_ch' => $mas_ch,
                    'note' => $note,
                    'date_creation' => $dateCreation,
                    'date_versement' => $dateVersement,
                    'status' => $status,
                    'print_link' => $printLink,
                    'export_link' => $exportLink,
                ];
            }
        }

        // $data now contains an array of all invoice rows.
        return $data;
    }
    public function syncStatuses()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user) {
            \App\Jobs\SyncAsapStatusesJob::dispatch($user);
            return response()->json([
                'success' => true,
                'message' => 'La synchronisation des statuts a été lancée en arrière-plan. Les commandes seront mises à jour sous peu.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Utilisateur non authentifié.',
        ], 401);
    }

    /**
     * Run the status synchronization for orders assigned to ASAP Delivery (carrier_id = 22).
     *
     * @return array
     */
    public function runSyncStatuses()
    {
        // 1. Fetch pickup IDs associated with ASAP carrier (carrier_id = 22)
        $pickups = Pickup::where('carrier_id', 22)->pluck('id')->toArray();

        // 2. Initialize tracking counters for the current run
        $deliveredCount = 0; // Tracks the number of successfully delivered orders
        $canceledCount = 0;  // Tracks the number of canceled/returned orders
        $totalProcessed = 0; // Tracks the total number of orders processed in this run
        $limit = 10;         // Max limit of orders to process per execution to prevent timeouts

        // 3. Login to ASAP Delivery portal and retrieve the active session cookie
        $sessionId = $this->login();

        // 4. Query orders belonging to the authenticated user's account in status 6 (In Transit) and matching carrier pickups
        Order::where('account_id', getAccountUser()->account_id)
            ->whereIn('order_status_id', [6]) // Status 6 indicates 'In Transit'
            ->whereIn('pickup_id', $pickups)   // Filter by ASAP carrier pickups
            ->orderBy('created_at', 'asc')     // Order by creation date to process older orders first
            // Chunk the query results to manage memory usage
            ->chunk(100, function ($orders) use (&$deliveredCount, &$canceledCount, &$totalProcessed, $limit, $sessionId) {
                // Loop through each order in the current chunk
                foreach ($orders as $order) {


                    // Variable to hold the latest tracking event retrieved
                    $latestEvent = null;

                    try {
                        // Priority 1: If a shipping code exists, search the ASAP portal using the shipping code
                        if ($order->shipping_code) {
                            $asapHistory = $this->getOrder($order->shipping_code, $sessionId);
                            if ($asapHistory && isset($asapHistory[0])) {
                                $latestEvent = $asapHistory[0]; // Set the most recent tracking entry
                            }
                        } else {
                            // Priority 2: Fallback to tracking using the internal order code
                            $asapHistory = $this->getOrder($order->code, $sessionId);
                            if ($asapHistory && isset($asapHistory[0])) {
                                $latestEvent = $asapHistory[0]; // Set the most recent tracking entry
                            } else {
                                // Priority 3: Fallback to tracking using the customer's phone number
                                $asapHistory = $this->getOrder($order->customer->activePhones->first()->title, $sessionId);
                                if ($asapHistory && isset($asapHistory[0])) {
                                    $latestEvent = $asapHistory[0]; // Set the most recent tracking entry
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        // Log a warning if the getOrder scraping call fails for an order, so other orders can still sync
                        \Illuminate\Support\Facades\Log::warning("ASAP Delivery getOrder failed for order {$order->code}: " . $e->getMessage());
                    }

                    // 5. Map the latest ASAP event state to local comment and status IDs
                    if ($latestEvent) {
                        $id = 64; // Default to 'En cours' status comment ID
                        switch ($latestEvent['state']) {
                            case 'En attente de ramassage':
                                break;
                            case 'Ramassé':
                                $id = 65; // 'Ramassé'
                                break;
                            case 'Receptionné':
                                $id = 64; // 'En cours'
                                break;
                            case 'Expédié':
                                $id = 29; // 'Ajout du code'
                                break;
                            case 'Reçu par livreur':
                                $id = 64; // 'En cours'
                                break;
                            case 'Faux destination':
                                $id = 62; // 'Hors zone'
                                break;
                            case 'Hors zone':
                                $id = 62; // 'Hors zone'
                                break;
                            case 'En distribution':
                                $id = 64; // 'En cours'
                                break;
                            case 'Injoignable':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Pas de réponse':
                                $id = 42; // 'Client ne répond pas'
                                break;
                            case 'Annulée':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Refusé':
                                $id = 34; // 'Commande refusée'
                                break;
                            case 'Changement client':
                                $id = 64; // 'En cours'
                                break;
                            case 'Demande de retour':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Reporté':
                                $id = 28; // 'Reporté'
                                break;
                            case 'Livré (Payé)':
                                $id = 25; // 'Livrée'
                                break;
                            case 'Livré':
                                $id = 25; // 'Livrée'
                                break;
                            case 'Retour vers agence casa':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Retour reçu agence casa':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Retour client expédié':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Retour client reçu':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Interessé':
                                $id = 64; // 'En cours'
                                break;
                            case 'Demande de suivi':
                                $id = 64; // 'En cours'
                                break;
                            case 'En attente de retour':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Change':
                                $id = 64; // 'En cours'
                                break;
                            case 'Programmé':
                                $id = 64; // 'En cours'
                                break;
                            case 'A retourner vers agence principal casa':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'en voyage':
                                $id = 35; // 'Client en voyage'
                                break;
                            case 'pas de réponse 2 fois':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'pas de réponse 3 fois':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'pas de réponse 4 fois':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Pas de réponse LV':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Pas de réponse 5 fois':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Pas de réponse ( suivi )':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Annuler ( suivi )':
                                $id = 33; // 'Commande annulée'
                                break;
                            case 'Reporté ( suivi )':
                                $id = 28; // 'Reporté'
                                break;
                            case 'Changement numéro':
                                $id = 64; // 'En cours'
                                break;
                            case 'En attente d\'appel du client':
                                $id = 64; // 'En cours'
                                break;
                            case 'Numéro Incorrect':
                                $id = 64; // 'En cours'
                                break;
                            case 'Injoignable ( suivi )':
                                $id = 31; // 'Client Injoignable'
                                break;
                            case 'Double Commande':
                                $id = 58; // 'Retard de traitement interne'
                                break;
                            default:
                                $id = 64; // Default comment ID
                                break;
                        }

                        // 6. Increment specific status outcome counters
                        if ($id === 25) {
                            $deliveredCount++; // Increment delivered count if mapped status is Livrée (25)
                        } elseif ($id === 33) {
                            $canceledCount++;  // Increment canceled count if mapped status is Commande annulée (33)
                        }

                        // 7. Prepare local order update data structure
                        $orderData = [
                            [
                                "id" => $order->id,
                                'meta' => $order->meta ?? ($order->shipping_code ?? $order->code),
                                'shipping_code' => $order->shipping_code ?? $latestEvent['asap_code'],
                                "comment" => [
                                    "id" => $id,
                                    "title" => $latestEvent['state']
                                ]
                            ]
                        ];
                        // 8. Update order in local system and append the comment log/history
                        OrderController::update(new Request($orderData));
                    }

                    // Increment count of processed orders
                    $totalProcessed++;
                }

            });

        // 9. Return execution sync summary statistics
        return [
            'success' => true,
            'message' => "Statuses synchronized successfully: {$deliveredCount} delivered, {$canceledCount} canceled",
        ];
    }
    public function syncInvoices()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user) {
            \App\Jobs\SyncAsapInvoicesJob::dispatch($user);
            return response()->json([
                'success' => true,
                'message' => 'La synchronisation des factures a été lancée en arrière-plan. Elles seront mises à jour sous peu.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Utilisateur non authentifié.',
        ], 401);
    }

    public function runSyncInvoices()
    {
        $datas = $this->invoices();
        foreach ($datas as $key => $data) {
            $hasInvoice = Shipment::where('title', $data['code'])->first();
            if (!$hasInvoice) {

                $getAsapOrders = $this->invoiceOrders($data['id']);
                $orders = [];
                foreach ($getAsapOrders as $key => $asapOrder) {
                    $order = null;
                    if ($asapOrder['code'])
                        $order = Order::where('shipping_code', $asapOrder['code'])->first();
                    if ($order)
                        $orders[] = ['id' => $order->id, 'carrier_price' => $asapOrder['shipping']];
                }
                $requestData = new Request([['carrier_id' => 22, 'shipment_type_id' => 1, 'warehouse_id' => 30, 'statut' => 1, 'title' => $data['code'], 'orders' => $orders]]);
                ShipmentController::store($requestData);
            }
        }

        return [
            'success' => true,
            'message' => 'Invoices synchronized successfully.',
        ];
    }
    public function syncReturns()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user) {
            \App\Jobs\SyncAsapReturnsJob::dispatch($user);
            return response()->json([
                'success' => true,
                'message' => 'La synchronisation des retours a été lancée en arrière-plan. Ils seront mis à jour sous peu.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Utilisateur non authentifié.',
        ], 401);
    }

    public function runSyncReturns()
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
            'success' => true,
            'message' => 'Returns synchronized successfully.',
        ];
    }
    public function bls($id, $colis)
    {
        $sessionId = $this->login();
        $curl = curl_init();
        $body = [
            "type" => "BR",
            "id" => $id,
            "colis" => $colis,
            "keyword" => "",
            "client" => "",
            "worker" => "",
            "dlm" => "",
            "city" => "",
            "fstock" => "",
            "datestart" => "",
            "dateend" => "",
            "action" => "loadblscolisadded"
        ];
        $body = http_build_query($body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

        $data = [
            "url" => "https://app.asapdelivery.ma/inc/bls.php",
            "customHeaders" => "true"
        ];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: */*",
            'cookie: ' . $sessionId,
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

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
            if ($cells->length >= 6) {
                $code = $row->getAttribute('data-code');
                $phone = $row->getAttribute('data-phone');
                $fullname = $row->getAttribute('data-fullname');
                $address = $row->getAttribute('data-address');
                $cityAttr = $row->getAttribute('data-city');

                // Extract data from cells
                $deleteLink = $cells->item(0)->getElementsByTagName('a')->item(0);
                $colisId = $deleteLink ? $deleteLink->getAttribute('data-id') : null;

                $city = trim($cells->item(2)->textContent);
                $price = trim($cells->item(3)->textContent);
                $state = trim($cells->item(4)->textContent);
                $dateUpdate = trim($cells->item(5)->textContent);

                $data[] = [
                    'id' => $colisId,
                    'code' => $code,
                    'phone' => $phone,
                    'fullname' => $fullname,
                    'address' => $address,
                    'city_attr' => $cityAttr,
                    'city' => $city,
                    'price' => $price,
                    'state' => $state,
                    'date_update' => $dateUpdate,
                ];
            }
        }
        return $data;
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
        }
        ;

        $cityUpdated = $this->updateCities();
        if (1 == $cityUpdated['statut']) {
            $orders = collect($request['orders'])->map(function ($orderId) {
                $order = Order::find($orderId);
                $cityExist = $order->city->defaultCarriers()->where(['carrier_id' => 22, 'statut' => 1])->first();
                if (!$cityExist)
                    return $order->id;
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
