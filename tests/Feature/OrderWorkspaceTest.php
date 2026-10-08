<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Order workspaces: queues, counters, call log, callbacks, assignment and the focus-mode queue.
 * Runs against the development database inside a rolled-back transaction; skipped when the
 * reference user or a pending order is missing.
 */
class OrderWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $agentId;
    private int $orderId;

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
        $this->agentId = (int) $accountUser->id;

        $orderId = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 1)
            ->whereNull('deleted_at')->orderByDesc('id')->value('id');
        if (! $orderId) {
            $this->markTestSkipped('Needs a pending order of the account.');
        }
        $this->orderId = (int) $orderId;
        $this->makeFresh($this->orderId);

        Passport::actingAs($user, [], 'api');
    }

    /** A pending order nobody touched yet: no call, no callback, no owner, not claimed. */
    private function makeFresh(int $orderId): void
    {
        DB::table('order_calls')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->update([
            'callback_at' => null, 'assigned_to' => null, 'assigned_at' => null, 'claimed_by' => null, 'claimed_until' => null,
        ]);
    }

    private function inQueue(string $queue, int $orderId, array $extra = []): bool
    {
        $code = DB::table('orders')->where('id', $orderId)->value('code');
        $response = $this->getJson('/api/orders?' . http_build_query([
            'queue' => $queue, 'search' => $code, 'pagination' => ['per_page' => 100, 'current_page' => 0],
        ] + $extra))->assertOk();

        return collect($response->json('data'))->pluck('id')->contains($orderId);
    }

    public function test_an_unknown_queue_is_refused(): void
    {
        $this->getJson('/api/orders?queue=confirmation.everything')->assertStatus(422);
        $this->getJson('/api/orders/queues/counts?workspace=nowhere')->assertStatus(422);
    }

    public function test_list_rows_carry_the_workspace_fields(): void
    {
        $response = $this->getJson('/api/orders?queue=confirmation.new&pagination[per_page]=5')->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertTrue(collect(['assignee', 'callback_at', 'calls_count', 'last_call', 'claim', 'status_age_days', 'carrier_status'])
            ->every(fn ($key) => array_key_exists($key, $response->json('data.0'))));
    }

    public function test_each_counter_matches_its_list(): void
    {
        foreach (['confirmation', 'tracking', 'recovery'] as $workspace) {
            $counts = $this->getJson("/api/orders/queues/counts?workspace={$workspace}")->assertOk()->json('data.counts');

            foreach ($counts as $queue => $count) {
                $total = $this->getJson("/api/orders?queue={$workspace}.{$queue}&pagination[per_page]=1")->assertOk()->json('total');
                $this->assertSame($count, $total, "{$workspace}.{$queue}");
            }
        }
    }

    public function test_a_call_without_answer_moves_the_order_to_follow_ups(): void
    {
        $this->assertTrue($this->inQueue('confirmation.new', $this->orderId));

        $this->postJson("/api/orders/{$this->orderId}/calls", ['result' => 'no_answer', 'note' => 'TEST'])
            ->assertOk()
            ->assertJsonPath('data.call.call_number', 1)
            ->assertJsonPath('data.order.calls_count', 1)
            ->assertJsonPath('data.order.assignee.id', $this->agentId);

        $this->assertFalse($this->inQueue('confirmation.new', $this->orderId));
        $this->assertTrue($this->inQueue('confirmation.no_answer', $this->orderId));

        $this->postJson("/api/orders/{$this->orderId}/calls", ['result' => 'busy'])->assertOk()->assertJsonPath('data.call.call_number', 2);
        $this->assertCount(2, $this->getJson("/api/orders/{$this->orderId}/calls")->assertOk()->json('data'));
    }

    public function test_a_callback_schedules_the_order_and_needs_a_future_date(): void
    {
        $this->postJson("/api/orders/{$this->orderId}/calls", ['result' => 'callback'])->assertStatus(422);
        $this->postJson("/api/orders/{$this->orderId}/calls", ['result' => 'callback', 'callback_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422);

        $at = now()->addDay()->setTime(10, 0);
        // sent with the Moroccan offset: stored as the same instant in the app timezone
        $this->postJson("/api/orders/{$this->orderId}/calls", [
            'result' => 'callback', 'callback_at' => $at->copy()->setTimezone('Africa/Casablanca')->toIso8601String(),
        ])->assertOk();

        $this->assertSame($at->toDateTimeString(), Order::find($this->orderId)->callback_at->toDateTimeString());
        $this->assertTrue($this->inQueue('confirmation.scheduled', $this->orderId));

        DB::table('orders')->where('id', $this->orderId)->update(['callback_at' => now()->subMinute()]);
        $this->assertTrue($this->inQueue('confirmation.callbacks_due', $this->orderId));
    }

    public function test_a_postponed_reason_saves_its_date_as_the_callback(): void
    {
        $reason = DB::table('comments as c')->join('comments as p', 'p.id', '=', 'c.comment_id')
            ->where('c.postponed', 1)->where('p.current_statut', 1)->value('c.id');
        if (! $reason) {
            $this->markTestSkipped('No postponed reason for pending orders.');
        }

        $at = now()->addDays(2)->setTime(11, 30);
        $this->putJson("/api/orders/{$this->orderId}", [[
            'id' => $this->orderId,
            'comment' => ['id' => $reason, 'postponed' => $at->toDateTimeString()],
        ]])->assertOk();

        $this->assertSame($at->toDateTimeString(), Order::find($this->orderId)->callback_at->toDateTimeString());
        $this->assertNotNull(DB::table('order_comment')->where('order_id', $this->orderId)->where('comment_id', $reason)->latest('id')->value('postpone'));
    }

    public function test_a_status_change_clears_the_callback_and_the_claim(): void
    {
        $order = Order::find($this->orderId);
        $order->callback_at = now()->addDay();
        $order->claimed_by = $this->agentId;
        $order->claimed_until = now()->addMinutes(5);
        $order->save();

        $order->order_status_id = 4;
        $order->save();

        $order->refresh();
        $this->assertNull($order->callback_at);
        $this->assertNull($order->claimed_by);
    }

    public function test_focus_mode_never_hands_out_the_same_order_twice(): void
    {
        $first = $this->postJson('/api/orders/queue/next')->assertOk()->json('data');
        $second = $this->postJson('/api/orders/queue/next')->assertOk()->json('data');
        if (! $first || ! $second) {
            $this->markTestSkipped('Needs two orders to confirm.');
        }

        $this->assertNotSame($first['id'], $second['id']);
        foreach ([$first['id'], $second['id']] as $id) {
            $this->assertSame($this->agentId, (int) DB::table('orders')->where('id', $id)->value('claimed_by'));
        }

        $this->postJson("/api/orders/{$first['id']}/release")->assertOk()->assertJsonPath('data.released', true);
        $this->assertNull(DB::table('orders')->where('id', $first['id'])->value('claimed_by'));
    }

    public function test_focus_mode_skips_orders_of_other_agents(): void
    {
        $colleague = DB::table('account_user')->where('account_id', $this->accountId)->where('id', '!=', $this->agentId)->value('id');
        if (! $colleague) {
            $this->markTestSkipped('Needs a second agent in the account.');
        }
        DB::table('orders')->where('id', $this->orderId)->update(['assigned_to' => $colleague]);

        $skip = [];
        for ($i = 0; $i < 20 && ($next = $this->postJson('/api/orders/queue/next', ['skip' => $skip])->json('data')); $i++) {
            $this->assertNotSame($this->orderId, $next['id']);
            $skip[] = $next['id'];
        }
    }

    public function test_orders_can_be_assigned_and_filtered_by_agent(): void
    {
        $this->postJson('/api/orders/assign', ['ids' => [$this->orderId], 'account_user_id' => $this->agentId])
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertTrue($this->inQueue('confirmation.new', $this->orderId, ['assigned_to' => ['me']]));
        $this->assertFalse($this->inQueue('confirmation.new', $this->orderId, ['assigned_to' => ['none']]));

        $this->postJson('/api/orders/assign', ['ids' => [$this->orderId], 'account_user_id' => null])->assertOk();
        $this->assertTrue($this->inQueue('confirmation.new', $this->orderId, ['assigned_to' => ['none']]));
    }

    public function test_an_agent_of_another_account_cannot_get_orders(): void
    {
        $foreign = DB::table('account_user')->where('account_id', '!=', $this->accountId)->value('id');
        if (! $foreign) {
            $this->markTestSkipped('No agent of another account.');
        }

        $this->postJson('/api/orders/assign', ['ids' => [$this->orderId], 'account_user_id' => $foreign])->assertStatus(422);
        $this->assertNull(DB::table('orders')->where('id', $this->orderId)->value('assigned_to'));
    }

    public function test_auto_assign_shares_the_orders_between_agents(): void
    {
        $agents = DB::table('account_user')->where('account_id', $this->accountId)->where('statut', 1)->limit(2)->pluck('id')->all();
        $ids = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 1)
            ->whereNull('deleted_at')->limit(4)->pluck('id')->all();
        if (count($agents) < 2 || count($ids) < 4) {
            $this->markTestSkipped('Needs two agents and four pending orders.');
        }

        $response = $this->postJson('/api/orders/assign/auto', ['ids' => $ids, 'user_ids' => $agents])->assertOk();

        $this->assertSame(4, $response->json('data.assigned'));
        $this->assertSame(4, array_sum($response->json('data.by_agent')));
        $this->assertSame(0, DB::table('orders')->whereIn('id', $ids)->whereNull('assigned_to')->count());
    }

    public function test_calls_of_another_account_are_not_found(): void
    {
        $foreign = DB::table('orders')->where('account_id', '!=', $this->accountId)->whereNull('deleted_at')->value('id');
        if (! $foreign) {
            $this->markTestSkipped('No order of another account.');
        }

        $this->getJson("/api/orders/{$foreign}/calls")->assertStatus(404);
        $this->postJson("/api/orders/{$foreign}/calls", ['result' => 'no_answer'])->assertStatus(404);
        $this->assertSame(0, DB::table('order_calls')->where('order_id', $foreign)->count());
    }
}
