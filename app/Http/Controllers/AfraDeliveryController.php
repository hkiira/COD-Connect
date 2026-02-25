<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\Phone;

use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;

class AfraDeliveryController extends Controller
{
    public function rest(Request $request, $entity, $id = null, $type = null)
    {
        if ($entity == 'login') {
            return $this->login();
        } elseif ($entity == 'orders') {
            return $this->orders($request);
        }  elseif ($entity == 'statuses') {
            return $this->statuses($request);
        } elseif ($entity == 'tickets') {
            return $this->tickets($id, $type);
        } elseif ($entity == 'cities') {
            return $this->cities();
        } elseif ($entity == 'import') {
            return $this->import($id);
        } elseif ($entity == 'check_cities') {
            return $this->checkCities($request);
        }  elseif ($entity == 'update_cities') {
            return $this->updateCities();
        } else {
            return "productsuppliers";
        }

        return response()->json([
            'statut' => 1,
            'data ' => "Entity restored successfully",
        ]);
    }
    public function login(){
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://afradelivery.com/api/seller/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
            "email": "stylemen.ma@gmail.com",
            "password": "demarrer"
            }',
        CURLOPT_HTTPHEADER => array(
            'Content-Type:  application/json',
            'Accept:  application/json',
        ),
        ));

        $response = curl_exec($curl);
        $decoded = json_decode($response, true);
        return $decoded['access_token'];

        curl_close($curl);
    }

    public function orders(Request $request){
        $current_page = $request->input('current_page', 1);
        $per_page = $request->input('per_page', 20);
        $status_id = $request->input('status_id', null);
        $token = $this->login();
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://afradelivery.com/api/seller/get-orders?current_page='.$current_page.'&per_page='.$per_page.'&status_id='.$status_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$token,
            'Content-Type:  application/json',
            'Accept:  application/json',
        ),
        ));

        $response = curl_exec($curl);
        $decoded = json_decode($response, true);
        foreach ($decoded['orders'] as $orderData) {
            $phoneNumber = $orderData['phone_number'] ?? null;
            $normalizedPhoneNumber = str_replace(' ', '', (string) $phoneNumber);
            $clientValue = (string) ($orderData['client'] ?? '');
            $orderCodeFromClient = null;

            if (strpos($clientValue, '-') !== false) {
                $orderCodeFromClient = trim(substr($clientValue, strpos($clientValue, '-') + 1));
            }
            if($orderData['status'] == 'En attente') continue;
            if($orderCodeFromClient){
                $order = Order::where('code', $orderCodeFromClient)->first();
                if($order && !$order->shipping_code){
                    $order->shipping_code = $orderData['number'] ?? null;
                    $order->save();
                    continue;
                }
            }else{
                $order = Order::where(function ($query) use ($normalizedPhoneNumber) {
                    $query->whereHas('phones', function ($phoneQuery) use ($normalizedPhoneNumber) {
                        $phoneQuery->whereRaw("REPLACE(title, ' ', '') LIKE ?", ['%' . $normalizedPhoneNumber . '%']);
                    })->orWhereHas('customer.phones', function ($phoneQuery) use ($normalizedPhoneNumber) {
                        $phoneQuery->whereRaw("REPLACE(title, ' ', '') LIKE ?", ['%' . $normalizedPhoneNumber . '%']);
                    });
                })->whereHas('pickup', function ($query) {
                    $query->where('carrier_id', 26);
                })->whereNull('shipping_code')->orderBy('created_at', 'desc')->first();
                if($order && !$order->shipping_code){
                        $order->shipping_code = $orderData['number'] ?? null;
                        $order->save();
                        continue;
                    }
                }
        }

        curl_close($curl);
    }
    public function statuses(Request $request){
        $current_page = $request->input('current_page', 1);
        $per_page = $request->input('per_page', 20);
        $status_id = $request->input('status_id', null);
        $token = $this->login();
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://afradelivery.com/api/seller/get-orders?current_page='.$current_page.'&per_page='.$per_page.'&status_id='.$status_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer '.$token,
            'Content-Type:  application/json',
            'Accept:  application/json',
        ),
        ));

        $response = curl_exec($curl);
        $decoded = json_decode($response, true);
        foreach ($decoded['orders'] as $orderData) {
            $order = Order::where('shipping_code', $orderData['number'])->first();
            if($orderData['status']=="En attente" || $order->order_status_id==7 || $order->shipment_id!=null) continue;
            switch ($orderData['status']) {
                case "En attente":
                    break;
                case "Ramassé":
                        $id = 65;
                        break;
                case "En Sortie":
                        $id = 29;
                        break;
                case "En Livraison":
                        $id = 29;
                        break;
                case "Livré":
                        $id = 25;
                        break;
                case "Annulée":
                        $id = 33;
                        break;
                case "Retourné":
                        $id = 33;
                        break;
                case "Injoignable":
                        $id = 31;
                        break;
                case "Confirmé":
                        $id = 64;
                        break;
                case "Entrée en Agence":
                        $id = 33;
                        break;
                case "Partiellement Livrée":
                        $id = 25;
                        break;
                case "Récupérer":
                        $id = 33;
                        break;
                case "Remboursée":
                        $id = 64;
                        break;
                case "Refusée":
                        $id = 34;
                        break;
                case "Reçu par vendeur":
                        $id = 33;
                        break;
                case "Demande de change":
                        $id = 64;
                        break;
                case "Pas de réponse":
                        $id = 42;
                        break;
                case "Changement d'adresse":
                        $id = 64;
                        break;
                case "Demande de Retour":
                        $id = 33;
                        break;
                case "Demande de Remboursement":
                        $id = 64;
                        break;
                case "Partiellement entrée en agence":
                        $id = 33;
                        break;
                case "Partiellement Récupéré":
                        $id = 33;
                        break;
                case "En Retour":
                         $id = 33;
                        break;
                case "Retourné - Entré en agence":
                        $id = 33;
                        break;
                case "Reportée":
                        $id = 28;
                        break;
                case "Programmé":
                        $id = 28;
                        break;
                case "Réception en agence":
                        $id = 33;
                        break;
                case "Confirmation en Attente":
                    break;
                case "Hors Zone":
                        $id = 62;
                        break;
                case "En Préparation":
                        $id = 64;
                        break;
                case "Reconfirmé":
                        $id = 64;
                        break;
                case "Réception par Livreur":
                        $id = 64;
                        break;
                default:
                        $id = 64;
                        break;
            }
            $orderData = [
                [
                    "id" => $order->id,
                    "comment" => [
                        "id" => $id,
                        "title" => $orderData['status']
                    ]
                ]
            ];
            OrderController::update(new Request($orderData));
        }

        curl_close($curl);
    }

    /**
     * Import orders from an uploaded .xlsx file.
     * The file is expected to have 16 columns in the following order:
     * ville_destinataire, statut, waybill, ordre_de_client, fret, client,
     * type_express, montant_total, telephone_destinataire, temps_ramassage,
     * date_collection, autoriser_ouverture, adresse_destinataire,
     * statut_facturation, retourne, remarque
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
                'ref_commande' => $order->code,
                'client' => $order->customer->name."-".$order->code,
                'téléphone' => $order->customer->activePhones->last()->title,
                'ville' => $order->customer->activeAddresses->first()->city->title ?? '',
                'adresse' => $order->customer->activeAddresses->first()->title,
                'nom produit' => implode(" \n ", collect($order->orderPvaTtitle()->map(function ($item) {
                    return $item['product'] . ' ' . implode(' ', $item['attributes']);
                }))->map(fn($item) => $item)->toArray()),
                'quantite' => $order->activeOrderPvas->sum('quantity'),
                'prix_unitaire' => $order->calculateActivePvasTotalValue()-$order->discount+$order->shipping_price,
                'commentaire' => $order->comment
            ];
        });
        // Single row header
        $headings = [
            'ref_commande', 'client', 'téléphone', 'ville', 'adresse',  'nom produit', 'quantite', "prix_unitaire", 'commentaire'
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
}
