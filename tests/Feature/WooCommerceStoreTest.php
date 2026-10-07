<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WooCommerce\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * WooCommerce stores of an account: tenancy, write-only keys, default status mappings and the connection test.
 * WooCommerce itself is faked; runs against the development database in a rolled-back transaction.
 */
class WooCommerceStoreTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;

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

        Passport::actingAs($user, [], 'api');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'TEST shop',
            'base_url' => 'https://shop.example.test/wp-json/wc/v3/',
            'consumer_key' => 'ck_test_key_value',
            'consumer_secret' => 'cs_test_secret_value',
        ], $overrides);
    }

    public function test_a_store_is_created_with_encrypted_keys_and_default_mappings(): void
    {
        $response = $this->postJson('/api/wc/stores', $this->payload())->assertStatus(201);

        $response->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.base_url', 'https://shop.example.test/wp-json/wc/v3');
        $this->assertStringNotContainsString('ck_test_key_value', $response->getContent());
        $this->assertStringNotContainsString('cs_test_secret_value', $response->getContent());

        $id = $response->json('data.id');
        $raw = DB::table('woocommerce_stores')->where('id', $id)->first();
        $this->assertSame($this->accountId, (int) $raw->account_id);
        $this->assertStringNotContainsString('ck_test_key_value', $raw->consumer_key, 'the key is encrypted at rest');
        $this->assertSame('ck_test_key_value', Store::find($id)->consumer_key, 'and readable through the model');

        $this->assertSame(6, DB::table('woocommerce_status_mappings')->where('store_id', $id)->count());
    }

    public function test_the_list_never_returns_the_keys(): void
    {
        $this->postJson('/api/wc/stores', $this->payload())->assertStatus(201);

        $list = $this->getJson('/api/wc/stores')->assertOk();

        $this->assertStringNotContainsString('consumer_key', $list->getContent());
        $this->assertStringNotContainsString('ck_test_key_value', $list->getContent());
    }

    public function test_an_update_without_keys_keeps_the_saved_ones(): void
    {
        $id = $this->postJson('/api/wc/stores', $this->payload())->json('data.id');

        $this->putJson("/api/wc/stores/{$id}", ['name' => 'Renamed', 'consumer_key' => '', 'consumer_secret' => null])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->assertSame('ck_test_key_value', Store::find($id)->consumer_key);
        $this->assertSame('cs_test_secret_value', Store::find($id)->consumer_secret);

        $this->putJson("/api/wc/stores/{$id}", ['consumer_key' => 'ck_new'])->assertOk();
        $this->assertSame('ck_new', Store::find($id)->consumer_key);
    }

    public function test_a_store_of_another_account_does_not_exist_for_the_caller(): void
    {
        $foreignId = DB::table('woocommerce_stores')->insertGetId([
            'account_id' => $this->accountId + 100000, 'name' => 'Foreign', 'base_url' => 'https://other.test', 'consumer_key' => 'x', 'consumer_secret' => 'y',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson("/api/wc/stores/{$foreignId}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/wc/stores/{$foreignId}")->assertStatus(404);
        $this->postJson("/api/wc/stores/{$foreignId}/test")->assertStatus(404);
        $this->assertSame('Foreign', DB::table('woocommerce_stores')->where('id', $foreignId)->value('name'));
        $this->assertNotContains($foreignId, collect($this->getJson('/api/wc/stores')->json('data'))->pluck('id')->all());
    }

    public function test_defaults_must_belong_to_the_account(): void
    {
        $foreignWarehouse = DB::table('warehouses')->where('account_id', '!=', $this->accountId)->value('id');
        if (! $foreignWarehouse) {
            $this->markTestSkipped('No warehouse of another account to test with.');
        }

        $this->postJson('/api/wc/stores', $this->payload(['default_warehouse_id' => $foreignWarehouse]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['default_warehouse_id']]);
        $this->postJson('/api/wc/stores', $this->payload(['name' => '']))->assertStatus(422);
    }

    public function test_connection_test_reports_counts_and_errors(): void
    {
        $id = $this->postJson('/api/wc/stores', $this->payload())->json('data.id');

        Http::fake([
            'shop.example.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-Total' => '42']),
            'shop.example.test/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '7']),
        ]);
        $this->postJson("/api/wc/stores/{$id}/test")->assertOk()->assertJsonPath('data.orders', 42)->assertJsonPath('data.products', 7);
        $this->assertNull(Store::find($id)->last_error);
        $this->assertNotNull(Store::find($id)->last_checked_at);

        // a fresh fake: the first matching stub of the previous one would win otherwise
        Http::swap(new Factory());
        Http::fake(['shop.example.test/*' => Http::response(['message' => 'Consumer key is invalid.'], 401)]);
        $this->postJson("/api/wc/stores/{$id}/test")->assertStatus(422)->assertJsonPath('message', 'Consumer key is invalid.');
        $this->assertSame('Consumer key is invalid.', Store::find($id)->last_error);
    }

    public function test_the_overview_counts_local_activity_and_reads_the_store_once(): void
    {
        $id = $this->postJson('/api/wc/stores', $this->payload())->json('data.id');
        DB::table('woocommerce_order_links')->insert(['store_id' => $id, 'account_id' => $this->accountId, 'wc_order_id' => 1, 'order_id' => 1, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('woocommerce_sync_logs')->insert([
            ['store_id' => $id, 'account_id' => $this->accountId, 'direction' => 'push', 'entity' => 'order', 'status' => 'failed', 'created_at' => now()],
            ['store_id' => $id, 'account_id' => $this->accountId, 'direction' => 'push', 'entity' => 'order', 'status' => 'success', 'created_at' => now()],
        ]);

        Http::fake([
            'shop.example.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-Total' => '12']),
            'shop.example.test/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '30']),
        ]);

        $data = $this->getJson("/api/wc/stores/{$id}/overview")->assertOk()->json('data');
        $this->assertSame(12, $data['summary']['waiting_orders']['value']);
        $this->assertSame(30, $data['summary']['products_total']['value']);
        $this->assertSame(1, $data['summary']['imported_today']['value']);
        $this->assertSame(1, $data['summary']['failed_week']['value']);
        $this->assertSame(1, $data['summary']['pushed_today']['value']);
        $this->assertNull($data['wc_error']);

        $this->getJson("/api/wc/stores/{$id}/overview")->assertOk();
        Http::assertSentCount(2); // the second call came from the cache
    }

    public function test_the_overview_still_answers_when_the_store_is_down(): void
    {
        $id = $this->postJson('/api/wc/stores', $this->payload())->json('data.id');
        Http::fake(['shop.example.test/*' => Http::response(['message' => 'Down for maintenance'], 503)]);

        $data = $this->getJson("/api/wc/stores/{$id}/overview")->assertOk()->json('data');

        $this->assertNull($data['summary']['waiting_orders']['value']);
        $this->assertSame('Down for maintenance', $data['wc_error']);
        $this->assertSame(0, $data['summary']['imported_total']['value']);
    }
}
