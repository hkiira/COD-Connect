<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Returns and exchanges: ownership of what is referenced, type, unique codes, and the "types" list filter.
 * Runs against the development database in a rolled-back transaction.
 */
class OrderReturnTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $ownLineId;
    private int $ownCustomerId;
    private int $foreignLineId;
    private int $foreignCustomerId;

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

        $own = DB::table('order_pva as op')->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('o.account_id', $this->accountId)->whereNull('o.deleted_at')->whereNull('op.deleted_at')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')->where('c.account_id', $this->accountId)
            ->where('o.type', 'sale')->select('op.id', 'o.customer_id')->first();
        $foreign = DB::table('order_pva as op')->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('o.account_id', '!=', $this->accountId)->whereNull('o.deleted_at')->whereNull('op.deleted_at')
            ->whereNotNull('o.customer_id')->select('op.id', 'o.customer_id')->first();
        if (! $own || ! $foreign) {
            $this->markTestSkipped('Needs an order line of the account and one of another account.');
        }
        [$this->ownLineId, $this->ownCustomerId] = [(int) $own->id, (int) $own->customer_id];
        [$this->foreignLineId, $this->foreignCustomerId] = [(int) $foreign->id, (int) $foreign->customer_id];

        Passport::actingAs($user, [], 'api');
    }

    private function refund(int $lineId, int $customerId)
    {
        return $this->postJson('/api/orders/exchange', [
            'customer_id' => $customerId,
            'resolution_type' => 'refund',
            'items_to_return' => [['source_order_pva_id' => $lineId, 'quantity' => 1]],
        ]);
    }

    public function test_a_foreign_line_or_customer_is_refused(): void
    {
        $this->refund($this->foreignLineId, $this->ownCustomerId)->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return.0.source_order_pva_id']]);
        $this->refund($this->ownLineId, $this->foreignCustomerId)->assertStatus(422)->assertJsonStructure(['data' => ['customer_id']]);
    }

    public function test_the_payload_the_old_return_form_sent_is_refused_not_half_processed(): void
    {
        $this->postJson('/api/orders/exchange', [
            'customer_id' => $this->ownCustomerId,
            'resolution_type' => 'Refund',
            'items_to_return' => [['order_pva_id' => $this->ownLineId, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_a_return_is_typed_and_a_second_return_gets_its_own_code(): void
    {
        $first = $this->refund($this->ownLineId, $this->ownCustomerId);
        $second = $this->refund($this->ownLineId, $this->ownCustomerId);

        $first->assertOk();
        $second->assertOk();

        $returns = Order::where('type', 'return')->where('account_id', $this->accountId)->latest('id')->take(2)->get();
        $this->assertCount(2, $returns);
        $this->assertNotSame($returns[0]->code, $returns[1]->code, 'two returns on one order need two codes');
        $this->assertStringEndsWith('-RT', $returns[1]->code);
    }

    public function test_the_list_can_be_filtered_on_type(): void
    {
        $this->refund($this->ownLineId, $this->ownCustomerId)->assertOk();

        $rows = $this->getJson('/api/orders?types[]=return&pagination[per_page]=20&pagination[current_page]=0')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('return', $row['type']);
        }
    }

    public function test_an_exchange_item_can_be_sent_as_product_and_attributes(): void
    {
        $pva = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $this->accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->select('pva.product_id', 'pva.variation_attribute_id')->first();
        $attributes = $pva ? DB::table('variation_attributes')->where('variation_attribute_id', $pva->variation_attribute_id)->pluck('attribute_id')->all() : [];
        if (! $pva || ! $attributes) {
            $this->markTestSkipped('The account needs a product with attributes.');
        }

        $response = $this->postJson('/api/orders/exchange', [
            'customer_id' => $this->ownCustomerId,
            'resolution_type' => 'exchange',
            'items_to_return' => [['source_order_pva_id' => $this->ownLineId, 'quantity' => 1]],
            'items_to_exchange' => [['product_id' => $pva->product_id, 'attributes' => $attributes, 'quantity' => 1, 'price' => 100]],
        ]);

        $response->assertOk();
        $this->assertTrue(Order::where('account_id', $this->accountId)->where('type', 'sale')->where('code', 'like', '%-EX%')->exists());

        // attributes that match no variation are refused before anything is written
        $this->postJson('/api/orders/exchange', [
            'customer_id' => $this->ownCustomerId,
            'resolution_type' => 'exchange',
            'items_to_return' => [['source_order_pva_id' => $this->ownLineId, 'quantity' => 1]],
            'items_to_exchange' => [['product_id' => $pva->product_id, 'attributes' => [DB::table('attributes')->max('id')], 'quantity' => 1]],
        ])->assertStatus(422);
    }
}
