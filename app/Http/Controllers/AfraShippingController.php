<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAfraShippingJob;
use App\Models\AccountCarrier;
use App\Models\AfraCity;
use App\Models\AfraCityChange;
use App\Models\AfraOrderOperation;
use App\Models\AfraStatusMapping;
use App\Models\AfraSyncRun;
use App\Models\City;
use App\Models\Comment;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Pickup;
use App\Services\AfraCityWatcher;
use App\Services\AfraException;
use App\Services\AfraOperationsService;
use App\Services\AfraShippingClient;
use App\Services\AfraShippingService;
use Illuminate\Http\Request;

/** Afra settings page (account, cities, statuses) and the actions the order / pickup pages trigger. */
class AfraShippingController extends Controller
{
    public function __construct(private readonly AfraShippingService $service)
    {
    }

    // region account

    public function account()
    {
        $link = $this->link();

        return response()->json([
            'email'      => $link?->username,
            'configured' => (bool) ($link?->username && $link?->password),
            'settings'   => $this->service->settings($this->accountId()),
        ]);
    }

    public function saveAccount(Request $request)
    {
        $data = $request->validate([
            'email'        => 'required|email|max:255',
            'password'     => 'nullable|string|min:1',
            'test_product' => 'sometimes|boolean',
        ]);

        $link = AccountCarrier::firstOrNew(['account_id' => $this->accountId(), 'carrier_id' => AfraShippingClient::carrierId()]);
        $link->autocode ??= 0;
        $link->statut = 1;
        $link->username = $data['email'];
        if (!empty($data['password'])) {
            $link->password = $data['password']; // encrypted by the EncryptedCredential cast
        }
        if (array_key_exists('test_product', $data)) {
            $link->settings = ['test_product' => (bool) $data['test_product']] + ($link->settings ?? []);
        }
        $link->save();

        return response()->json([
            'success'    => true,
            'configured' => (bool) $link->password,
            'settings'   => $this->service->settings($this->accountId()),
        ]);
    }

    /** Tests the stored credentials with a fresh login. */
    public function login()
    {
        return $this->attempt(function () {
            $client = $this->service->client($this->accountId());
            $client->forgetToken();
            $client->login();

            return ['success' => true, 'message' => 'Connexion Afra réussie.'];
        });
    }

    public function orders(Request $request)
    {
        $data = $request->validate([
            'current_page' => 'sometimes|integer|min:1',
            'per_page'     => 'sometimes|integer|min:1|max:100',
            'status_id'    => 'sometimes|integer|min:1',
        ]);

        return $this->attempt(fn () => $this->service->client($this->accountId())
            ->getOrders($data['current_page'] ?? 1, $data['per_page'] ?? 100, $data['status_id'] ?? null));
    }

    // endregion

    // region cities

    /** Afra cities, the current links (with their tariff) and every local city for the search. */
    public function cities()
    {
        return $this->attempt(fn () => [
            'cities'       => $this->service->client($this->accountId())->getCities(),
            'links'        => DefaultCarrier::with('city:id,title')->where('carrier_id', AfraShippingClient::carrierId())
                ->whereNotNull('city_id_carrier')->get(['id', 'city_id', 'city_id_carrier', 'price', 'return']),
            'local_cities' => City::orderBy('title')->get(['id', 'title']),
        ]);
    }

    /** Links one Afra city to one local city (or unlinks it when city_id is empty). */
    public function mapCity(Request $request)
    {
        $data = $request->validate([
            'afra_city_id' => 'required|integer|min:1',
            'city_id'      => 'nullable|integer|exists:cities,id',
        ]);

        return $this->attempt(function () use ($data) {
            $afraCity = collect($this->service->client($this->accountId())->getCities())
                ->first(fn ($city) => (int) ($city['id'] ?? 0) === (int) $data['afra_city_id']);
            if (!$afraCity) {
                throw new AfraException('Ville Afra inconnue.');
            }
            $this->service->linkCity($afraCity, $data['city_id'] ?? null);

            return ['success' => true];
        });
    }

