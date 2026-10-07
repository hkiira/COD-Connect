<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * WooCommerce orders of a store: list with match state, import (links, logs, status update after the commit),
 * details, and the older routes that default to the account's store. WooCommerce is faked; runs against the
 * development database in a rolled-back transaction.
 */
class WooCommerceOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BASE = 'https://shop.example.test/wp-json/wc/v3';

    private int $accountId;
    private int $storeId;
    private int $pvaId;
    private int $productId;
    private array $attributeIds;

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

        $pva = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $this->accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->select('pva.id', 'pva.product_id', 'pva.variation_attribute_id')->first();
        $this->attributeIds = $pva ? DB::table('variation_attributes')->where('variation_attribute_id', $pva->variation_attribute_id)->pluck('attribute_id')->all() : [];
        $brandSource = DB::table('brand_source')->where('account_id', $this->accountId)->value('id');
        $city = DB::table('cities')->value('id');
        if (! $pva || ! $this->attributeIds || ! $brandSource || ! $city) {
            $this->markTestSkipped('The account needs a product with attributes, a brand source and a city.');
        }
        [$this->pvaId, $this->productId] = [(int) $pva->id, (int) $pva->product_id];

        Passport::actingAs($user, [], 'api');

        $this->storeId = (int) $this->postJson('/api/wc/stores', [
            'name' => 'TEST shop', 'base_url' => self::BASE, 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x',
            'default_brand_source_id' => $brandSource, 'wc_status_after_import' => 'completed',
        ])->assertStatus(201)->json('data.id');
        $this->cityId = (int) $city;
    }

    private int $cityId;

    private function wcOrder(int $id, ?array $items = null, string $status = 'processing'): array
    {
        return [
            'id' => $id, 'status' => $status, 'total' => '299', 'currency' => 'MAD', 'date_created' => '2026-10-07T10:00:00',
            'shipping_total' => '35', 'customer_note' => 'ring twice',
            'billing' => ['first_name' => 'TEST', 'last_name' => 'Buyer', 'phone' => '0600077' . str_pad((string) ($id % 1000), 3, '0', STR_PAD_LEFT), 'address_1' => 'test street', 'city' => (string) $this->cityId],
            'line_items' => $items ?? [['id' => 1, 'product_id' => 500, 'variation_id' => 501, 'name' => 'Shoe', 'sku' => '', 'quantity' => 1, 'price' => '264']],
        ];
    }

    private function link(int $wcProduct = 500, int $wcVariation = 501): void
    {
        $this->postJson("/api/wc/stores/{$this->storeId}/links", ['wc_product_id' => $wcProduct, 'wc_variation_id' => $wcVariation, 'product_variation_attribute_id' => $this->pvaId])->assertOk();
    }

    public function test_the_list_shows_the_match_state_and_the_imported_orders(): void
    {
        $this->link();
        Http::fake([self::BASE . '/orders?*' => Http::response([
            $this->wcOrder(9001),
            $this->wcOrder(9002, [['id' => 2, 'product_id' => 600, 'variation_id' => 601, 'name' => 'Unknown', 'sku' => '', 'quantity' => 1, 'price' => '100']]),
        ], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1'])]);

        $rows = collect($this->getJson("/api/wc/stores/{$this->storeId}/orders?status=processing")->assertOk()->json('data'))->keyBy('wc_order_id');

        $this->assertSame(0, $rows[9001]['unmatched_items']);
        $this->assertTrue($rows[9001]['line_items'][0]['is_matched']);
        $this->assertSame(1, $rows[9002]['unmatched_items']);
        $this->assertFalse($rows[9001]['already_imported']);
    }

    public function test_an_import_creates_the_order_the_link_and_the_log_then_marks_woocommerce(): void
    {
        $this->link();
        Http::fake([
            self::BASE . '/orders/9101' => Http::sequence()->push($this->wcOrder(9101))->push(['id' => 9101, 'status' => 'completed']),
        ]);

        $response = $this->postJson("/api/wc/stores/{$this->storeId}/orders/import", ['orders' => [['wc_order_id' => 9101]]]);
        $response->assertOk()->assertJsonPath('results.0.success', true);

        $orderId = $response->json('results.0.system_order_id');
        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('woocommerce_order_links', ['store_id' => $this->storeId, 'wc_order_id' => 9101, 'order_id' => $orderId, 'last_pushed_status' => 'completed']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'account_id' => $this->accountId, 'meta' => '9101']);
        $this->assertDatabaseHas('woocommerce_sync_logs', ['store_id' => $this->storeId, 'direction' => 'import', 'status' => 'success', 'wc_id' => 9101]);

        // the WooCommerce order was updated once, to the store's "after import" status
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/orders/9101') && $request['status'] === 'completed');

        // importing it again is refused, nothing is duplicated (a fresh fake: the first stubs would win otherwise)
        Http::swap(new Factory());
        Http::fake([self::BASE . '/orders/9101' => Http::response($this->wcOrder(9101))]);
        $again = $this->postJson("/api/wc/stores/{$this->storeId}/orders/import", ['orders' => [['wc_order_id' => 9101]]]);
        $again->assertJsonPath('results.0.success', false)->assertJsonPath('results.0.message', 'Order already imported.');
        $this->assertSame(1, DB::table('woocommerce_order_links')->where('store_id', $this->storeId)->where('wc_order_id', 9101)->count());
    }

    public function test_an_order_with_no_matched_item_is_not_imported(): void
    {
        Http::fake([self::BASE . '/orders/9201' => Http::response($this->wcOrder(9201))]);

        $this->postJson("/api/wc/stores/{$this->storeId}/orders/import", ['orders' => [['wc_order_id' => 9201]]])
            ->assertJsonPath('results.0.success', false);

        $this->assertDatabaseMissing('woocommerce_order_links', ['store_id' => $this->storeId, 'wc_order_id' => 9201]);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_a_failed_woocommerce_update_does_not_fail_the_import(): void
    {
        $this->link();
        Http::fake([
            self::BASE . '/orders/9301' => Http::sequence()->push($this->wcOrder(9301))->push(['message' => 'boom'], 500),
        ]);

        $response = $this->postJson("/api/wc/stores/{$this->storeId}/orders/import", ['orders' => [['wc_order_id' => 9301]]]);

        $response->assertOk()->assertJsonPath('results.0.success', true);
        $this->assertDatabaseHas('woocommerce_order_links', ['store_id' => $this->storeId, 'wc_order_id' => 9301, 'last_pushed_status' => null]);
        $this->assertDatabaseHas('woocommerce_sync_logs', ['store_id' => $this->storeId, 'direction' => 'push', 'status' => 'failed', 'wc_id' => 9301]);
    }

    public function test_the_details_show_a_preview_then_the_imported_order(): void
    {
        $this->link();
        Http::fake([self::BASE . '/orders/9401' => Http::sequence()->push($this->wcOrder(9401))->push($this->wcOrder(9401))->push(['id' => 9401, 'status' => 'completed'])->push($this->wcOrder(9401, null, 'completed'))]);

        $preview = $this->getJson("/api/wc/stores/{$this->storeId}/orders/9401")->assertOk()->json('data');
        $this->assertNull($preview['id']);
        $this->assertSame('WC-9401', $preview['code']);
        $this->assertTrue($preview['products'][0]['is_matched']);

        $this->postJson("/api/wc/stores/{$this->storeId}/orders/import", ['orders' => [['wc_order_id' => 9401]]])->assertOk();

        $imported = $this->getJson("/api/wc/stores/{$this->storeId}/orders/9401")->assertOk()->json('data');
        $this->assertNotNull($imported['id']);
        $this->assertSame('completed', $imported['link']['last_pushed_status']);
    }

    public function test_a_foreign_store_is_out_of_reach_and_the_old_routes_use_the_account_store(): void
    {
        $foreign = DB::table('woocommerce_stores')->insertGetId([
            'account_id' => $this->accountId + 100000, 'name' => 'Foreign', 'base_url' => 'https://other.test', 'consumer_key' => 'x', 'consumer_secret' => 'y',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson("/api/wc/stores/{$foreign}/orders")->assertStatus(404);
        $this->postJson("/api/wc/stores/{$foreign}/orders/import", ['orders' => [['wc_order_id' => 1]]])->assertStatus(404);
        $this->getJson("/api/wc/stores/{$foreign}/orders/1")->assertStatus(404);

        $this->link();
        Http::fake([self::BASE . '/orders?*' => Http::response([$this->wcOrder(9501)], 200, ['X-WP-Total' => '1'])]);

        $legacy = $this->getJson("/api/wc-orders?status=processing&store_id={$this->storeId}")->assertOk();
        $this->assertSame(9501, $legacy->json('data.0.wc_order_id'));
        $this->assertTrue($legacy->json('data.0.line_items.0.is_matched'));
    }

    public function test_the_old_sync_button_creates_a_store_link(): void
    {
        $this->postJson('/api/wc-orders/sync-product', ['store_id' => $this->storeId, 'wc_variation_id' => 777, 'wc_product_id' => 700, 'system_pva_id' => $this->pvaId])->assertOk();

        $this->assertDatabaseHas('woocommerce_variation_links', ['store_id' => $this->storeId, 'wc_product_id' => 700, 'wc_variation_id' => 777, 'product_variation_attribute_id' => $this->pvaId]);

        $foreignPva = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', '!=', $this->accountId)->whereNull('pva.deleted_at')
            ->whereNotIn('p.id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $this->accountId))->value('pva.id');
        if ($foreignPva) {
            $this->postJson('/api/wc-orders/sync-product', ['wc_variation_id' => 778, 'system_pva_id' => $foreignPva])->assertStatus(404);
        }
    }
}
