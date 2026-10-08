<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Order workspaces: queues built on the statuses and the reasons already in use (order_comment),
 * counters, callbacks from "Reporté", assignment, the focus-mode queue, team figures and messages.
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

        $orderId = $this->pendingOrders(1)[0] ?? null;
        if (! $orderId) {
            $this->markTestSkipped('Needs a pending order of the account.');
        }
        $this->orderId = $orderId;

        Passport::actingAs($user, [], 'api');
    }

    /** Pending orders nobody touched yet: no callback, no owner, not claimed. */
    private function pendingOrders(int $count): array
    {
        $ids = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 1)
            ->whereNull('deleted_at')->orderByDesc('id')->limit($count)->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::table('orders')->whereIn('id', $ids)->update([
            'callback_at' => null, 'assigned_to' => null, 'assigned_at' => null, 'claimed_by' => null, 'claimed_until' => null,
        ]);

        return $ids;
    }

    /** A visible reason (comments.statut = 1) of the category whose target status is $status. */
    private function reason(int $status, array $where = []): ?int
    {
        $query = DB::table('comments as c')->join('comments as p', 'p.id', '=', 'c.comment_id')
            ->where('p.current_statut', $status)->where('c.statut', 1)->whereNull('c.deleted_at');
        foreach ($where as $column => $value) {
            match (true) {
                is_array($value) => $query->whereIn("c.$column", $value),
                // flags are NULL as often as 0 in the reasons table
                $value === 0 => $query->where(fn ($q) => $q->whereNull("c.$column")->orWhere("c.$column", 0)),
                default => $query->where("c.$column", $value),
            };
        }

        return ($id = $query->orderBy('c.id')->value('c.id')) ? (int) $id : null;
    }

    private function applyReason(int $orderId, int $reasonId, array $comment = [])
    {
        return $this->putJson("/api/orders/{$orderId}", [['id' => $orderId, 'comment' => ['id' => $reasonId] + $comment]])->assertOk();
    }

    private function row(string $queue, int $orderId, array $extra = []): ?array
    {
        $code = DB::table('orders')->where('id', $orderId)->value('code');
        $response = $this->getJson('/api/orders?' . http_build_query([
            'queue' => $queue, 'search' => $code, 'pagination' => ['per_page' => 100, 'current_page' => 0],
        ] + $extra))->assertOk();

        return collect($response->json('data'))->firstWhere('id', $orderId);
    }

    private function inQueue(string $queue, int $orderId, array $extra = []): bool
    {
        return $this->row($queue, $orderId, $extra) !== null;
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
        $this->assertTrue(collect(['assignee', 'callback_at', 'claim', 'status_age_days', 'carrier_status', 'attempts'])
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

    public function test_the_no_answer_reason_moves_the_order_to_no_answer_and_counts_an_attempt(): void
    {
        $noAnswer = $this->reason(1, ['id' => config('orders.no_answer_comments')]);
        if (! $noAnswer) {
            $this->markTestSkipped('No "no answer" reason for pending orders.');
        }
        $this->assertTrue($this->inQueue('confirmation.new', $this->orderId));

        $this->applyReason($this->orderId, $noAnswer);

        $this->assertFalse($this->inQueue('confirmation.new', $this->orderId));
        $row = $this->row('confirmation.no_answer', $this->orderId);
        $this->assertNotNull($row, 'abandoned for no answer: in the no-answer queue');
        $this->assertGreaterThanOrEqual(1, $row['attempts']);
        $this->assertFalse($this->inQueue('confirmation.abandoned_other', $this->orderId));
    }

    public function test_a_postponed_reason_saves_its_date_and_schedules_the_callback(): void
    {
        $postponed = $this->reason(1, ['postponed' => 1]);
        if (! $postponed) {
            $this->markTestSkipped('No postponed reason for pending orders.');
        }

        $at = now()->addDays(2)->setTime(11, 30);
        // sent with the Moroccan offset: stored as the same instant in the app timezone
        $this->applyReason($this->orderId, $postponed, ['postponed' => $at->copy()->setTimezone('Africa/Casablanca')->toIso8601String()]);

        $this->assertSame($at->toDateTimeString(), Order::find($this->orderId)->callback_at->toDateTimeString());
        $this->assertNotNull(DB::table('order_comment')->where('order_id', $this->orderId)->where('comment_id', $postponed)->latest('id')->value('postpone'));
        $this->assertTrue($this->inQueue('confirmation.scheduled', $this->orderId));
        $this->assertFalse($this->inQueue('confirmation.out_of_stock', $this->orderId));

        DB::table('orders')->where('id', $this->orderId)->update(['callback_at' => now()->subMinute()]);
        $this->assertTrue($this->inQueue('confirmation.callbacks_due', $this->orderId));
    }

    public function test_a_postponed_order_without_date_is_due_and_out_of_stock_stays_apart(): void
    {
        $postponed = $this->reason(1, ['postponed' => 1]);
        $outOfStock = $this->reason(3, ['postponed' => 0]);
        [$first, $second] = array_pad($this->pendingOrders(2), 2, null);
        if (! $postponed || ! $outOfStock || ! $second) {
            $this->markTestSkipped('Needs the postponed and out-of-stock reasons and two pending orders.');
        }

        $this->applyReason($first, $postponed);
        $this->applyReason($second, $outOfStock);

        $this->assertTrue($this->inQueue('confirmation.callbacks_due', $first), 'a "Reporté" without date is due now');
        $this->assertFalse($this->inQueue('confirmation.out_of_stock', $first));
        $this->assertTrue($this->inQueue('confirmation.out_of_stock', $second));
        $this->assertFalse($this->inQueue('confirmation.callbacks_due', $second));
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
        $ids = $this->pendingOrders(4);
        if (count($agents) < 2 || count($ids) < 4) {
            $this->markTestSkipped('Needs two agents and four pending orders.');
        }

        $response = $this->postJson('/api/orders/assign/auto', ['ids' => $ids, 'user_ids' => $agents])->assertOk();

        $this->assertSame(4, $response->json('data.assigned'));
        $this->assertSame(4, array_sum($response->json('data.by_agent')));
        $this->assertSame(0, DB::table('orders')->whereIn('id', $ids)->whereNull('assigned_to')->count());
    }

    public function test_team_figures_count_the_reasons_the_agent_applied(): void
    {
        $confirm = $this->reason(4, ['is_change' => 0]);
        if (! $confirm) {
            $this->markTestSkipped('No confirmation reason.');
        }
        $before = $this->getJson('/api/orders/agents/stats')->assertOk()->json('data.agents');
        $mine = fn ($agents) => collect($agents)->firstWhere('agent.id', $this->agentId)['confirmed'] ?? 0;

        $this->applyReason($this->orderId, $confirm);

        $response = $this->getJson('/api/orders/agents/stats?start_date=' . now()->toDateString())->assertOk();
        $this->assertSame($mine($before) + 1, $mine($response->json('data.agents')));
        $this->assertTrue(collect(['orders_created', 'confirmed', 'no_answer', 'confirmation_rate', 'delivery_rate', 'first_response_minutes'])
            ->every(fn ($key) => array_key_exists($key, $response->json('data.totals'))));
        $this->getJson('/api/orders/agents/stats?start_date=2026-10-08&end_date=2026-10-01')->assertStatus(422);
    }

    public function test_message_templates_are_saved_per_account_and_reset(): void
    {
        $this->putJson('/api/orders/message-templates', ['templates' => [
            ['stage' => 'confirmation', 'title' => 'Confirmer', 'language' => 'darija', 'body' => 'Salam {client}'],
            ['stage' => 'tracking', 'title' => 'En route', 'language' => 'fr', 'body' => 'Bonjour {client}, {code} est en route.'],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(['Confirmer', 'En route'], collect($this->getJson('/api/orders/message-templates')->json('data'))->pluck('title')->all());

        $this->putJson('/api/orders/message-templates', ['templates' => [['stage' => 'billing', 'title' => 'x', 'language' => 'fr', 'body' => 'x']]])
            ->assertStatus(422);

        $this->putJson('/api/orders/message-templates', ['templates' => []])->assertOk()->assertJsonCount(0, 'data');
    }
}
