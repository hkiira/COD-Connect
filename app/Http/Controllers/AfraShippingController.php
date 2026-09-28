<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAfraShippingJob;
use App\Models\AccountCarrier;
use App\Models\AfraOrderOperation;
use App\Models\AfraStatusMapping;
use App\Models\AfraSyncRun;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Pickup;
use App\Services\AfraShippingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AfraShippingController extends Controller
{
    public function account()
    {
        $link = $this->link();
        return response()->json([
            'email' => $link?->username,
            'configured' => (bool) ($link?->username && $link?->password),
        ]);
    }

    public function saveAccount(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'nullable|string|min:1']);
        $link = AccountCarrier::firstOrNew(['account_id' => $this->accountId(), 'carrier_id' => 26]);
        $link->autocode = $link->autocode ?? 0;
        $link->statut = 1;
        $link->username = $data['email'];
        if (!empty($data['password'])) {
            $link->password = Crypt::encryptString($data['password']);
        }
        $link->save();
        return response()->json(['success' => true, 'configured' => (bool) $link->password]);
    }

    public function login(AfraShippingService $service)
    {
        try {
            $service->client($this->accountId())->login();
            return response()->json(['success' => true, 'message' => 'Connexion Afra réussie.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function orders(Request $request, AfraShippingService $service)
    {
        $data = $request->validate(['current_page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'status_id' => 'sometimes|integer|min:1']);
        try {
            return response()->json($service->client($this->accountId())->getOrders($data['current_page'] ?? 1, $data['per_page'] ?? 100, $data['status_id'] ?? null));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cities(AfraShippingService $service)
    {
        try {
            $raw = $service->client($this->accountId())->getCities();
            return response()->json([
                'cities' => $raw['cities'] ?? $raw['data'] ?? $raw,
                'local_cities' => DefaultCarrier::with('city:id,title')->where('carrier_id', 26)
                    ->get(['id', 'city_id', 'city_id_carrier', 'price', 'return']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function mapCity(Request $request, AfraShippingService $service)
    {
        $data = $request->validate([
            'afra_city_id' => 'required|integer|min:1',
            'city_id' => 'nullable|integer|exists:cities,id',
        ]);
        try {
            $raw = $service->client($this->accountId())->getCities();
            $cities = $raw['cities'] ?? $raw['data'] ?? $raw;
            if (!collect($cities)->contains(fn ($city) => (int) ($city['id'] ?? 0) === (int) $data['afra_city_id'])) {
                return response()->json(['message' => 'Ville Afra inconnue.'], 422);
            }
            DB::transaction(function () use ($data) {
                $rows = DefaultCarrier::where('carrier_id', 26)->lockForUpdate()->get();
                $target = $rows->firstWhere('city_id', $data['city_id'] ?? null);
                if (!empty($data['city_id']) && !$target) {
                    throw new \DomainException('Ville locale indisponible pour Afra.');
                }
                foreach ($rows as $row) {
                    if ((int) $row->city_id_carrier === (int) $data['afra_city_id']) {
                        $row->city_id_carrier = null;
                        $row->save();
                    }
                }
                if ($target) {
                    if ($target->city_id_carrier && (int) $target->city_id_carrier !== (int) $data['afra_city_id']) {
                        throw new \DomainException('Cette ville locale est déjà associée à une autre ville Afra.');
                    }
                    $target->city_id_carrier = $data['afra_city_id'];
                    $target->save();
                }
            });
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function statuses(AfraShippingService $service)
    {
        try {
            $raw = $service->client($this->accountId())->getStatuses();
            return response()->json([
                'statuses' => $raw['statuses'] ?? $raw['data'] ?? $raw,
                'local_statuses' => OrderStatus::get(['id', 'title']),
                'mappings' => AfraStatusMapping::where('account_id', $this->accountId())->get(['afra_status_id', 'order_status_id']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function mapStatus(Request $request, AfraShippingService $service)
    {
        $data = $request->validate([
            'afra_status_id' => 'required|integer|min:1',
            'order_status_id' => 'nullable|integer|exists:order_statuses,id',
        ]);
        try {
            $raw = $service->client($this->accountId())->getStatuses();
            $statuses = $raw['statuses'] ?? $raw['data'] ?? $raw;
            if (!collect($statuses)->contains(fn ($s) => (int) ($s['id'] ?? 0) === (int) $data['afra_status_id'])) {
                return response()->json(['message' => 'Statut Afra inconnu.'], 422);
            }
            if (empty($data['order_status_id'])) {
                AfraStatusMapping::where('account_id', $this->accountId())->where('afra_status_id', $data['afra_status_id'])->delete();
            } else {
                AfraStatusMapping::updateOrCreate(
                    ['account_id' => $this->accountId(), 'afra_status_id' => $data['afra_status_id']],
                    ['order_status_id' => $data['order_status_id']]
                );
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function syncPickup(int $pickupId)
    {
        $pickup = Pickup::with('accountUser')->find($pickupId);
        if (!$pickup || (int) $pickup->carrier_id !== 26 || (int) $pickup->accountUser?->account_id !== $this->accountId()) {
            return response()->json(['success' => false, 'message' => 'Pickup Afra introuvable pour ce compte.'], 404);
        }
        return $this->startRun('pickup', $pickupId);
    }

    public function syncStatusesNow()
    {
        return $this->startRun('statuses');
    }

    public function run(int $id)
    {
        return AfraSyncRun::where('account_id', $this->accountId())->findOrFail($id);
    }

    public function retryReturn(int $id, AfraShippingService $service)
    {
        $order = Order::where('account_id', $this->accountId())->where('order_status_id', 9)
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', 26))->findOrFail($id);
        return response()->json($service->requestReturn($order, getAccountUser()->id, true));
    }

    private function startRun(string $kind, ?int $pickupId = null)
    {
        $existing = AfraSyncRun::where('account_id', $this->accountId())->where('kind', $kind)
            ->where('pickup_id', $pickupId)->whereIn('status', ['queued', 'running'])->first();
        if ($existing) {
            return response()->json(['success' => true, 'run_id' => $existing->id, 'message' => 'Synchronisation déjà en cours.']);
        }
        $run = AfraSyncRun::create([
            'account_id' => $this->accountId(),
            'account_user_id' => getAccountUser()->id,
            'pickup_id' => $pickupId,
            'kind' => $kind,
            'status' => 'queued',
        ]);
        SyncAfraShippingJob::dispatch($run->id);
        return response()->json(['success' => true, 'run_id' => $run->id, 'message' => 'Synchronisation Afra en file d’attente.'], 202);
    }

    private function accountId(): int
    {
        return (int) getAccountUser()->account_id;
    }

    private function link(): ?AccountCarrier
    {
        return AccountCarrier::where('account_id', $this->accountId())->where('carrier_id', 26)->first();
    }
}
