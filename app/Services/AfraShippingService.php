<?php

namespace App\Services;

use App\Models\AccountCarrier;
use App\Models\AccountUser;
use App\Models\AfraOrderOperation;
use App\Models\AfraStatusMapping;
use App\Models\AfraSyncRun;
use App\Models\City;
use App\Models\Comment;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\Pickup;
use App\Notifications\AppAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * What the app does with Afra: send the orders of an Afra pickup, push later changes, ask for a
 * return, and bring Afra's statuses back as status comments. HTTP lives in AfraShippingClient.
 */
class AfraShippingService
{
    /** Status that makes the app ask Afra to send the parcel back (chosen by the business). */
    public const RETURN_STATUS = 9;

    /** Only orders "En cours" (in delivery) are updated from Afra's statuses (business choice). */
    public const SYNCED_STATUSES = [6];

    public const DELIVERED_STATUS = 7;
    public const CANCELLED_STATUS = 8;
    /** Only a payment slip (FL) / a return slip (FR) may set these: they carry the money / the stock. */
    public const PAID_STATUS = 10;
    public const RETURNED_STATUS = 11;

    /** Afra statuses before the parcel is picked up: the order can still be deleted at Afra. */
    private const BEFORE_PICKUP = ['en attente', 'confirmation en attente', 'confirme', 'reconfirme', 'en preparation'];

    /** How many Afra pages to read at most when looking for an order created without a clear answer. */
    private const MATCH_PAGES = 5;

    /** How many Afra pages "Rechercher chez Afra" reads at most (100 orders per page). */
    private const SEARCH_PAGES = 50;

    /** How many of the newest pages the status sync reads to find "En cours" orders without number. */
    private const SEARCH_PAGES_IN_SYNC = 10;

    /** Safety stop when reading the whole order list (100 orders per page). */
    private const MAX_PAGES = 500;

    public function client(int $accountId): AfraShippingClient
    {
        return new AfraShippingClient($accountId);
    }

    public static function isAfraOrder(Order $order): bool
    {
        return (int) $order->pickup?->carrier_id === AfraShippingClient::carrierId();
    }

    // region settings

    public function settings(int $accountId): array
    {
        $link = AccountCarrier::where('account_id', $accountId)->where('carrier_id', AfraShippingClient::carrierId())->first();

        return ['test_product' => ($link?->settings['test_product'] ?? false) === true];
    }

    // endregion

    // region cities

    /**
     * Links an Afra city ['id', 'name', 'delivery_price'] to a local city, or unlinks it (null).
     * A local city without an Afra tariff row gets one, priced with Afra's delivery price
     * (also used as return price until it is edited in the carrier's tariffs).
     */
    public function linkCity(array $afraCity, ?int $cityId): void
    {
        $afraId = (int) $afraCity['id'];

        DB::transaction(function () use ($afraCity, $afraId, $cityId) {
            $rows = DefaultCarrier::where('carrier_id', AfraShippingClient::carrierId())->lockForUpdate()->get();
            $target = $cityId ? $rows->firstWhere('city_id', $cityId) : null;
            if ($target?->city_id_carrier && (int) $target->city_id_carrier !== $afraId) {
                throw new AfraException('Cette ville locale est déjà associée à une autre ville Afra.');
            }

            $rows->where('city_id_carrier', $afraId)->each->update(['city_id_carrier' => null]);
            if (!$cityId) {
                return;
            }
            if ($target) {
                $target->update(['city_id_carrier' => $afraId]);

                return;
            }

            $price = (float) ($afraCity['delivery_price'] ?? 0);
            DefaultCarrier::create([
                'carrier_id'      => AfraShippingClient::carrierId(),
                'city_id'         => $cityId,
                'city_id_carrier' => $afraId,
                'name'            => (string) ($afraCity['name'] ?? ''),
                'price'           => $price,
                'return'          => $price,
                'delivery_time'   => 24,
                'statut'          => 1,
            ]);
        });
    }