    /** Links the chosen Afra cities by name; with create_missing, creates the local cities that do not exist. */
    public function autoLinkCities(Request $request)
    {
        $data = $request->validate([
            'afra_city_ids'   => 'required|array|min:1|max:1000',
            'afra_city_ids.*' => 'integer|min:1',
            'create_missing'  => 'sometimes|boolean',
        ]);

        return $this->attempt(fn () => $this->service->autoLinkCities(
            $this->accountId(),
            $data['afra_city_ids'],
            (bool) ($data['create_missing'] ?? false)
        ));
    }

    /** Changes found in Afra's city list: the open ones, then the last handled ones. */
    public function cityChanges()
    {
        $changes = AfraCityChange::open()->latest('id')->get()
            ->concat(AfraCityChange::whereNotNull('resolved_at')->latest('resolved_at')->limit(20)->get());
        $names = AfraCity::whereIn('id', $changes->pluck('afra_city_id'))->pluck('name', 'id');
        $links = DefaultCarrier::with('city:id,title')->where('carrier_id', AfraShippingClient::carrierId())
            ->whereIn('city_id_carrier', $changes->pluck('afra_city_id'))->get()->keyBy('city_id_carrier');

        return response()->json([
            'last_check' => AfraCity::max('updated_at'),
            'changes'    => $changes->map(fn ($change) => [
                'id'           => $change->id,
                'afra_city_id' => $change->afra_city_id,
                'afra_name'    => $names->get($change->afra_city_id) ?? $change->old_value,
                'type'         => $change->type,
                'old_value'    => $change->old_value,
                'new_value'    => $change->new_value,
                'auto_action'  => $change->auto_action,
                'resolved_at'  => $change->resolved_at,
                'created_at'   => $change->created_at,
                'local_city'   => $links->get($change->afra_city_id)?->city?->only('id', 'title'),
                'local_price'  => $links->get($change->afra_city_id)?->price,
            ])->values(),
        ]);
    }

    /** Runs the daily comparison now. */
    public function checkCities(AfraCityWatcher $watcher)
    {
        return $this->attempt(fn () => $watcher->check());
    }

    public function resolveCityChange(int $id)
    {
        AfraCityChange::open()->findOrFail($id)->update(['resolved_at' => now(), 'resolved_by' => getAccountUser()->id]);

        return response()->json(['success' => true]);
    }

    /** Copies Afra's new delivery price into the local Afra tariff of that city. */
    public function applyCityPrice(int $id)
    {
        $change = AfraCityChange::open()->where('type', AfraCityChange::PRICE)->findOrFail($id);
        $updated = DefaultCarrier::where('carrier_id', AfraShippingClient::carrierId())
            ->where('city_id_carrier', $change->afra_city_id)->update(['price' => (float) $change->new_value]);
        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Cette ville Afra n’est associée à aucune ville locale.'], 422);
        }
        $change->update(['auto_action' => 'price_applied', 'resolved_at' => now(), 'resolved_by' => getAccountUser()->id]);

