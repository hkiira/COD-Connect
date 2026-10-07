<?php

namespace Tests\Feature;

use App\Jobs\PushWooCommerceOrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\WooCommerce\OrderLink;
use App\Services\WooCommerce\StatusPusher;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Status mapping, the manual and automatic pushes to WooCommerce, the activity log and its retry.
 * WooCommerce is faked; runs against the development database in a rolled-back transaction.
 */
class WooCommerceStatusTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BASE = 'https://shop.example.test/wp-json/wc/v3';

    private int $accountId;
    private int $storeId;
    private int $orderId;
    private int $linkId;

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

        $orderId = DB::table('orders')->where('account_id', $this->accountId)->where('order_status_id', 1)->whereNull('deleted_at')->value('id');
        if (! $orderId) {
            $this->markTestSkipped('The account has no pending order.');
        }
        $this->orderId = (int) $orderId;

        Passport::actingAs($user, [], 'api');

        $this->storeId = (int) $this->postJson('/api/wc/stores', [
            'name' => 'TEST shop', 'base_url' => self::BASE, 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x',
        ])->assertStatus(201)->json('data.id');

        $this->linkId = (int) DB::table('woocommerce_order_links')->insertGetId([
            'store_id' => $this->storeId, 'account_id' => $this->accountId, 'wc_order_id' => 9901, 'order_id' => $this->orderId,
            'last_wc_status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setOrderStatus(int $status): void
    {
        DB::table('orders')->where('id', $this->orderId)->update(['order_status_id' => $status]);
    }

    private function freshHttp(array $fake): void
    {
        Http::swap(new Factory());
        Http::fake($fake);
    }

    public function test_every_status_is_listed_with_the_default_mapping(): void
    {
        $rows = collect($this->getJson("/api/wc/stores/{$this->storeId}/status-mappings")->assertOk()->json('data'))->keyBy('order_status_id');

        $this->assertCount(DB::table('order_statuses')->count(), $rows);
        $this->assertSame(['completed', true], [$rows[7]['wc_status'], $rows[7]['is_enabled']]);
        $this->assertSame(['cancelled', true], [$rows[8]['wc_status'], $rows[8]['is_enabled']]);
        $this->assertFalse($rows[6]['is_enabled'], 'pushing "in delivery" is opt-in');
        $this->assertNull($rows[1]['wc_status']);
    }

    public function test_the_mapping_can_be_changed_and_is_validated(): void
    {
        $this->putJson("/api/wc/stores/{$this->storeId}/status-mappings", ['mappings' => [
            ['order_status_id' => 6, 'wc_status' => 'on-hold', 'is_enabled' => true],
            ['order_status_id' => 7, 'wc_status' => 'processing', 'is_enabled' => false],
        ]])->assertOk();

        $this->assertDatabaseHas('woocommerce_status_mappings', ['store_id' => $this->storeId, 'order_status_id' => 6, 'is_enabled' => 1]);
        $this->assertDatabaseHas('woocommerce_status_mappings', ['store_id' => $this->storeId, 'order_status_id' => 7, 'wc_status' => 'processing', 'is_enabled' => 0]);

        $this->putJson("/api/wc/stores/{$this->storeId}/status-mappings", ['mappings' => [['order_status_id' => 6, 'wc_status' => 'shipped-ish', 'is_enabled' => true]]])->assertStatus(422);
        $this->putJson("/api/wc/stores/{$this->storeId}/status-mappings", ['mappings' => [['order_status_id' => 9999, 'wc_status' => 'completed', 'is_enabled' => true]]])->assertStatus(422);
    }

    public function test_a_status_change_of_a_linked_order_queues_a_push_but_other_changes_do_not(): void
    {
        Queue::fake();

        $order = Order::find($this->orderId);
        $order->update(['note' => 'changed note']);
        Queue::assertNothingPushed();

        $order->update(['order_status_id' => 7]);
        Queue::assertPushed(PushWooCommerceOrderStatus::class, fn ($job) => $job->orderLinkId === $this->linkId && $job->queue === 'woocommerce');
    }

    public function test_an_order_that_did_not_come_from_woocommerce_queues_nothing(): void
    {
        Queue::fake();
        $other = DB::table('orders')->where('account_id', $this->accountId)->where('id', '!=', $this->orderId)->whereNull('deleted_at')
            ->whereNotIn('id', fn ($q) => $q->select('order_id')->from('woocommerce_order_links'))->value('id');
        if (! $other) {
            $this->markTestSkipped('No unlinked order.');
        }

        Order::find($other)->update(['order_status_id' => 7]);

        Queue::assertNothingPushed();
    }

    public function test_the_push_follows_the_mapping_once_and_skips_what_woocommerce_already_has(): void
    {
        $this->setOrderStatus(7);
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['id' => 9901, 'status' => 'completed'])]);
        $pusher = app(StatusPusher::class);

        $first = $pusher->push(OrderLink::find($this->linkId));
        $this->assertSame('success', $first['status']);
        $this->assertSame('completed', $first['wc_status']);
        $this->assertDatabaseHas('woocommerce_order_links', ['id' => $this->linkId, 'last_wc_status' => 'completed', 'last_pushed_status' => 'completed']);
        $this->assertDatabaseHas('woocommerce_sync_logs', ['store_id' => $this->storeId, 'direction' => 'push', 'status' => 'success', 'wc_id' => 9901]);

        $second = $pusher->push(OrderLink::find($this->linkId));
        $this->assertSame('skipped', $second['status']);
        Http::assertSentCount(1);
    }

    public function test_an_unmapped_status_is_skipped_and_an_automatic_skip_leaves_no_log(): void
    {
        $this->setOrderStatus(1);
        Http::fake();
        $pusher = app(StatusPusher::class);

        $this->assertSame('skipped', $pusher->push(OrderLink::find($this->linkId), null, quiet: true)['status']);
        $this->assertSame(0, DB::table('woocommerce_sync_logs')->where('store_id', $this->storeId)->where('direction', 'push')->count());
        Http::assertNothingSent();

        $pusher->push(OrderLink::find($this->linkId));
        $this->assertSame(1, DB::table('woocommerce_sync_logs')->where('store_id', $this->storeId)->where('status', 'skipped')->count());
    }

    public function test_a_failed_push_is_logged_and_thrown_for_the_queue_to_retry(): void
    {
        $this->setOrderStatus(8);
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['message' => 'Order not found'], 404)]);

        try {
            app(StatusPusher::class)->push(OrderLink::find($this->linkId));
            $this->fail('the failure should reach the queue');
        } catch (WooCommerceException $e) {
            $this->assertSame('Order not found', $e->getMessage());
        }

        $this->assertDatabaseHas('woocommerce_sync_logs', ['store_id' => $this->storeId, 'direction' => 'push', 'status' => 'failed', 'wc_id' => 9901]);
        $this->assertDatabaseHas('woocommerce_order_links', ['id' => $this->linkId, 'last_wc_status' => 'processing']);
    }

    public function test_the_job_pushes_the_status_the_order_has_when_it_runs(): void
    {
        $this->setOrderStatus(10);
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['id' => 9901, 'status' => 'completed'])]);

        (new PushWooCommerceOrderStatus($this->linkId))->handle(app(StatusPusher::class));

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['status'] === 'completed');
    }

    public function test_a_manual_push_can_force_any_status_and_a_missing_link_is_a_404(): void
    {
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['id' => 9901, 'status' => 'on-hold'])]);

        $this->postJson("/api/wc/stores/{$this->storeId}/orders/9901/push-status", ['status' => 'on-hold'])->assertOk()->assertJsonPath('wc_status', 'on-hold');
        $this->postJson("/api/wc/stores/{$this->storeId}/orders/9901/push-status", ['status' => 'bogus'])->assertStatus(422);
        $this->postJson("/api/wc/stores/{$this->storeId}/orders/1/push-status", ['status' => 'on-hold'])->assertStatus(404);
    }

    public function test_a_bulk_push_reports_each_order(): void
    {
        $this->setOrderStatus(7);
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['id' => 9901, 'status' => 'completed'])]);

        $results = collect($this->postJson("/api/wc/stores/{$this->storeId}/orders/push-status", ['wc_order_ids' => [9901, 12345]])->assertOk()->json('data'))->keyBy('wc_order_id');

        $this->assertSame('success', $results[9901]['status']);
        $this->assertSame('failed', $results[12345]['status']);
    }

    public function test_the_log_can_be_filtered_and_a_failed_push_can_be_retried(): void
    {
        $this->setOrderStatus(8);
        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['message' => 'down'], 500)]);
        try {
            app(StatusPusher::class)->push(OrderLink::find($this->linkId));
        } catch (WooCommerceException) {
        }

        $failed = $this->getJson("/api/wc/stores/{$this->storeId}/logs?status=failed&direction=push")->assertOk()->json('data');
        $this->assertCount(1, $failed);

        $this->freshHttp([self::BASE . '/orders/9901' => Http::response(['id' => 9901, 'status' => 'cancelled'])]);
        $this->postJson("/api/wc/stores/{$this->storeId}/logs/{$failed[0]['id']}/retry")->assertOk();

        $this->assertDatabaseHas('woocommerce_order_links', ['id' => $this->linkId, 'last_wc_status' => 'cancelled']);
        $this->assertSame([], $this->getJson("/api/wc/stores/{$this->storeId}/logs?status=failed")->json('data'), 'the retried line is no longer offered');

        // a log line that is not a failure cannot be retried
        $ok = DB::table('woocommerce_sync_logs')->where('store_id', $this->storeId)->where('status', 'success')->value('id');
        $this->postJson("/api/wc/stores/{$this->storeId}/logs/{$ok}/retry")->assertStatus(422);
    }

    public function test_a_foreign_store_has_no_mappings_pushes_or_logs(): void
    {
        $foreign = DB::table('woocommerce_stores')->insertGetId([
            'account_id' => $this->accountId + 100000, 'name' => 'Foreign', 'base_url' => 'https://other.test', 'consumer_key' => 'x', 'consumer_secret' => 'y',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/wc/stores/{$foreign}/status-mappings")->assertStatus(404);
        $this->putJson("/api/wc/stores/{$foreign}/status-mappings", ['mappings' => [['order_status_id' => 7, 'wc_status' => 'completed', 'is_enabled' => true]]])->assertStatus(404);
        $this->postJson("/api/wc/stores/{$foreign}/orders/1/push-status")->assertStatus(404);
        $this->getJson("/api/wc/stores/{$foreign}/logs")->assertStatus(404);
    }
}