    /**
     * Links the given Afra cities to the local city with the same name (accents, case and
     * punctuation ignored). With $createMissing, a city that does not exist locally is created.
     *
     * @return array{linked: string[], created: string[], skipped: array<int, array{name: string, reason: string}>}
     */
    public function autoLinkCities(int $accountId, array $afraIds, bool $createMissing): array
    {
        $afraCities = collect($this->client($accountId)->getCities())->keyBy(fn ($c) => (int) $c['id']);
        $links = DefaultCarrier::where('carrier_id', AfraShippingClient::carrierId())->whereNotNull('city_id_carrier')
            ->pluck('city_id_carrier', 'city_id')->map(fn ($id) => (int) $id);
        $locals = City::get(['id', 'title'])->groupBy(fn ($c) => self::cityKey($c->title))->map->first();

        $report = ['linked' => [], 'created' => [], 'skipped' => []];
        foreach (array_unique(array_map('intval', $afraIds)) as $afraId) {
            $afraCity = $afraCities->get($afraId);
            $name = (string) ($afraCity['name'] ?? '#'.$afraId);
            $skip = function (string $reason) use (&$report, $name) {
                $report['skipped'][] = ['name' => $name, 'reason' => $reason];
            };

            if (!$afraCity) {
                $skip('ville Afra inconnue');
                continue;
            }
            if ($links->contains($afraId)) {
                $skip('déjà associée');
                continue;
            }

            $local = $locals->get(self::cityKey($name));
            if ($local && $links->has($local->id)) {
                $skip('« '.$local->title.' » est déjà associée à une autre ville Afra');
                continue;
            }
            if (!$local && !$createMissing) {
                $skip('aucune ville locale de ce nom');
                continue;
            }
            if (!$local) {
                $local = City::create(['title' => trim($name), 'statut' => 1]);
                $locals->put(self::cityKey($name), $local);
                $report['created'][] = $name;
            } else {
                $report['linked'][] = $name;
            }

            $this->linkCity($afraCity, $local->id);
            $links->put($local->id, $afraId);
        }

        return $report;
    }

