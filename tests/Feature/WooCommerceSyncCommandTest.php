<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * wc:sync-stores: only stores with auto-import on, only orders that are new and fully matched.
 * WooCommerce is faked; runs against the development database in a rolled-back transaction.
 */
class WooCommerceSyncCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BASE = 'https://shop.example.test/wp-json/wc/v3';

    private int $storeId;
    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->baseUrl = 'http://localhost';

        $user = User::where('email', self::EMAIL)->first();
        $accountId = $user?->accountUsers()->first()?->account_id;
        $pva = $accountId ? DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')->value('pva.id') : null;
        $brandSource = $accountId ? DB::table('brand_source')->where('account_id', $accountId)->value('id') : null;
        $city = DB::table('cities')->value('id');
        if (! $pva || ! $brandSource || ! $city) {
            $this->markTestSkipped('Needs the reference account with a product, a brand source and a city.');
        }
        $this->cityId = (int) $city;

        Passport::actingAs($user, [], 'api');
        $this->storeId = (int) $this->postJson('/api/wc/stores', [
            'name' => 'TEST auto', 'base_url' => self::BASE, 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x',
            'default_brand_source_id' => $brandSource, 'auto_import' => true, 'import_statuses' => ['processing'],
        ])->assertStatus(201)->json('data.id');
        $this->postJson("/api/wc/stores/{$this->storeId}/links", ['wc_product_id' => 500, 'wc_variation_id' => 501, 'product_variation_attribute_id' => $pva])->assertOk();
    }

    private function wcOrder(int $id, int $variation): array
    {
        return [
            'id' => $id, 'status' => 'processing', 'total' => '299', 'currency' => 'MAD', 'date_created' => '2026-10-07T10:00:00', 'shipping_total' => '0',
            'billing' => ['first_name' => 'TEST', 'last_name' => 'Auto', 'phone' => '0600066' . str_pad((string) ($id % 1000), 3, '0', STR_PAD_LEFT), 'address_1' => 'street', 'city' => (string) $this->cityId],
            'line_items' => [['id' => 1, 'product_id' => 500, 'variation_id' => $variation, 'name' => 'Shoe', 'sku' => '', 'quantity' => 1, 'price' => '299']],
        ];
    }

    private function fakeStore(): void
    {
        Http::fake([
            self::BASE . '/orders?*' => Http::response([$this->wcOrder(8001, 501), $this->wcOrder(8002, 999)], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']),
            self::BASE . '/orders/8001' => Http::sequence()->push($this->wcOrder(8001, 501))->push(['id' => 8001, 'status' => 'completed']),
        ]);
    }

    public function test_a_dry_run_counts_the_matched_new_orders_and_changes_nothing(): void
    {
        $this->fakeStore();

        Artisan::call('wc:sync-stores', ['--store' => $this->storeId, '--dry-run' => true]);

        $this->assertStringContainsString('would import 1 order', Artisan::output());
        $this->assertSame(0, DB::table('woocommerce_order_links')->where('store_id', $this->storeId)->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_the_run_imports_only_the_matched_order_and_marks_the_store_checked(): void
    {
        $this->fakeStore();

        Artisan::call('wc:sync-stores', ['--store' => $this->storeId]);

        $this->assertDatabaseHas('woocommerce_order_links', ['store_id' => $this->storeId, 'wc_order_id' => 8001]);
        $this->assertDatabaseMissing('woocommerce_order_links', ['store_id' => $this->storeId, 'wc_order_id' => 8002]);
        $this->assertNotNull(DB::table('woocommerce_stores')->where('id', $this->storeId)->value('last_checked_at'));
        $this->assertNull(DB::table('woocommerce_stores')->where('id', $this->storeId)->value('last_error'));
    }

    public function test_a_store_without_auto_import_is_left_alone(): void
    {
        $this->putJson("/api/wc/stores/{$this->storeId}", ['auto_import' => false])->assertOk();
        Http::fake();

        Artisan::call('wc:sync-stores', ['--store' => $this->storeId]);

        Http::assertNothingSent();
    }
}
