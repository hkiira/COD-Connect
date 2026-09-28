<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Pickup;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

/** Legacy routes stay available, but all live API operations use the account-scoped client. */
class AfraDeliveryController extends Controller
{
    public function rest(Request $request, string $entity)
    {
        $shipping = app(AfraShippingController::class);
        return match ($entity) {
            'login' => $shipping->login(app(\App\Services\AfraShippingService::class)),
            'orders' => $shipping->orders($request, app(\App\Services\AfraShippingService::class)),
            'statuses' => $shipping->syncStatusesNow(),
            'cities' => $shipping->cities(app(\App\Services\AfraShippingService::class)),
            default => response()->json(['message' => 'Endpoint Afra non supporté.'], 404),
        };
    }

    // Preserve the existing upload endpoint's response contract.
    public function importOrders(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls']);
        $file = $request->file('file');
        return response()->json([
            'success' => true,
            'message' => 'File received',
            'file_info' => [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ],
        ]);
    }

    public function exportPickupOrders(int $id)
    {
        $pickup = Pickup::with('accountUser')->findOrFail($id);
        abort_unless((int) $pickup->carrier_id === 26 &&
            (int) $pickup->accountUser?->account_id === (int) getAccountUser()->account_id, 404);

        $rows = Order::with(['customer.activePhones', 'customer.activeAddresses.city', 'activePvas.product'])
            ->where('pickup_id', $id)->where('account_id', getAccountUser()->account_id)
            ->orderByDesc('id')->get()->map(function ($order) {
                $products = $order->activePvas->map(fn ($pva) => $pva->product?->title.' × '.$pva->pivot->quantity)->implode("\n");
                return [
                    $order->code,
                    $order->customer?->name.'-'.$order->code,
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