    /** "Fès", "fes" and "FES -" are the same city name. */
    public static function cityKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))));
    }

    // endregion

    // region outgoing order data

    /** create-order / update-order body for a local order. */
    public function payload(Order $order): array
    {
        $order->loadMissing(['customer.activePhones', 'customer.activeAddresses', 'activePhones', 'activeAddresses', 'activePvas.product', 'activePvas.variationAttribute.childVariationAttributes.attribute']);
        $address = $order->activeAddresses->first() ?: $order->customer?->activeAddresses->first();
        $phone = $order->activePhones->first() ?: $order->customer?->activePhones->first();

        $cityId = $order->city_id ?: $address?->city_id;
        $afraCityId = DefaultCarrier::where('carrier_id', AfraShippingClient::carrierId())->where('city_id', $cityId)
            ->whereNull('deleted_at')->value('city_id_carrier');
        if (!$afraCityId) {
            throw new AfraException('Ville non associée à une ville Afra (Paramètres Afra → Villes).');
        }
        if (!$order->customer?->name || !$phone?->title || !$address?->title) {
            throw new AfraException('Client, téléphone ou adresse manquant.');
        }

        return [
            // The order code travels in the client name (same convention as the Excel export), so an
            // order whose creation got no clear answer can be found again in Afra's list.
            'client_name'         => self::clientName($order),
            'client_phone_number' => $phone->title,
            'city_id'             => (int) $afraCityId,
            'client_address'      => $address->title,
            'order_type'          => 'normal',
            'test_product'        => $this->settings((int) $order->account_id)['test_product'] ? 'yes' : 'no',
            'products'            => $this->products($order),
            'note'                => (string) ($order->note ?? ''),
        ];
    }

    public static function clientName(Order $order): string
    {
        return trim((string) $order->customer?->name).' - '.$order->code;
    }

    /**
     * Afra has no discount field: the amount to collect is the sum of the product lines. The discount
     * (and any shipping charged to the customer) is spread over the lines in proportion to their value,
     * in cents. When a line total does not divide by its quantity, one unit carries the remaining cents.
     */
    private function products(Order $order): array
    {
        $lines = $order->activePvas->filter(fn ($pva) => (int) $pva->pivot->quantity > 0)->values();
        if ($lines->isEmpty()) {
            throw new AfraException('Aucun produit actif à envoyer.');
        }

        $cents = fn ($value) => (int) round((float) $value * 100);
        $lineValues = $lines->map(fn ($pva) => $cents($pva->pivot->price) * (int) $pva->pivot->quantity);
        $gross = $lineValues->sum();
        $target = $gross - $cents($order->discount) + $cents($order->carrier_price);
        if ($target < 0) {
            throw new AfraException('La remise dépasse le total de la commande.');
        }

        $products = [];
        $allocated = 0;
        foreach ($lines as $i => $pva) {
            $quantity = (int) $pva->pivot->quantity;
            $lineCents = $i === $lines->count() - 1
                ? $target - $allocated
                : ($gross > 0 ? intdiv($target * $lineValues[$i], $gross) : intdiv($target, $lines->count()));
            $allocated += $lineCents;

            $name = self::productName($pva);
            $unit = intdiv($lineCents, $quantity);
            $extra = $lineCents - $unit * $quantity;
            if ($extra === 0) {
                $products[] = ['product_name' => $name, 'quantity' => $quantity, 'unit_price' => $unit / 100];
                continue;
            }
            if ($quantity > 1) {
                $products[] = ['product_name' => $name, 'quantity' => $quantity - 1, 'unit_price' => $unit / 100];
            }
            $products[] = ['product_name' => $name, 'quantity' => 1, 'unit_price' => ($unit + $extra) / 100];
        }

        return $products;
    }

    /**
     * Product name as the courier sees it: title then the variation values, e.g. "Sneaker 103 42 Noir".
     * Needs activePvas.product and activePvas.variationAttribute.childVariationAttributes.attribute.
     */
    public static function productName($pva): string
    {
        $values = $pva->variationAttribute?->childVariationAttributes
            ?->map(fn ($child) => trim((string) $child->attribute?->title))->filter()->all() ?? [];

        return trim(implode(' ', [$pva->product?->title ?: 'Produit', ...$values]));
    }

    /** Changes only to what Afra receives (status and local-only fields are left out). */
    public function fingerprint(Order $order): string
    {
        $order->loadMissing(['customer.activePhones', 'customer.activeAddresses', 'activePhones', 'activeAddresses', 'activePvas.product', 'activePvas.variationAttribute.childVariationAttributes.attribute']);

        return hash('sha256', json_encode([
            $order->customer?->name,
            $order->activePhones->first()?->title ?: $order->customer?->activePhones->first()?->title,
            $order->activeAddresses->first()?->title ?: $order->customer?->activeAddresses->first()?->title,
            $order->city_id,
            $order->discount,
            $order->carrier_price,
            $order->note,
            $order->activePvas->map(fn ($pva) => [$pva->id, $pva->pivot->quantity, $pva->pivot->price])->all(),
        ]));
    }

    // endregion

    // region create / update / return

    /**
     * Sends one order to Afra and stores the Afra number as shipping_code.
     * The operation row makes it safe against double clicks and parallel runs: an order is only
     * sent when it was never sent or its last attempt was refused.
     */
    public function create(Order $order, int $actorId, AfraShippingClient $client): string
    {
        if ($order->shipping_code) {
            return 'skipped';
        }

        $operation = AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        // "accepted": Afra has it but gave no number. It is looked for, never sent again.
        if (in_array($operation->create_state, ['uncertain', 'accepted'], true)
            && $this->resolveUncertain($order, $client, $operation->create_state === 'uncertain')) {
            return 'synchronized';
        }

        $claimed = AfraOrderOperation::whereKey($operation->id)
            ->where(fn ($q) => $q->whereNull('create_state')->orWhere('create_state', 'failed'))
            ->update(['create_state' => 'pending', 'create_attempted_at' => now()]);
        if (!$claimed) {
            return 'skipped';
        }
        $operation->refresh(); // the claim changed create_state in the database

        try {
            $number = $client->createOrder($this->payload($order));
        } catch (AfraNumberMissingException $e) {
            // The order exists at Afra: look for it now, and never send it twice.
            $operation->update(['create_state' => 'accepted', 'last_error' => $e->getMessage()]);
            if ($this->resolveUncertain($order, $client, false)) {
                $this->linkExchange($order, (string) Order::whereKey($order->id)->value('shipping_code'), $actorId, $client);

                return 'synchronized';
            }
            $this->comment($order, $actorId, 'Afra: commande acceptée sans numéro et pas encore visible chez Afra, à retrouver avec « Rechercher chez Afra ».');

            return 'failed';
        } catch (\Throwable $e) {
            $state = $e instanceof AfraUncertainException ? 'uncertain' : 'failed';
            $operation->update(['create_state' => $state, 'last_error' => $e->getMessage()]);
            $this->comment($order, $actorId, $state === 'uncertain'
                ? 'Afra: envoi sans réponse claire, vérification au prochain envoi. '.$e->getMessage()
                : 'Afra: envoi refusé. '.$e->getMessage());

            return 'failed';
        }

        Order::whereKey($order->id)->whereNull('shipping_code')->update(['shipping_code' => $number]);
        $operation->update(['create_state' => 'sent', 'last_error' => null]);
        $this->linkExchange($order, $number, $actorId, $client);

        return 'synchronized';
    }

    /**
     * An uncertain creation may exist at Afra. Look for it (by "Name - CODE") in the newest pages:
     * found → store its number; not found → mark it failed so it can be sent again, unless Afra
     * said it accepted it ($failIfMissing = false: it stays uncertain, never sent twice).
     */
    public function resolveUncertain(Order $order, AfraShippingClient $client, bool $failIfMissing = true): bool
    {
        $name = mb_strtolower(self::clientName($order));
        foreach ($this->pagesNewestFirst($client, self::MATCH_PAGES) as $data) {
            foreach ($data['orders'] ?? [] as $remote) {
                if (mb_strtolower(trim((string) ($remote['client'] ?? ''))) === $name && !empty($remote['number'])) {
                    Order::whereKey($order->id)->whereNull('shipping_code')->update(['shipping_code' => (string) $remote['number']]);
                    AfraOrderOperation::where('order_id', $order->id)->update([
                        'create_state' => 'sent', 'last_error' => null,
                        'remote_status' => $remote['status'] ?? null, 'remote_status_at' => now(),
                    ]);

                    return true;
                }
            }
        }
        if ($failIfMissing) {
            AfraOrderOperation::where('order_id', $order->id)->update(['create_state' => 'failed']);
        }

        return false;
    }

    /**
     * Afra's order list is sorted oldest first: recent orders are on the last pages. Yields at most
     * $maxPages pages of 100 orders, from the last one backwards.
     */
    private function pagesNewestFirst(AfraShippingClient $client, int $maxPages): \Generator
    {
        $first = $client->getOrders(1, 100);
        $last = max(1, (int) ($first['pagination']['last_page'] ?? 1));
        for ($page = $last, $read = 0; $page >= 1 && $read < $maxPages; $page--, $read++) {
            yield $page === 1 ? $first : $client->getOrders($page, 100);
        }
    }

    /** Pushes an edited order (customer, address, products, totals) to Afra. */
    public function update(Order $order, int $actorId): array
    {
        try {
            $this->client((int) $order->account_id)->updateOrder((string) $order->shipping_code, $this->payload($order));

            return ['success' => true, 'message' => 'Modification envoyée à Afra.'];
        } catch (\Throwable $e) {
            $this->comment($order, $actorId, 'Afra: modification non envoyée. '.$e->getMessage());

            return ['success' => false, 'message' => 'Enregistré localement, mais Afra n’a pas reçu la modification: '.$e->getMessage()];
        }
    }

    /** Asks Afra to send the parcel back. Sent once; a refused request can be retried by hand. */
    public function requestReturn(Order $order, int $actorId, bool $manualRetry = false): array
    {
        if (!$order->shipping_code || !self::isAfraOrder($order)) {
            return ['success' => false, 'message' => 'Commande Afra non éligible.'];
        }

        AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        $claim = AfraOrderOperation::where('order_id', $order->id);
        $manualRetry ? $claim->whereIn('return_state', ['failed', 'uncertain']) : $claim->whereNull('return_state');
        if (!$claim->update(['return_state' => 'pending'])) {
            return ['success' => false, 'message' => 'Demande de retour déjà envoyée.'];
        }

        try {
            $this->client((int) $order->account_id)->returnRequest((string) $order->shipping_code);
            AfraOrderOperation::where('order_id', $order->id)->update(['return_state' => 'sent', 'last_error' => null]);

            return ['success' => true, 'message' => 'Demande de retour envoyée à Afra.'];
        } catch (\Throwable $e) {
            $state = $e instanceof AfraUncertainException ? 'uncertain' : 'failed';
            AfraOrderOperation::where('order_id', $order->id)->update(['return_state' => $state, 'last_error' => $e->getMessage()]);
            $this->comment($order, $actorId, 'Afra: demande de retour non envoyée. '.$e->getMessage());

            return ['success' => false, 'message' => $state === 'uncertain'
                ? 'Afra n’a pas confirmé la demande de retour: vérifiez chez Afra avant de réessayer.'
                : 'Demande de retour refusée: '.$e->getMessage()];
        }
    }

    /**
     * The order left its Afra pickup or was cancelled: delete it at Afra while the parcel is not picked
     * up yet. Once Afra has it, nothing is sent: the history and the bell say it must be handled by hand.
     * $code is the Afra number before the local change (removing an order from a pickup may clear it).
     */
    public function cancelAtAfra(Order $order, string $code, int $actorId): array
    {
        $operation = AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        $remote = $operation->remote_status ? self::normalize($operation->remote_status) : null;
        if ($remote !== null && !in_array($remote, self::BEFORE_PICKUP, true)) {
            $message = 'Afra: commande annulée chez nous mais déjà prise en charge par Afra ('.$operation->remote_status.'). À régler avec Afra.';
            $this->comment($order, $actorId, $message);
            $this->alert((int) $order->account_id, 'Commande '.$order->code.' à annuler chez Afra', $message, '/dashboard/order/'.$order->id);

            return ['success' => false, 'message' => $message];
        }

        $claimed = AfraOrderOperation::whereKey($operation->id)
            ->where(fn ($q) => $q->whereNull('delete_state')->orWhere('delete_state', 'failed'))
            ->update(['delete_state' => 'pending']);
        if (!$claimed) {
            return ['success' => false, 'message' => 'Suppression chez Afra déjà demandée.'];
        }
        $operation->refresh(); // the claim changed delete_state in the database

        try {
            $this->client((int) $order->account_id)->deleteOrder($code);
            // The Afra number is dead: forget it, so the order is sent again if put back in an Afra pickup.
            Order::whereKey($order->id)->where('shipping_code', $code)->update(['shipping_code' => null]);
            $operation->update([
                'create_state' => null, 'create_attempted_at' => null, 'delete_state' => null, 'exchange_state' => null,
                'remote_status' => null, 'remote_status_at' => null, 'missing_since' => null, 'last_error' => null,
            ]);
            $this->comment($order, $actorId, 'Afra: commande '.$code.' supprimée chez Afra.');

            return ['success' => true, 'message' => 'Commande supprimée chez Afra.'];
        } catch (\Throwable $e) {
            $state = $e instanceof AfraUncertainException ? 'uncertain' : 'failed';
            $operation->update(['delete_state' => $state, 'last_error' => $e->getMessage()]);
            $message = 'Afra: suppression de '.$code.' non confirmée. '.$e->getMessage();
            $this->comment($order, $actorId, $message);
            $this->alert((int) $order->account_id, 'Commande '.$order->code.' à supprimer chez Afra', $message, '/dashboard/order/'.$order->id);

            return ['success' => false, 'message' => $message];
        }
    }

    /**
     * Order id → Afra number, for the given orders that are in an Afra pickup with an Afra number.
     * Read before taking orders out of a pickup (that clears the pickup, so the link to Afra is lost).
     */
    public static function afraCodes(array $orderIds): array
    {
        return Order::whereIn('id', $orderIds)->whereNotNull('shipping_code')
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', AfraShippingClient::carrierId()))
            ->pluck('shipping_code', 'id')->all();
    }

    /** Orders taken out of an Afra pickup (or whose pickup was deleted): delete them at Afra. */
    public function cancelRemovedOrders(array $codes, int $actorId): array
    {
        $results = [];
        foreach ($codes as $orderId => $code) {
            $order = Order::find($orderId);
            if ($order) {
                $results[$orderId] = $this->cancelAtAfra($order, (string) $code, $actorId);
            }
        }

        return $results;
    }

    /**
     * An exchange order (a sale linked to an original order) was just created at Afra: tell Afra it
     * replaces the original parcel, so the courier swaps them at the customer's door.
     */
    private function linkExchange(Order $order, string $number, int $actorId, AfraShippingClient $client): void
    {
        $original = $order->order_id ? Order::with('pickup')->find($order->order_id) : null;
        if (($order->type ?? 'sale') !== 'sale' || !$original?->shipping_code || !self::isAfraOrder($original)) {
            return;
        }

        $operation = AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        if (!AfraOrderOperation::whereKey($operation->id)->whereNull('exchange_state')->update(['exchange_state' => 'pending'])) {
            return;
        }

        try {
            $client->createExchange((string) $original->shipping_code, $number);
            $operation->update(['exchange_state' => 'sent']);
            $this->comment($order, $actorId, 'Afra: échange déclaré ('.$original->shipping_code.' → '.$number.').');
        } catch (\Throwable $e) {
            $operation->update(['exchange_state' => $e instanceof AfraUncertainException ? 'uncertain' : 'failed', 'last_error' => $e->getMessage()]);
            $this->comment($order, $actorId, 'Afra: échange non déclaré ('.$original->shipping_code.' → '.$number.'). '.$e->getMessage());
        }
    }

    // endregion

    // region find orders sent without a code

    /**
     * Orders of Afra pickups that have no Afra number (sent by hand, or creation without answer):
     * finds them in Afra's list by the order code in the client name ("Name - CODE" / "Name-CODE"),
     * else by phone when exactly one order matches on each side. Nothing is written unless $apply.
     *
     * @return array{matched: array, ambiguous: array, not_found: array}
     */
    public function matchMissingCodes(int $accountId, ?int $pickupId, bool $apply, int $actorId): array
    {
        $targets = Order::where('account_id', $accountId)->whereNull('shipping_code')
            ->whereNotIn('order_status_id', [2, 3, self::CANCELLED_STATUS])
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', AfraShippingClient::carrierId()))
            ->when($pickupId, fn ($q) => $q->where('pickup_id', $pickupId))
            ->with(['customer.activePhones', 'activePhones'])
            ->get();
        $report = ['matched' => [], 'ambiguous' => [], 'not_found' => []];
        if ($targets->isEmpty()) {
            return $report;
        }

        $byCode = $targets->keyBy(fn ($o) => mb_strtolower((string) $o->code));
        $phoneOf = fn (Order $o) => self::phoneKey((string) ($o->activePhones->first()?->title ?: $o->customer?->activePhones->first()?->title));
        $usedNumbers = Order::where('account_id', $accountId)->whereNotNull('shipping_code')->pluck('shipping_code')->flip();

        $client = $this->client($accountId);
        $codeMatches = [];
        $remoteByPhone = [];
        foreach ($this->pagesNewestFirst($client, self::SEARCH_PAGES) as $data) {
            if (count($codeMatches) >= $targets->count()) {
                break;
            }
            foreach ($data['orders'] ?? [] as $remote) {
                $number = (string) ($remote['number'] ?? '');
                if ($number === '' || $usedNumbers->has($number)) {
                    continue;
                }
                $tokens = preg_split('/[\s\-]+/', mb_strtolower((string) ($remote['client'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $target = collect($tokens)->map(fn ($t) => $byCode->get($t))->filter()->first();
                if ($target && !isset($codeMatches[$target->id])) {
                    $codeMatches[$target->id] = ['order' => $target, 'remote' => $remote, 'via' => 'code'];
                    continue;
                }
                $phone = self::phoneKey((string) ($remote['phone_number'] ?? ''));
                if ($phone !== '') {
                    $remoteByPhone[$phone][] = $remote;
                }
            }
        }

        $localByPhone = $targets->reject(fn ($o) => isset($codeMatches[$o->id]))->groupBy($phoneOf);
        $matches = $codeMatches;
        foreach ($targets as $order) {
            if (isset($matches[$order->id])) {
                continue;
            }
            $phone = $phoneOf($order);
            $remotes = $remoteByPhone[$phone] ?? [];
            $line = ['order_id' => $order->id, 'code' => $order->code, 'customer' => $order->customer?->name];
            if ($phone !== '' && count($remotes) === 1 && $localByPhone->get($phone)?->count() === 1) {
                $matches[$order->id] = ['order' => $order, 'remote' => $remotes[0], 'via' => 'phone'];
            } elseif (count($remotes) > 1 || ($remotes && $localByPhone->get($phone)?->count() > 1)) {
                $report['ambiguous'][] = $line + ['reason' => count($remotes).' commande(s) Afra et '.$localByPhone->get($phone)->count().' commande(s) chez nous avec ce téléphone'];
            } else {
                $report['not_found'][] = $line;
            }
        }

        foreach ($matches as $match) {
            $order = $match['order'];
            $remote = $match['remote'];
            $report['matched'][] = [
                'order_id'    => $order->id,
                'code'        => $order->code,
                'customer'    => $order->customer?->name,
                'via'         => $match['via'],
                'afra_number' => (string) $remote['number'],
                'afra_client' => $remote['client'] ?? null,
                'afra_status' => $remote['status'] ?? null,
            ];
            if ($apply && Order::whereKey($order->id)->whereNull('shipping_code')->update(['shipping_code' => (string) $remote['number']])) {
                AfraOrderOperation::updateOrCreate(['order_id' => $order->id], [
                    'create_state' => 'sent', 'last_error' => null,
                    'remote_status' => $remote['status'] ?? null, 'remote_status_at' => now(),
                ]);
                $this->comment($order, $actorId, 'Afra: numéro '.$remote['number'].' retrouvé ('.($match['via'] === 'code' ? 'par le code' : 'par le téléphone').').');
            }
        }

        return $report;
    }

    /** Last 9 digits: "+212 6 12-34-56-78" and "0612345678" are the same phone. */
    public static function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    // endregion

    // region background runs

    public function syncPickup(AfraSyncRun $run): void
    {
        $pickup = Pickup::with(['orders', 'accountUser'])->findOrFail($run->pickup_id);
        if ((int) $pickup->carrier_id !== AfraShippingClient::carrierId() || (int) $pickup->accountUser?->account_id !== (int) $run->account_id) {
            throw new AfraException('Ce ramassage n’est pas un ramassage Afra de ce compte.');
        }

        $client = $this->client((int) $run->account_id);
        $client->link(); // fail the whole run early when the account has no credentials
        foreach ($pickup->orders as $order) {
            $outcome = (int) $order->account_id !== (int) $run->account_id
                ? 'skipped'
                : $this->create($order, (int) $run->account_user_id, $client);
            $run->increment('processed');
            $run->increment($outcome);
        }
    }

    /**
     * Reads Afra's order list and, for every local Afra order "En cours" (status 6) seen there, applies
     * its Afra status as the mapped status comment. "En cours" orders of Afra pickups that have no Afra
     * number yet are found on the way by their code in the client name ("Name - CODE") and get their
     * number and status. Statuses without a mapping are only recorded and reported.
     */
    public function syncStatuses(AfraSyncRun $run): void
    {
        $accountId = (int) $run->account_id;
        $client = $this->client($accountId);

        $inProgress = fn () => Order::where('account_id', $accountId)
            ->whereIn('order_status_id', self::SYNCED_STATUSES)->whereNull('shipment_id')
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', AfraShippingClient::carrierId()));
        $open = $inProgress()->whereNotNull('shipping_code')->pluck('id', 'shipping_code');
        $withoutNumber = $inProgress()->whereNull('shipping_code')->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => $id]);
        if ($open->isEmpty() && $withoutNumber->isEmpty()) {
            $run->update(['message' => 'Aucune commande Afra en cours.']);

            return;
        }
        $usedNumbers = Order::where('account_id', $accountId)->whereNotNull('shipping_code')->pluck('shipping_code')->flip();

        $statusIds = collect($client->getStatuses())
            ->mapWithKeys(fn ($s) => [self::normalize((string) ($s['fr_name'] ?? '')) => (int) ($s['id'] ?? 0)]);
        $comments = AfraStatusMapping::where('account_id', $accountId)->whereNotNull('comment_id')
            ->with('comment.parentComment')->get()->keyBy('afra_status_id');

        $remaining = $open->keys()->flip();
        $unmapped = [];
        $numbersFound = 0;
        $readWholeList = false;
        $interrupted = null;

        // Afra lists oldest first: "En cours" orders are on the last pages, so read from the end and stop
        // once they were all seen. Orders without number are only looked for in the newest pages.
        $first = $client->getOrders(1, 100);
        $lastPage = max(1, (int) ($first['pagination']['last_page'] ?? 1));
        for ($page = $lastPage, $read = 0; $page >= 1; $page--, $read++) {
            $lookingForNumbers = $withoutNumber->isNotEmpty() && $read < self::SEARCH_PAGES_IN_SYNC;
            if ($remaining->isEmpty() && !$lookingForNumbers) {
                break;
            }
            if ($read >= self::MAX_PAGES) {
                break;
            }
            try {
                $data = $page === 1 ? $first : $client->getOrders($page, 100);
            } catch (AfraUncertainException $e) {
                // keep what was already updated; the next run starts again from the newest pages
                $interrupted = 'lecture interrompue à la page '.$page.' ('.$e->getMessage().')';
                break;
            }

            foreach ($data['orders'] ?? [] as $remote) {
                $number = (string) ($remote['number'] ?? '');
                if ($number === '') {
                    continue;
                }

                if ($remaining->has($number)) {
                    $orderId = $open[$number];
                    $remaining->forget($number);
                } elseif ($lookingForNumbers && !$usedNumbers->has($number)
                    && ($orderId = $this->orderIdInClientName((string) ($remote['client'] ?? ''), $withoutNumber))) {
                    if (!Order::whereKey($orderId)->whereNull('shipping_code')->update(['shipping_code' => $number])) {
                        continue;
                    }
                    $withoutNumber = $withoutNumber->reject(fn ($id) => (int) $id === $orderId);
                    $usedNumbers->put($number, true);
                    AfraOrderOperation::updateOrCreate(['order_id' => $orderId], ['create_state' => 'sent', 'last_error' => null]);
                    $this->comment(Order::find($orderId), (int) $run->account_user_id, 'Afra: numéro '.$number.' retrouvé (par le code).');
                    $numbersFound++;
                } else {
                    continue;
                }

                $run->increment('processed');
                $status = trim((string) ($remote['status'] ?? ''));
                $comment = $comments->get($statusIds->get(self::normalize($status)))?->comment;
                $outcome = $this->applyRemoteStatus(Order::find($orderId), $status, $comment, (int) $run->account_user_id);
                if ($outcome === 'unmapped') {
                    $unmapped[$status ?: '(vide)'] = ($unmapped[$status ?: '(vide)'] ?? 0) + 1;
                    $outcome = 'skipped';
                }
                $run->increment($outcome);
            }

            if ($page === 1) {
                $readWholeList = true;
            }
        }

        // An order is "missing at Afra" only when the whole list was read without finding it.
        $found = $open->except($remaining->keys()->all())->values();
        AfraOrderOperation::whereIn('order_id', $found)->whereNotNull('missing_since')->update(['missing_since' => null]);
        if ($readWholeList && $remaining->isNotEmpty()) {
            foreach ($open->only($remaining->keys()->all()) as $orderId) {
                AfraOrderOperation::firstOrCreate(['order_id' => $orderId]);
            }
            AfraOrderOperation::whereIn('order_id', $open->only($remaining->keys()->all())->values())
                ->whereNull('missing_since')->update(['missing_since' => now()]);
        }

        $notes = [];
        if ($interrupted) {
            $notes[] = $interrupted;
        }
        if ($numbersFound) {
            $notes[] = $numbersFound.' numéro(s) Afra retrouvé(s)';
        }
        if ($unmapped) {
            $notes[] = 'Statuts Afra sans association (commandes non mises à jour): '
                .collect($unmapped)->map(fn ($n, $s) => $s.' ('.$n.')')->implode(', ');
        }
        if ($remaining->isNotEmpty()) {
            $notes[] = $remaining->count().' commande(s) introuvable(s) chez Afra';
        }
        if ($withoutNumber->isNotEmpty()) {
            $notes[] = $withoutNumber->count().' commande(s) sans numéro Afra (« Rechercher chez Afra » pour les retrouver par téléphone)';
        }
        $run->update(['message' => $notes ? implode(' · ', $notes) : null]);
    }

    /** The order whose code is a word of the Afra client name ("Name - CODE", "Name-CODE"). */
    private function orderIdInClientName(string $client, $idsByCode): ?int
    {
        foreach (preg_split('/[\s\-]+/', mb_strtolower($client), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if ($idsByCode->has($word)) {
                return (int) $idsByCode[$word];
            }
        }

        return null;
    }

    /**
     * Keeps the last Afra status of the order (used by the returns list and the order page), and applies
     * the mapped comment when this status was not applied yet: a status that had no mapping when it was
     * first seen is applied as soon as it gets one.
     */
    private function applyRemoteStatus(Order $order, string $status, ?Comment $comment, int $actorId): string
    {
        $operation = AfraOrderOperation::firstOrCreate(['order_id' => $order->id]);
        if ($operation->remote_status !== $status) {
            $operation->update(['remote_status' => $status, 'remote_status_at' => now()]);
        }
        if (!$comment) {
            return 'unmapped';
        }
        if ($operation->applied_status === $status) {
            return 'skipped';
        }

        $this->addStatusComment($order, $comment, 'Afra: '.$status, $actorId);
        $operation->update(['applied_status' => $status]);

        return 'synchronized';
    }

    /**
     * Same effect as an agent choosing this comment (OrderController::changeStatus): the comment is
     * added to the history and the order takes the comment's status. Done here so that statuses
     * coming from Afra never trigger the outgoing Afra calls (update / return request).
     */
    private function addStatusComment(Order $order, Comment $comment, string $title, int $actorId): void
    {
        if (!function_exists('calculateDayBasedScore')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }

        $statusId = (int) $comment->statut === 2
            ? (int) $order->order_status_id
            : (int) ($comment->new_statut ?: $comment->parentComment?->current_statut ?: $order->order_status_id);

        $comment->orders()->attach($order->id, [
            'title'           => mb_substr($title, 0, 255),
            'order_status_id' => $statusId,
            'account_user_id' => $actorId,
            'score'           => calculateDayBasedScore(Carbon::parse($order->created_at), now(), $order->id),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        if ($statusId !== (int) $order->order_status_id) {
            $order->update(['order_status_id' => $statusId]);
            $order->activeOrderPvas()->update(['order_status_id' => $statusId]);
        }
    }

    // endregion

    /** A note in the order history (no status change). */
    public function comment(Order $order, int $actorId, string $message): void
    {
        OrderComment::create([
            'order_id'        => $order->id,
            'account_user_id' => $actorId,
            'order_status_id' => $order->order_status_id,
            'title'           => mb_substr($message, 0, 255),
        ]);
    }

    /** Bell notification to every active user of the account. */
    public function alert(int $accountId, string $title, string $message, ?string $link = null): void
    {
        $users = AccountUser::where('account_id', $accountId)->where('statut', 1)->get();
        Notification::send($users, new AppAlert($title, $message, $link, 'Afra', 'delivery'));
    }

    /** "Livré" and "livre " are the same status name. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);
    }
}
