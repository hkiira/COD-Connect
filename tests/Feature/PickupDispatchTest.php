<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Creating a pickup from a batch of orders (the fulfillment screen): ownership and atomicity.
 * Runs against the development database inside a rolled-back transaction.
 */
class PickupDispatchTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $warehouseId;
    private int $carrierId;
    private array $ownOrderIds;
    private int $foreignOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->baseUrl = 'http://localhost';

        $user = User::where('email', self::EMAIL)->first();
        if (! $user || ! ($accountUser = $user->accountUsers()->first())) {
            $this->markTestSkipped('Reference user not available in this database.');
        }
        $this->accountId = (int) $accountUser->account_id;

        $warehouse = DB::table('warehouses')->where('account_id', $this->accountId)->where('warehouse_type_id', 1)
            ->where('statut', 1)->whereNull('warehouse_nature_id')->whereNull('warehouse_id')->whereNull('deleted_at')->value('id');
        $carrier = DB::table('carriers')->whereNull('deleted_at')->value('id');
        $own = DB::table('orders as o')->where('o.account_id', $this->accountId)->whereIn('o.order_status_id', [1, 4])->whereNull('o.pickup_id')
            ->whereNull('o.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_pva as op')->whereColumn('op.order_id', 'o.id')->whereNull('op.deleted_at'))
            ->orderByDesc('o.id')->limit(2)->pluck('o.id')->all();
        $foreign = DB::table('orders')->where('account_id', '!=', $this->accountId)->whereNull('deleted_at')->value('id');

        if (! $warehouse || ! $carrier || count($own) < 2 || ! $foreign) {
            $this->markTestSkipped('Needs a main warehouse, a carrier, two pending/confirmed orders with lines and a foreign order.');
        }

        [$this->warehouseId, $this->carrierId, $this->ownOrderIds, $this->foreignOrderId] = [(int) $warehouse, (int) $carrier, array_map('intval', $own), (int) $foreign];

        Passport::actingAs($user, [], 'api');
    }

    private function dispatchPayload(array $orders): array
    {
        return [[
            'warehouse_id' => $this->warehouseId,
            'carrier_id'   => $this->carrierId,
            'orders'       => $orders,
            'comment'      => 'TEST batch',
            'statut'       => 1,
        ]];
    }

    private function pickupCount(): int
    {
        return DB::table('pickups')->count();
    }

    public function test_a_foreign_order_cannot_be_put_in_a_pickup(): void
    {
        $pickups = $this->pickupCount();

        $this->postJson('/api/pickups', $this->dispatchPayload([$this->ownOrderIds[0], $this->foreignOrderId]))
            ->assertStatus(422)->assertJsonPath('statut', 0);

        $this->assertSame($pickups, $this->pickupCount(), 'a pickup was created despite the foreign order');
        $this->assertNull(DB::table('orders')->where('id', $this->ownOrderIds[0])->value('pickup_id'));
    }

    public function test_dispatch_is_atomic(): void
    {
        $pickups = $this->pickupCount();
        $statuses = DB::table('orders')->whereIn('id', $this->ownOrderIds)->pluck('order_status_id', 'id')->all();

        // the second order fails to save: the pickup and the first order must be left untouched
        $saved = 0;
        Order::updated(function () use (&$saved) {
            if (++$saved >= 2) {
                throw new \RuntimeException('simulated failure');
            }
        });

        $this->postJson('/api/pickups', $this->dispatchPayload($this->ownOrderIds))->assertStatus(500);

        $this->assertSame($pickups, $this->pickupCount(), 'pickup survived the failure');
        $this->assertSame($statuses, DB::table('orders')->whereIn('id', $this->ownOrderIds)->pluck('order_status_id', 'id')->all());
    }

    public function test_a_valid_dispatch_ships_every_order(): void
    {
        $this->postJson('/api/pickups', $this->dispatchPayload($this->ownOrderIds))->assertOk()->assertJsonPath('statut', 1);

        $this->assertSame(
            [6, 6],
            array_values(DB::table('orders')->whereIn('id', $this->ownOrderIds)->orderBy('id')->pluck('order_status_id')->map(fn ($s) => (int) $s)->all()),
            'orders are put in delivery by the pickup itself, no extra status call needed'
        );
        $this->assertSame(2, DB::table('orders')->whereIn('id', $this->ownOrderIds)->whereNotNull('pickup_id')->count());
    }

    public function test_the_orders_ready_for_a_pickup_load_when_a_history_row_has_no_agent(): void
    {
        $order = $this->ownOrderIds[0];
        DB::table('orders')->where('id', $order)->update(['order_status_id' => 4]);
        // a history row written by an agent that no longer exists (the live data has such rows)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_comment')->insert([
            'order_id' => $order, 'comment_id' => DB::table('comments')->value('id'), 'title' => 'TEST orphan',
            'order_status_id' => 4, 'account_user_id' => (int) DB::table('account_user')->max('id') + 1000, 'type' => 'comment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $rows = $this->getJson('/api/pickups/create?orders[inactive][pagination][per_page]=100')
            ->assertOk()->assertJsonPath('statut', 1)->json('data.orders.inactive.data');

        $this->assertTrue(collect($rows)->pluck('id')->contains($order));
    }
}
