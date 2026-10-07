<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Order update / delete rules: ownership, atomicity, deletable statuses.
 * Runs against the development database inside a rolled-back transaction.
 * Skipped when the reference user or suitable orders are missing.
 */
class OrderSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $ownOrderId;
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

        $own = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 1)->whereNull('deleted_at')->value('id');
        $foreign = DB::table('orders as o')->join('order_pva as op', 'op.order_id', '=', 'o.id')
            ->where('o.account_id', '!=', $this->accountId)->whereNull('o.deleted_at')->whereNull('op.deleted_at')->value('o.id');
        if (! $own || ! $foreign) {
            $this->markTestSkipped('Needs a pending order of the account and an order of another account.');
        }
        $this->ownOrderId = (int) $own;
        $this->foreignOrderId = (int) $foreign;

        Passport::actingAs($user, [], 'api');
    }

    private function updateOrder(array $payload, ?int $id = null)
    {
        $id ??= $this->ownOrderId;

        return $this->putJson("/api/orders/{$id}", [$payload]);
    }

    public function test_own_order_can_be_updated(): void
    {
        $this->updateOrder(['id' => $this->ownOrderId, 'note' => 'TEST note'])->assertOk()->assertJsonPath('statut', 1);

        $this->assertSame('TEST note', DB::table('orders')->where('id', $this->ownOrderId)->value('note'));
    }

    public function test_foreign_order_cannot_be_updated(): void
    {
        $before = DB::table('orders')->where('id', $this->foreignOrderId)->value('note');

        $this->updateOrder(['id' => $this->foreignOrderId, 'note' => 'HACKED'], $this->foreignOrderId)
            ->assertStatus(422)->assertJsonPath('statut', 0)->assertJsonStructure(['data' => ['0.id']]);

        $this->assertSame($before, DB::table('orders')->where('id', $this->foreignOrderId)->value('note'));
    }

    public function test_a_line_of_another_order_cannot_be_deactivated(): void
    {
        $line = DB::table('order_pva')->where('order_id', $this->foreignOrderId)->whereNull('deleted_at')->first();
        if (! $line) {
            $this->markTestSkipped('Foreign order has no line.');
        }

        $this->updateOrder(['id' => $this->ownOrderId, 'productsToInactive' => [$line->id]])
            ->assertStatus(422)->assertJsonPath('statut', 0);

        $this->assertSame($line->order_status_id, DB::table('order_pva')->where('id', $line->id)->value('order_status_id'));
    }

    public function test_a_foreign_pickup_cannot_be_linked(): void
    {
        $pickup = DB::table('pickups')->whereNotIn('warehouse_id', DB::table('warehouses')->where('account_id', $this->accountId)->select('id'))
            ->whereNotIn('account_user_id', DB::table('account_user')->where('account_id', $this->accountId)->select('id'))
            ->whereNull('deleted_at')->value('id');
        if (! $pickup) {
            $this->markTestSkipped('No pickup of another account.');
        }

        $this->updateOrder(['id' => $this->ownOrderId, 'pickup_id' => $pickup])->assertStatus(422)->assertJsonPath('statut', 0);

        $this->assertNotSame($pickup, DB::table('orders')->where('id', $this->ownOrderId)->value('pickup_id'));
    }

    public function test_update_is_atomic(): void
    {
        $comment = DB::table('comments')->whereNotNull('new_statut')->where('statut', '!=', 2)->value('id');
        if (! $comment) {
            $this->markTestSkipped('No status comment available.');
        }

        $commentsBefore = DB::table('order_comment')->where('order_id', $this->ownOrderId)->count();
        $noteBefore = DB::table('orders')->where('id', $this->ownOrderId)->value('note');

        // fails after the history row was written and the order saved: everything must roll back
        Order::updated(function () {
            throw new \RuntimeException('simulated failure');
        });

        $this->updateOrder(['id' => $this->ownOrderId, 'note' => 'HALF DONE', 'comment' => ['id' => $comment]])->assertStatus(500);

        $this->assertSame($commentsBefore, DB::table('order_comment')->where('order_id', $this->ownOrderId)->count(), 'history row survived the failure');
        $this->assertSame($noteBefore, DB::table('orders')->where('id', $this->ownOrderId)->value('note'));
    }

    public function test_return_type_is_persisted(): void
    {
        Order::whereKey($this->ownOrderId)->first()->update(['type' => 'return']);

        $this->assertSame('return', DB::table('orders')->where('id', $this->ownOrderId)->value('type'));
    }

    public function test_only_early_status_orders_can_be_deleted(): void
    {
        $shipped = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 7)->whereNull('deleted_at')->value('id');
        if ($shipped) {
            $this->deleteJson("/api/orders/{$shipped}")->assertStatus(422)->assertJsonPath('statut', 0);
            $this->assertNull(DB::table('orders')->where('id', $shipped)->value('deleted_at'));
        }

        $this->deleteJson("/api/orders/{$this->ownOrderId}")->assertOk()->assertJsonPath('statut', 1);
        $this->assertNotNull(DB::table('orders')->where('id', $this->ownOrderId)->value('deleted_at'));
    }

    public function test_foreign_order_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/orders/{$this->foreignOrderId}")->assertStatus(404)->assertJsonPath('statut', 0);

        $this->assertNull(DB::table('orders')->where('id', $this->foreignOrderId)->value('deleted_at'));
    }

    public function test_generated_order_codes_are_sequential_and_unique(): void
    {
        $generate = fn () => \App\Http\Controllers\DefaultCodeController::getAccountCode('Order', $this->accountId);

        $codes = [$generate(), $generate(), $generate()];

        $this->assertCount(3, array_unique($codes));
        preg_match('/(\d+)[A-Z]{2}$/', $codes[0], $first);
        preg_match('/(\d+)[A-Z]{2}$/', $codes[2], $third);
        $this->assertSame((int) $first[1] + 2, (int) $third[1]);
    }
}
