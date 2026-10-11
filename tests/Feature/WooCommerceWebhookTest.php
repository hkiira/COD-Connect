<?php

namespace Tests\Feature;

use App\Models\AccountUser;
use App\Models\User;
use App\Models\WooCommerce\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BASE = 'https://shop.example.test/wp-json/wc/v3';
    private const SECRET = 'cs_test_secret_123';

    private int $accountId;
    private int $storeId;
    private int $pvaId;
    private int $productId;
    private int $cityId;

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

        $brandSource = DB::table('brand_source')->where('account_id', $this->accountId)->value('id');
        $city = DB::table('cities')->value('id');
        if (! $pva || ! $brandSource || ! $city) {
            $this->markTestSkipped('The account needs a product with attributes, a brand source and a city.');
        }

        $this->pvaId = (int) $pva->id;
        $this->productId = (int) $pva->product_id;
        $this->cityId = (int) $city;

        $store = Store::create([
            'account_id' => $this->accountId,
            'name' => 'Webhook Test Store',
            'base_url' => self::BASE,
            'consumer_key' => 'ck_test',
            'consumer_secret' => self::SECRET,
            'is_active' => true,
            'default_brand_source_id' => $brandSource,
            'wc_status_after_import' => 'completed',
        ]);
        $this->storeId = (int) $store->id;
    }

    private function wcOrder(int $id, ?array $items = null): array
    {
        return [
            'id' => $id,
            'status' => 'processing',
            'total' => '350',
            'currency' => 'MAD',
            'date_created' => '2026-10-11T12:00:00',
            'shipping_total' => '30',
            'customer_note' => 'Livraison rapide svp',
            'billing' => [
                'first_name' => 'Mohamed',
                'last_name' => 'Amine',
                'phone' => '0612345678',
                'address_1' => 'Bd Zerktouni',
                'city' => (string) $this->cityId,
            ],
            'line_items' => $items ?? [
                ['id' => 1, 'product_id' => 500, 'variation_id' => 501, 'name' => 'Chaussure', 'sku' => 'CH-01', 'quantity' => 1, 'price' => '320']
            ],
        ];
    }

    public function test_rejects_missing_or_invalid_secret(): void
    {
        $this->postJson("/api/wc/stores/{$this->storeId}/webhook", ['wc_order_id' => 101])
            ->assertStatus(401);

        $this->postJson("/api/wc/stores/{$this->storeId}/webhook", ['wc_order_id' => 101], ['X-WC-Secret' => 'wrong_secret'])
            ->assertStatus(403);
    }

    public function test_blocks_import_when_product_is_unmatched_and_creates_notification(): void
    {
        Http::fake([
            self::BASE . '/orders/7701' => Http::response($this->wcOrder(7701, [
                ['id' => 1, 'product_id' => 9999, 'variation_id' => 9999, 'name' => 'Unlinked Item', 'sku' => '', 'quantity' => 1, 'price' => '100']
            ])),
        ]);

        $response = $this->postJson(
            "/api/wc/stores/{$this->storeId}/webhook",
            ['wc_order_id' => 7701],
            ['X-WC-Secret' => self::SECRET]
        );

        $response->assertStatus(422)
            ->assertJsonPath('statut', 0)
            ->assertJsonPath('unmatched_items', 1);

        $this->assertDatabaseMissing('woocommerce_order_links', [
            'store_id' => $this->storeId,
            'wc_order_id' => 7701,
        ]);

        // Verify database notification was created
        $user = AccountUser::where('account_id', $this->accountId)->first();
        $this->assertNotNull($user->notifications()->where('data->category', 'WooCommerce')->first());
    }

    public function test_successfully_imports_matched_order_and_creates_notification(): void
    {
        // Link the product
        DB::table('woocommerce_variation_links')->insert([
            'account_id' => $this->accountId,
            'store_id' => $this->storeId,
            'wc_product_id' => 500,
            'wc_variation_id' => 501,
            'product_variation_attribute_id' => $this->pvaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            self::BASE . '/orders/7702' => Http::sequence()
                ->push($this->wcOrder(7702))
                ->push($this->wcOrder(7702))
                ->push(['id' => 7702, 'status' => 'completed']),
            self::BASE . '/orders/7702?*' => Http::response($this->wcOrder(7702)),
        ]);

        $response = $this->postJson(
            "/api/wc/stores/{$this->storeId}/webhook",
            ['wc_order_id' => 7702],
            ['X-WC-Secret' => self::SECRET]
        );

        $response->assertOk()
            ->assertJsonPath('statut', 1);

        $this->assertDatabaseHas('woocommerce_order_links', [
            'store_id' => $this->storeId,
            'wc_order_id' => 7702,
        ]);
    }

    public function test_discover_stores_returns_stores_for_valid_secret(): void
    {
        $this->postJson('/api/wc/stores/discover')
            ->assertStatus(401);

        $response = $this->postJson(
            '/api/wc/stores/discover',
            ['site_url' => 'https://shop.example.test'],
            ['X-WC-Secret' => self::SECRET]
        );

        $response->assertOk()
            ->assertJsonPath('statut', 1)
            ->assertJsonPath('data.0.id', $this->storeId)
            ->assertJsonPath('data.0.name', 'Webhook Test Store');
    }

    public function test_test_mode_validates_connection_without_importing(): void
    {
        $response = $this->postJson(
            "/api/wc/stores/{$this->storeId}/webhook",
            ['test' => true],
            ['X-WC-Secret' => self::SECRET]
        );

        $response->assertOk()
            ->assertJsonPath('statut', 1)
            ->assertJsonPath('store.id', $this->storeId);
    }
}
