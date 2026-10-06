<?php

namespace App\Services;

use App\Models\AccountCarrier;
use App\Models\AccountUser;
use App\Models\AfraCity;
use App\Models\AfraCityChange;
use App\Notifications\AfraCitiesChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Afra has no webhook: its city list is compared every day with the copy kept in afra_cities.
 * New cities are linked to the local city of the same name; everything else (new city without a
 * match, renamed, new price, removed) is listed in Afra settings → Villes and announced in the bell.
 */
class AfraCityWatcher
{
    public function __construct(private readonly AfraShippingService $service)
    {
    }

    /** @return array{first_run: bool, added: int, linked: int, renamed: int, price: int, removed: int} */
    public function check(): array
    {
        $link = AccountCarrier::where('carrier_id', AfraShippingClient::carrierId())
            ->whereNotNull('username')->whereNotNull('password')->first();
        if (!$link) {
            throw new AfraException('Aucun compte Afra configuré.');
        }

        Cache::forget('afra_get-cities');
        $remote = collect($this->service->client((int) $link->account_id)->getCities())
            ->filter(fn ($c) => !empty($c['id']) && isset($c['name']))
            ->keyBy(fn ($c) => (int) $c['id']);
        if ($remote->isEmpty()) {
            // Never read an empty answer as "every city was removed".
            throw new AfraException('Afra a renvoyé une liste de villes vide.');
        }

        $known = AfraCity::all()->keyBy('id');
        $firstRun = $known->isEmpty();
        $changes = collect();

        DB::transaction(function () use ($remote, $known, $firstRun, $changes) {
            $record = fn (int $id, string $type, $old, $new) => $changes->push(AfraCityChange::create([
                'afra_city_id' => $id, 'type' => $type, 'old_value' => $old, 'new_value' => $new,
            ]));

            foreach ($remote as $id => $city) {
                $name = trim((string) $city['name']);
                $price = isset($city['delivery_price']) ? round((float) $city['delivery_price'], 2) : null;
                $old = $known->get($id);

                if (!$old) {
                    AfraCity::create(['id' => $id, 'name' => $name, 'delivery_price' => $price]);
                    if (!$firstRun) {
                        $record($id, AfraCityChange::ADDED, null, $name);
                    }
                    continue;
                }

                if ($old->removed_at) {
                    $record($id, AfraCityChange::ADDED, null, $name);
                }
                if ($old->name !== $name) {
                    $record($id, AfraCityChange::RENAMED, $old->name, $name);
                }
                if ($price !== null && $old->delivery_price !== null && abs($old->delivery_price - $price) >= 0.01) {
                    $record($id, AfraCityChange::PRICE, (string) $old->delivery_price, (string) $price);
                }
                $old->update(['name' => $name, 'delivery_price' => $price ?? $old->delivery_price, 'removed_at' => null]);
            }

            foreach ($known as $id => $old) {
                if (!$remote->has($id) && !$old->removed_at) {
                    $old->update(['removed_at' => now()]);
                    $record($id, AfraCityChange::REMOVED, $old->name, null);
                }
            }
        });

        $linked = $this->linkNewCities($changes->where('type', AfraCityChange::ADDED), (int) $link->account_id);

        $counts = [
            'first_run' => $firstRun,
            'added'     => $changes->where('type', AfraCityChange::ADDED)->count(),
            'linked'    => $linked,
            'renamed'   => $changes->where('type', AfraCityChange::RENAMED)->count(),
            'price'     => $changes->where('type', AfraCityChange::PRICE)->count(),
            'removed'   => $changes->where('type', AfraCityChange::REMOVED)->count(),
        ];
        if ($changes->isNotEmpty()) {
            $this->notify($counts);
        }

        return $counts;
    }

    /** New Afra cities whose name exists locally are linked and their change closed. */
    private function linkNewCities($added, int $accountId): int
    {
        if ($added->isEmpty()) {
            return 0;
        }

        $report = $this->service->autoLinkCities($accountId, $added->pluck('afra_city_id')->all(), false);
        $linkedNames = collect($report['linked'])->map(fn ($n) => mb_strtolower($n));

        $count = 0;
        foreach ($added as $change) {
            if ($linkedNames->contains(mb_strtolower((string) $change->new_value))) {
                $change->update(['auto_action' => 'linked', 'resolved_at' => now()]);
                $count++;
            }
        }

        return $count;
    }

    /** One bell notification for every active user of the accounts that use Afra. */
    private function notify(array $counts): void
    {
        $accountIds = AccountCarrier::where('carrier_id', AfraShippingClient::carrierId())
            ->whereNotNull('username')->pluck('account_id');
        $users = AccountUser::whereIn('account_id', $accountIds)->where('statut', 1)->get();

        Notification::send($users, new AfraCitiesChanged($counts));
    }
}
