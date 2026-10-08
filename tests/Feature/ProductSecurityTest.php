<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Product create / ownership rules. Runs against the development database inside a
 * transaction that is rolled back, so nothing is kept. Skipped when the reference
 * user or data is missing.
 */
class ProductSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $foreignProductId;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries the WAMP sub-path (/codconnect/public), which would prefix every test URL.
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->baseUrl = 'http://localhost';

        $user = User::where('email', self::EMAIL)->first();
        if (! $user || ! ($accountUser = $user->accountUsers()->first())) {
            $this->markTestSkipped('Reference user not available in this database.');
        }

        $this->accountId = (int) $accountUser->account_id;

        $foreign = DB::table('products')
            ->whereNull('deleted_at')
            ->whereNotIn('id', DB::table('account_product')->where('account_id', $this->accountId)->select('product_id'))
            ->whereNotIn('account_user_id', DB::table('account_user')->where('account_id', $this->accountId)->select('id'))
            ->value('id');

        if (! $foreign) {
            $this->markTestSkipped('No product from another account to test against.');
        }

        $this->foreignProductId = (int) $foreign;

        Passport::actingAs($user, [], 'api');
    }

    private function accountUserIds()
    {
        return DB::table('account_user')->where('account_id', $this->accountId)->pluck('id');
    }

    public function test_create_keeps_brand_category_and_warehouse(): void
    {
        $brand = DB::table('brands')->where('account_id', $this->accountId)->where('statut', 1)->whereNull('deleted_at')->value('id');
        $taxonomy = DB::table('taxonomies')->whereIn('account_user_id', $this->accountUserIds())->whereNull('deleted_at')->value('id');
        $warehouse = DB::table('warehouses')->where('account_id', $this->accountId)->where('warehouse_type_id', 1)->whereNull('deleted_at')->value('id');
        $attributes = DB::table('attributes')->whereIn('account_user_id', $this->accountUserIds())->whereNull('deleted_at')
            ->orderBy('id')->limit(40)->get(['id', 'types_attribute_id'])
            ->groupBy('types_attribute_id')->map(fn ($g) => $g->first()->id)->values()->take(2)->all();

        if (! $brand || ! $taxonomy || ! $warehouse || count($attributes) < 1) {
            $this->markTestSkipped('Account lacks the brand/category/warehouse/attributes needed.');
        }

        // exactly the payload shape the product form sends
        $response = $this->postJson('/api/products', [[
            'title'              => 'TEST product',
            'reference'          => 'TST-1',
            'price'              => 100,
            'statut'             => 1,
            'brandsToActive'     => [$brand],
            'taxonomiesToActive' => [$taxonomy],
            'warehousesToActive' => [$warehouse],
            'attributes'         => $attributes,
        ]]);

        $response->assertOk()->assertJsonPath('statut', 1);

        $productId = $response->json('data.0.id');
        $accountProductId = DB::table('account_product')->where('product_id', $productId)->value('id');

        $this->assertGreaterThan(0, DB::table('product_brand_source')->where('product_id', $productId)->count(), 'brand lost');
        $this->assertSame(1, DB::table('taxonomy_product')->where('account_product_id', $accountProductId)->count(), 'category lost');
        $this->assertGreaterThan(0, DB::table('warehouse_pva as w')
            ->join('product_variation_attribute as p', 'p.id', '=', 'w.product_variation_attribute_id')
            ->where('p.product_id', $productId)->count(), 'warehouse lost');
    }

    public function test_invalid_create_returns_422(): void
    {
        $this->postJson('/api/products', [['reference' => 'X', 'price' => 1, 'statut' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('statut', 0);
    }

    public function test_the_list_can_be_searched_and_stays_in_the_account(): void
    {
        $product = DB::table('products')->whereIn('account_user_id', $this->accountUserIds())->whereNull('deleted_at')->whereNotNull('title')->first();
        if (! $product) {
            $this->markTestSkipped('The account has no product.');
        }

        // the order and exchange screens search by name or reference
        $rows = $this->getJson('/api/products?' . http_build_query(['search' => $product->title, 'pagination' => ['per_page' => 50, 'current_page' => 0]]))
            ->assertOk()->json('data');

        $this->assertContains((int) $product->id, array_map(fn ($row) => (int) $row['id'], $rows));
        $this->assertNotContains($this->foreignProductId, array_map(fn ($row) => (int) $row['id'], $rows));
    }

    public function test_foreign_product_cannot_be_read_for_edit(): void
    {
        $this->getJson("/api/products/{$this->foreignProductId}/edit?productInfo=1")->assertNotFound();
    }

    public function test_foreign_product_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/products/{$this->foreignProductId}")->assertNotFound();

        $this->assertNull(DB::table('products')->where('id', $this->foreignProductId)->value('deleted_at'));
    }

    public function test_foreign_product_cannot_be_updated(): void
    {
        $before = DB::table('products')->where('id', $this->foreignProductId)->value('title');

        $this->putJson("/api/products/{$this->foreignProductId}", [[
            'id' => $this->foreignProductId, 'title' => 'HACKED', 'reference' => 'HK', 'price' => 1, 'statut' => 1,
        ]])->assertStatus(422);

        $this->assertSame($before, DB::table('products')->where('id', $this->foreignProductId)->value('title'));
    }
}