        return response()->json(['success' => true, 'message' => 'Nouveau prix appliqué.']);
    }

    // endregion

    // region statuses

    /** Afra statuses, the status comments they can map to, and the current mapping. */
    public function statuses()
    {
        return $this->attempt(fn () => [
            'statuses' => $this->service->client($this->accountId())->getStatuses(),
            'comments' => $this->statusComments(),
            'mappings' => AfraStatusMapping::where('account_id', $this->accountId())
                ->where(fn ($q) => $q->whereNotNull('comment_id')->orWhere('is_return', true))
                ->get(['afra_status_id', 'comment_id', 'is_return']),
        ]);
    }

    /**
     * Sets what an Afra status does: the comment to apply (comment_id, null to remove) and/or whether
     * it means the parcel is coming back (is_return). Only the fields sent are changed.
     */
    public function mapStatus(Request $request)
    {
        $data = $request->validate([
            'afra_status_id' => 'required|integer|min:1',
            'comment_id'     => 'sometimes|nullable|integer',
            'is_return'      => 'sometimes|boolean',
        ]);

        return $this->attempt(function () use ($data) {
            $known = collect($this->service->client($this->accountId())->getStatuses())
                ->contains(fn ($s) => (int) ($s['id'] ?? 0) === (int) $data['afra_status_id']);
            if (!$known) {
                throw new AfraException('Statut Afra inconnu.');
            }
            if (!empty($data['comment_id']) && !$this->statusComments()->contains('id', (int) $data['comment_id'])) {
                throw new AfraException('Commentaire de statut inconnu.');
            }

            $mapping = AfraStatusMapping::firstOrNew(['account_id' => $this->accountId(), 'afra_status_id' => $data['afra_status_id']]);
            if (array_key_exists('comment_id', $data)) {
                $mapping->comment_id = $data['comment_id'] ?: null;
                $mapping->order_status_id = null;
            }
            if (array_key_exists('is_return', $data)) {
                $mapping->is_return = (bool) $data['is_return'];
            }

            if (!$mapping->comment_id && !$mapping->is_return) {
                $mapping->exists && $mapping->delete();
            } else {
                $mapping->save();
            }

            return ['success' => true];
        });
    }

    /**
     * Status comments an agent can pick (global or this account's), with the status they lead to.
     * Comments leading to Paid / Returned are left out: only a payment or return slip may set those,
     * since the slip also records the money or brings the stock back.
     */
    private function statusComments()
    {
        $statuses = OrderStatus::pluck('title', 'id');
        $slipOnly = [AfraShippingService::PAID_STATUS, AfraShippingService::RETURNED_STATUS];

        return Comment::with('parentComment:id,title,current_statut')
            ->whereNotNull('comment_id')->where('statut', '!=', 0)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $this->accountId()))
            ->orderBy('comment_id')->orderBy('title')
            ->get(['id', 'title', 'statut', 'new_statut', 'comment_id'])
            ->map(function ($comment) use ($statuses) {
                $statusId = (int) $comment->statut === 2 ? null : ($comment->new_statut ?: $comment->parentComment?->current_statut);

                return [
                    'id'           => $comment->id,
                    'title'        => $comment->title,
                    'group_id'     => $comment->comment_id,
                    'group'        => $comment->parentComment?->title,
                    'status_id'    => $statusId ? (int) $statusId : null,
                    'status_title' => $statusId ? $statuses->get($statusId) : null,
                ];
            })
            ->reject(fn ($c) => in_array($c['status_id'], $slipOnly, true))
            ->values();
    }

    // endregion

    // region actions

    public function syncPickup(int $pickupId)
    {
        $pickup = Pickup::with('accountUser')->find($pickupId);
        if (!$pickup || (int) $pickup->carrier_id !== AfraShippingClient::carrierId() || (int) $pickup->accountUser?->account_id !== $this->accountId()) {
            return response()->json(['success' => false, 'message' => 'Ramassage Afra introuvable pour ce compte.'], 404);
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

    /** Sends one order again after a refused or unanswered creation. */
    public function resendOrder(int $id)
    {
        $order = $this->afraOrder($id);
        if ($order->shipping_code) {
            return response()->json(['success' => false, 'message' => 'Commande déjà chez Afra ('.$order->shipping_code.').'], 422);
        }

        return $this->attempt(function () use ($order) {
            $outcome = $this->service->create($order, getAccountUser()->id, $this->service->client($this->accountId()));
            $operation = AfraOrderOperation::where('order_id', $order->id)->first();

            return [
                'success'       => $outcome === 'synchronized',
                'shipping_code' => $order->fresh()->shipping_code,
                'message'       => $outcome === 'synchronized' ? 'Commande envoyée à Afra.' : ($operation?->last_error ?: 'Commande non envoyée.'),
            ];
        });
    }

    public function retryReturn(int $id)
    {
        $order = $this->afraOrder($id);
        if ((int) $order->order_status_id !== AfraShippingService::RETURN_STATUS) {
            return response()->json(['success' => false, 'message' => 'La commande n’est pas en souffrance.'], 422);
        }

        return response()->json($this->service->requestReturn($order, getAccountUser()->id, true));
    }

    /** "Rechercher chez Afra": finds the Afra number of orders sent without one (preview unless apply). */
    public function matchCodes(Request $request)
    {
        $data = $request->validate([
            'pickup_id' => 'nullable|integer',
            'apply'     => 'sometimes|boolean',
        ]);
        // Reads up to 50 pages of Afra's list (≈ 30 s for the whole list).
        set_time_limit(180);

        return $this->attempt(fn () => $this->service->matchMissingCodes(
            $this->accountId(), $data['pickup_id'] ?? null, (bool) ($data['apply'] ?? false), getAccountUser()->id
        ));
    }

    // endregion

    // region returns, payments, tracking

    public function tracking(AfraOperationsService $operations)
    {
        return $this->attempt(fn () => $operations->tracking($this->accountId()));
    }

    public function returns(AfraOperationsService $operations)
    {
        return $this->attempt(fn () => ['orders' => $operations->returnsToReceive($this->accountId())]);
    }

    public function lookupReturn(Request $request, AfraOperationsService $operations)
    {
        $data = $request->validate(['code' => 'required|string|max:64']);

        return $this->attempt(fn () => $operations->lookupReturn($this->accountId(), $data['code']));
    }

    public function receiveReturns(Request $request, AfraOperationsService $operations)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|integer',
            'order_ids'    => 'required|array|min:1',
            'order_ids.*'  => 'integer',
            'title'        => 'nullable|string|max:255',
        ]);

        return $this->attempt(fn () => $operations->receiveReturns(
            $this->accountId(), $data['warehouse_id'], $data['order_ids'], $data['title'] ?? null
        ));
    }

    public function reconcilePayment(Request $request, AfraOperationsService $operations)
    {
        $data = $request->validate(['text' => 'required|string|max:500000']);

        return $this->attempt(fn () => $operations->reconcilePayment($this->accountId(), $data['text']));
    }

    public function createPayment(Request $request, AfraOperationsService $operations)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|integer',
            'lines'        => 'required|array|min:1',
            'lines.*.id'   => 'required|integer',
            'lines.*.fee'  => 'required|numeric|min:0',
            'received'     => 'required|numeric|min:0',
            'title'        => 'nullable|string|max:255',
        ]);

        return $this->attempt(fn () => $operations->createPayment(
            $this->accountId(), $data['warehouse_id'], $data['lines'], (float) $data['received'], $data['title'] ?? null
        ));
    }

    // endregion

    private function startRun(string $kind, ?int $pickupId = null)
    {
        $existing = AfraSyncRun::where('account_id', $this->accountId())->where('kind', $kind)
            ->where('pickup_id', $pickupId)->active()->first();
        if ($existing) {
            return response()->json(['success' => true, 'run_id' => $existing->id, 'message' => 'Synchronisation déjà en cours.']);
        }

        $run = AfraSyncRun::create([
            'account_id'      => $this->accountId(),
            'account_user_id' => getAccountUser()->id,
            'pickup_id'       => $pickupId,
            'kind'            => $kind,
            'status'          => 'queued',
        ]);
        SyncAfraShippingJob::dispatch($run->id);

        return response()->json(['success' => true, 'run_id' => $run->id, 'message' => 'Synchronisation Afra lancée (elle démarre dans la minute).'], 202);
    }

    /** Runs an Afra call and turns its errors into a 422 with a readable message. */
    private function attempt(callable $call)
    {
        try {
            return response()->json($call());
        } catch (AfraException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function afraOrder(int $id): Order
    {
        return Order::where('account_id', $this->accountId())
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', AfraShippingClient::carrierId()))
            ->findOrFail($id);
    }

    private function accountId(): int
    {
        return (int) getAccountUser()->account_id;
    }

    private function link(): ?AccountCarrier
    {
        return AccountCarrier::where('account_id', $this->accountId())->where('carrier_id', AfraShippingClient::carrierId())->first();
    }
}
