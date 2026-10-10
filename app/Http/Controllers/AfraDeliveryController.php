<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Pickup;
use App\Services\AfraShippingClient;
use App\Services\AfraShippingService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

/** Excel export of an Afra pickup, for a manual upload on Afra's website. The API lives in AfraShippingController. */
class AfraDeliveryController extends Controller
{
    public function exportPickupOrders(int $id)
    {
        $pickup = Pickup::with('accountUser')->findOrFail($id);
        abort_unless((int) $pickup->carrier_id === AfraShippingClient::carrierId() &&
            (int) $pickup->accountUser?->account_id === (int) getAccountUser()->account_id, 404);

        $rows = Order::with(['customer.activePhones', 'customer.activeAddresses.city', 'activePvas.product', 'activePvas.variationAttribute.childVariationAttributes.attribute'])
            ->where('pickup_id', $id)->where('account_id', getAccountUser()->account_id)
            ->orderByDesc('id')->get()->map(function ($order) {
                $products = $order->activePvas->filter(fn ($pva) => (int) $pva->pivot->quantity > 0)
                    ->map(fn ($pva) => AfraShippingService::productName($pva).' × '.$pva->pivot->quantity)->implode("\n");

                return [
                    $order->code,
                    AfraShippingService::clientName($order),
                    $order->customer?->activePhones->first()?->title,
                    $order->customer?->activeAddresses->first()?->city?->title,
                    $order->customer?->activeAddresses->first()?->title,
                    $products,
                    $order->activePvas->sum(fn ($pva) => $pva->pivot->quantity),
                    $order->calculateActivePvasTotalValue() - $order->discount + $order->carrier_price,
                    $order->note,
                ];
            });

        $export = new class($rows) implements FromCollection, WithHeadings {
            public function __construct(private $rows) {}
            public function collection() { return $this->rows; }
            public function headings(): array
            {
                return ['ref_commande', 'client', 'téléphone', 'ville', 'adresse', 'nom produit', 'quantite', 'prix_unitaire', 'commentaire'];
            }
        };

        return Excel::download($export, 'orders_export.xlsx');
    }
}
