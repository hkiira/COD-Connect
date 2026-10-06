<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Product create / update behaviour (references, price history, relations).
 * Runs against the development database inside a rolled-back transaction.
 */
class ProductWriteTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private $attributes;
    private ?int $brand;
    private ?int $taxonomy;
    private ?int $warehouse;

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
        $users = DB::table('account_user')->where('account_id', $this->accountId)->pluck('id');

        $this->brand = DB::table('brands')->where('account_id', $this->accountId)->where('statut', 1)->whereNull('deleted_at')->value('id');
        $this->taxonomy = DB::table('taxonomies')->whereIn('account_user_id', $users)->whereNull('deleted_at')->value('id');
        $this->warehouse = DB::table('warehouses')->where('account_id', $this->accountId)->where('warehouse_type_id', 1)->whereNull('deleted_at')->value('id');
        $this->attributes = DB::table('attributes')->whereIn('account_user_id', $users)->whereNull('deleted_at')
            ->orderBy('id')->limit(40)->get(['id', 'types_attribute_id'])
            ->groupBy('types_attribute_id')->map(fn ($g) => $g->first()->id)->values()->take(2)->all();

        if (! $this->brand || ! $this->taxonomy || ! $this->warehouse || ! $this->attributes) {
            $this->markTestSkipped('Account lacks the data needed.');
        }

        Passport::actingAs($user, [], 'api');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'              => 'TEST write product',
            'reference'          => 'TST-W-' . uniqid(),
            'price'              => 100,
            'statut'             => 1,
            'attributes'         => $this->attributes,
            'taxonomiesToActive' => [$this->taxonomy],
        ], $overrides);
    }

    private function createViaApi(array $overrides = []): int
    {
        $response = $this->postJson('/api/products', [$this->payload($overrides)])->assertOk();

        return (int) $response->json('data.0.id');
    }

    public function test_update_changes_price_with_history_and_adds_relations(): void
    {
        $id = $this->createViaApi();

        $this->putJson("/api/products/{$id}", [[
            'id'                 => $id,
            'title'              => 'TEST write product renamed',
            'price'              => 150,
            'statut'             => 1,
            // update() rebuilds the variations from "attributes" (as the form always sends them);
            // leaving it out deactivates every variation of the product
            'attributes'         => $this->attributes,
            'brandsToActive'     => [$this->brand],
            'warehousesToActive' => [$this->warehouse],
        ]])->assertOk()->assertJsonPath('statut', 1);

        $this->assertSame('TEST write product renamed', DB::table('products')->where('id', $id)->value('title'));

        $activeOffers = DB::table('offerables as ofr')->join('offers as o', 'o.id', '=', 'ofr.offer_id')
            ->where('ofr.offerable_type', 'App\\Models\\Product')->where('ofr.offerable_id', $id)
            ->where('o.offer_type_id', 1)->where('o.statut', 1)->pluck('o.price');
        $this->assertSame([150.0], $activeOffers->map(fn ($p) => (float) $p)->all(), 'only the new price stays active');

        $this->assertGreaterThan(0, DB::table('product_brand_source')->where('product_id', $id)->count(), 'brand not added');
        $this->assertGreaterThan(0, DB::table('warehouse_pva as w')
            ->join('product_variation_attribute as p', 'p.id', '=', 'w.product_variation_attribute_id')
            ->where('p.product_id', $id)->count(), 'warehouse not added');
    }

    public function test_api_refuses_a_duplicate_reference(): void
    {
        $reference = 'TST-DUP-' . uniqid();
        $this->createViaApi(['reference' => $reference]);

        $this->postJson('/api/products', [$this->payload(['reference' => $reference])])
            ->assertStatus(422)
            ->assertJsonPath('statut', 0)
            ->assertJsonStructure(['data' => ['0.reference']]);
    }

    public function test_machine_imports_may_repeat_a_reference(): void
    {
        $reference = 'TST-IMP-' . uniqid();
        $this->createViaApi(['reference' => $reference]);

        // ImportController calls store() with a plain Request: no route, so references are not enforced
        $response = ProductController::store(new Request([$this->payload(['reference' => $reference])]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, DB::table('products')->where('reference', $reference)->count());
    }

    public function test_an_unchanged_duplicate_reference_does_not_block_an_edit(): void
    {
        $reference = 'TST-OLD-' . uniqid();
        $first = $this->createViaApi(['reference' => $reference]);
        ProductController::store(new Request([$this->payload(['reference' => $reference, 'title' => 'TEST write other'])]));

        $this->putJson("/api/products/{$first}", [[
            'id' => $first, 'reference' => $reference, 'title' => 'TEST write edited', 'price' => 100, 'statut' => 1,
        ]])->assertOk();

        $this->assertSame('TEST write edited', DB::table('products')->where('id', $first)->value('title'));
    }

    public function test_changing_to_a_reference_used_elsewhere_is_refused(): void
    {
        $taken = 'TST-TAKEN-' . uniqid();
        $this->createViaApi(['reference' => $taken]);
        $other = $this->createViaApi();

        $this->putJson("/api/products/{$other}", [[
            'id' => $other, 'reference' => $taken, 'title' => 'TEST write mover', 'price' => 100, 'statut' => 1,
        ]])->assertStatus(422)->assertJsonStructure(['data' => ['0.reference']]);
    }
}
