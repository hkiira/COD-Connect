<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WooCommerce\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * WooCommerce products and their links to CodConnect variations: match state, linking rules and tenancy.
 * WooCommerce is faked; runs against the development database in a rolled-back transaction.
 */
class WooCommerceProductTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BASE = 'https://shop.example.test/wp-json/wc/v3';

    private int $accountId;
    private int $storeId;
    private int $ownPvaId;
    private int $foreignPvaId;

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

        $own = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $this->accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')->value('pva.id');
        $foreign = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', '!=', $this->accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->whereNotIn('p.id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $this->accountId))
            ->value('pva.id');
        if (! $own || ! $foreign) {
            $this->markTestSkipped('Needs a variation of the account and one of another account.');
        }
        [$this->ownPvaId, $this->foreignPvaId] = [(int) $own, (int) $foreign];

        Passport::actingAs($user, [], 'api');

        $this->storeId = (int) $this->postJson('/api/wc/stores', [
            'name' => 'TEST shop', 'base_url' => self::BASE, 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x',
        ])->assertStatus(201)->json('data.id');
    }

    private function fakeCatalog(): void
    {
        Http::fake([
            self::BASE . '/products?*' => Http::response([
                ['id' => 100, 'name' => 'Variable shoe', 'sku' => '', 'type' => 'variable', 'variations' => [101, 102], 'images' => [], 'price' => '299'],
                ['id' => 200, 'name' => 'Simple shoe', 'sku' => 'S-1', 'type' => 'simple', 'variations' => [], 'images' => [], 'price' => '199'],
            ], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']),
            self::BASE . '/products/100/variations*' => Http::response([
                ['id' => 101, 'sku' => '', 'attributes' => [['name' => 'size', 'option' => '40']], 'price' => '299'],
                ['id' => 102, 'sku' => '', 'attributes' => [['name' => 'size', 'option' => '41']], 'price' => '299'],
            ], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']),
            self::BASE . '/products/100' => Http::response(['id' => 100, 'name' => 'Variable shoe', 'sku' => '', 'type' => 'variable', 'variations' => [101, 102]]),
        ]);
    }

    private function link(int $wcProduct, int $wcVariation, ?int $pva = null)
    {
        return $this->postJson("/api/wc/stores/{$this->storeId}/links", [
            'wc_product_id' => $wcProduct, 'wc_variation_id' => $wcVariation, 'product_variation_attribute_id' => $pva ?? $this->ownPvaId,
        ]);
    }

    public function test_products_show_their_match_state(): void
    {
        $this->fakeCatalog();

        $before = collect($this->getJson("/api/wc/stores/{$this->storeId}/products")->assertOk()->json('data'))->keyBy('wc_product_id');
        $this->assertSame('unmatched', $before[100]['state']);
        $this->assertSame(2, $before[100]['variations_total']);

        $this->link(100, 101)->assertOk();
        $partial = collect($this->getJson("/api/wc/stores/{$this->storeId}/products")->json('data'))->keyBy('wc_product_id');
        $this->assertSame('partial', $partial[100]['state']);
        $this->assertSame(1, $partial[100]['variations_linked']);

        $this->link(100, 102)->assertOk();
        $this->link(200, 0)->assertOk();
        $after = collect($this->getJson("/api/wc/stores/{$this->storeId}/products")->json('data'))->keyBy('wc_product_id');
        $this->assertSame('matched', $after[100]['state']);
        $this->assertSame('matched', $after[200]['state']);
    }

    public function test_unmatched_only_hides_the_matched_products(): void
    {
        $this->fakeCatalog();
        $this->link(200, 0)->assertOk();

        $rows = $this->getJson("/api/wc/stores/{$this->storeId}/products?unmatched=1")->assertOk()->json('data');

        $this->assertSame([100], array_column($rows, 'wc_product_id'));
    }

    public function test_the_variations_of_a_product_show_the_linked_variation(): void
    {
        $this->fakeCatalog();
        $this->link(100, 101)->assertOk();

        $rows = collect($this->getJson("/api/wc/stores/{$this->storeId}/products/100/variations")->assertOk()->json('data'))->keyBy('wc_variation_id');

        $this->assertSame($this->ownPvaId, $rows[101]['local']['product_variation_attribute_id']);
        $this->assertNull($rows[102]['local']);
        $this->assertSame(['40'], $rows[101]['options']);
    }

    public function test_linking_twice_moves_the_link_and_unlinking_removes_it(): void
    {
        $this->link(100, 101)->assertOk();
        $this->link(100, 101)->assertOk();
        $this->assertSame(1, DB::table('woocommerce_variation_links')->where('store_id', $this->storeId)->where('wc_variation_id', 101)->count());

        $linkId = DB::table('woocommerce_variation_links')->where('store_id', $this->storeId)->value('id');
        $this->deleteJson("/api/wc/stores/{$this->storeId}/links/{$linkId}")->assertOk();
        $this->assertSame(0, DB::table('woocommerce_variation_links')->where('store_id', $this->storeId)->count());
    }

    public function test_a_variation_of_another_account_cannot_be_linked(): void
    {
        $this->link(100, 101, $this->foreignPvaId)->assertStatus(422);
        $this->postJson("/api/wc/stores/{$this->storeId}/links", ['links' => [
            ['wc_product_id' => 100, 'wc_variation_id' => 101, 'product_variation_attribute_id' => $this->ownPvaId],
            ['wc_product_id' => 100, 'wc_variation_id' => 102, 'product_variation_attribute_id' => $this->foreignPvaId],
        ]])->assertStatus(422);
        $this->assertSame(0, DB::table('woocommerce_variation_links')->where('store_id', $this->storeId)->count(), 'a refused batch saves nothing');
    }

    public function test_the_same_wc_variation_can_be_linked_in_two_stores(): void
    {
        $other = $this->postJson('/api/wc/stores', ['name' => 'Second', 'base_url' => 'https://second.example.test/wp-json/wc/v3', 'consumer_key' => 'a', 'consumer_secret' => 'b'])->json('data.id');

        $this->link(100, 101)->assertOk();
        $this->postJson("/api/wc/stores/{$other}/links", ['wc_product_id' => 100, 'wc_variation_id' => 101, 'product_variation_attribute_id' => $this->ownPvaId])->assertOk();

        $this->assertSame(2, DB::table('woocommerce_variation_links')->where('wc_variation_id', 101)->whereIn('store_id', [$this->storeId, $other])->count());
    }

    public function test_the_links_of_a_foreign_store_are_out_of_reach(): void
    {
        $foreign = DB::table('woocommerce_stores')->insertGetId([
            'account_id' => $this->accountId + 100000, 'name' => 'Foreign', 'base_url' => 'https://other.test', 'consumer_key' => 'x', 'consumer_secret' => 'y',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/wc/stores/{$foreign}/products")->assertStatus(404);
        $this->postJson("/api/wc/stores/{$foreign}/links", ['wc_product_id' => 1, 'wc_variation_id' => 1, 'product_variation_attribute_id' => $this->ownPvaId])->assertStatus(404);
        $this->postJson("/api/wc/stores/{$foreign}/auto-match", ['product_ids' => [1]])->assertStatus(404);
    }

    public function test_auto_match_proposes_by_sku_without_saving(): void
    {
        $code = 'TESTSKU-' . $this->ownPvaId;
        DB::table('product_variation_attribute')->where('id', $this->ownPvaId)->update(['barcode' => $code]);

        Http::fake([
            self::BASE . '/products/100/variations*' => Http::response([
                ['id' => 101, 'sku' => $code, 'attributes' => [['name' => 'size', 'option' => '40']]],
                ['id' => 102, 'sku' => '', 'attributes' => [['name' => 'size', 'option' => '41']]],
            ], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']),
            self::BASE . '/products/100' => Http::response(['id' => 100, 'name' => 'Variable shoe', 'sku' => '', 'type' => 'variable', 'variations' => [101, 102]]),
        ]);

        $proposals = $this->postJson("/api/wc/stores/{$this->storeId}/auto-match", ['product_ids' => [100]])->assertOk()->json('data');

        $this->assertCount(1, $proposals);
        $this->assertSame(101, $proposals[0]['wc_variation_id']);
        $this->assertSame($this->ownPvaId, $proposals[0]['product_variation_attribute_id']);
        $this->assertSame('sku', $proposals[0]['reason']);
        $this->assertSame(0, DB::table('woocommerce_variation_links')->where('store_id', $this->storeId)->count(), 'a proposal is not a link');

        // once linked it is no longer proposed
        $this->link(100, 101)->assertOk();
        $this->assertSame([], $this->postJson("/api/wc/stores/{$this->storeId}/auto-match", ['product_ids' => [100]])->json('data'));
    }

    public function test_auto_match_finds_the_product_by_reference_and_the_variation_by_options(): void
    {
        $productId = (int) DB::table('product_variation_attribute')->where('id', $this->ownPvaId)->value('product_id');
        $options = DB::table('product_variation_attribute as pva')
            ->join('variation_attributes as va', 'va.variation_attribute_id', '=', 'pva.variation_attribute_id')
            ->join('attributes as a', 'a.id', '=', 'va.attribute_id')
            ->where('pva.id', $this->ownPvaId)->pluck('a.title')->all();
        if (! $options) {
            $this->markTestSkipped('The variation has no attributes.');
        }
        // the variation must be the only one of its product carrying all these options
        $sameOptions = DB::table('product_variation_attribute as pva')
            ->join('variation_attributes as va', 'va.variation_attribute_id', '=', 'pva.variation_attribute_id')
            ->join('attributes as a', 'a.id', '=', 'va.attribute_id')
            ->where('pva.product_id', $productId)->whereNull('pva.deleted_at')->whereIn('a.title', $options)
            ->groupBy('pva.id')->havingRaw('count(distinct a.title) = ?', [count(array_unique($options))])->pluck('pva.id');
        if ($sameOptions->count() !== 1) {
            $this->markTestSkipped('Several variations of the product carry the same options.');
        }

        DB::table('products')->where('id', $productId)->update(['reference' => 'TESTREF-' . $productId]);

        Http::fake([
            self::BASE . '/products/300/variations*' => Http::response([
                ['id' => 301, 'sku' => '', 'attributes' => array_map(fn ($o) => ['name' => 'x', 'option' => $o], array_values(array_unique($options)))],
            ], 200, ['X-WP-Total' => '1', 'X-WP-TotalPages' => '1']),
            self::BASE . '/products/300' => Http::response(['id' => 300, 'name' => 'By reference', 'sku' => 'TESTREF-' . $productId, 'type' => 'variable', 'variations' => [301]]),
        ]);

        $proposals = $this->postJson("/api/wc/stores/{$this->storeId}/auto-match", ['product_ids' => [300]])->assertOk()->json('data');

        $this->assertCount(1, $proposals);
        $this->assertSame($this->ownPvaId, $proposals[0]['product_variation_attribute_id']);
        $this->assertSame('attributes', $proposals[0]['reason']);
    }
}
