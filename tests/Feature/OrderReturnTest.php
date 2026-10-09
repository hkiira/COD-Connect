<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPva;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Returns and exchanges: ownership of what is referenced, which sales and how many pieces can come back,
 * what the refund and the exchange are worth, the status of the original order, the history notes, and
 * returns kept out of the workspaces. Runs against the development database in a rolled-back transaction,
 * on orders it creates.
 */
class OrderReturnTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    private int $accountId;
    private int $agentId;
    private int $customerId;
    private int $otherCustomerId;
    private array $variations;
    private object $template;
    private int $foreignLineId;

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

        $customers = DB::table('customers')->where('account_id', $this->accountId)->whereNull('deleted_at')
            ->orderByDesc('id')->limit(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->variations = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $this->accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->limit(2)->pluck('pva.id')->map(fn ($id) => (int) $id)->all();
        $template = DB::table('orders')->where('account_id', $this->accountId)->where('type', 'sale')->whereNull('deleted_at')
            ->whereNotNull('warehouse_id')->orderByDesc('id')->first();
        $foreign = DB::table('order_pva as op')->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('o.account_id', '!=', $this->accountId)->whereNull('o.deleted_at')->whereNull('op.deleted_at')->value('op.id');
        if (count($customers) < 2 || count($this->variations) < 2 || ! $template || ! $foreign) {
            $this->markTestSkipped('Needs two customers, two product variations and an order of the account, and a line of another account.');
        }
        [$this->customerId, $this->otherCustomerId] = $customers;
        $this->template = $template;
        $this->foreignLineId = (int) $foreign;

        Passport::actingAs($user, [], 'api');
    }

    /**
     * A sale of the customer: two pieces at 100 and one at 50 (250), a 50 DH discount, 30 DH shipping.
     * Each piece cost the customer 80% of its price: 80 and 40.
     *
     * @return array{0: Order, 1: int, 2: int} the order, the line of two pieces, the line of one
     */
    private function sale(int $status = 7, ?int $customerId = null): array
    {
        $order = Order::create([
            'account_id' => $this->accountId,
            'customer_id' => $customerId ?? $this->customerId,
            'order_status_id' => $status,
            'type' => 'sale',
            'code' => 'RT-TEST-' . uniqid(),
            'payment_type_id' => $this->template->payment_type_id,
            'payment_method_id' => $this->template->payment_method_id,
            'warehouse_id' => $this->template->warehouse_id,
            'brand_source_id' => $this->template->brand_source_id,
            'discount' => 50,
            'carrier_price' => 30,
        ]);
        $line = fn (int $variation, int $quantity, float $price, int $lineStatus) => (int) OrderPva::create([
            'order_id' => $order->id,
            'product_variation_attribute_id' => $variation,
            'quantity' => $quantity,
            'price' => $price,
            'order_status_id' => $lineStatus,
            'account_user_id' => $this->agentId,
        ])->id;
        $two = $line($this->variations[0], 2, 100, $status);
        $one = $line($this->variations[1], 1, 50, $status);
        // a removed line is not part of the sale
        $line($this->variations[1], 3, 10, 2);

        $phone = DB::table('phoneables')->where('phoneable_type', 'App\\Models\\Customer')->where('phoneable_id', $order->customer_id)->value('phone_id');
        if ($phone) {
            $order->phones()->attach($phone, ['statut' => 1]);
        }

        return [$order, $two, $one];
    }

    private function send(string $type, array $lines, array $extra = [], ?int $customerId = null)
    {
        return $this->postJson('/api/orders/exchange', [
            'customer_id' => $customerId ?? $this->customerId,
            'resolution_type' => $type,
            'items_to_return' => array_map(fn ($line) => ['source_order_pva_id' => $line[0], 'quantity' => $line[1]], $lines),
        ] + $extra);
    }

    private function exchangeItem(float $price, int $quantity = 1): array
    {
        return ['pva_id' => $this->variations[1], 'quantity' => $quantity, 'price' => $price];
    }

    public function test_a_foreign_line_or_customer_is_refused(): void
    {
        [, $two] = $this->sale();
        $foreignCustomer = (int) DB::table('customers')->where('account_id', '!=', $this->accountId)->value('id');

        $this->send('refund', [[$this->foreignLineId, 1]])->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return.0.source_order_pva_id']]);
        $this->send('refund', [[$two, 1]], [], $foreignCustomer)->assertStatus(422)->assertJsonStructure(['data' => ['customer_id']]);
        // a customer of the account, but not the one of the order
        $this->send('refund', [[$two, 1]], [], $this->otherCustomerId)->assertStatus(422)->assertJsonStructure(['data' => ['customer_id']]);
    }

    public function test_the_payload_the_old_return_form_sent_is_refused_not_half_processed(): void
    {
        [, $two] = $this->sale();

        $this->postJson('/api/orders/exchange', [
            'customer_id' => $this->customerId,
            'resolution_type' => 'Refund',
            'items_to_return' => [['order_pva_id' => $two, 'quantity' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, OrderPva::where('source_order_pva_id', $two)->count());
    }

    public function test_a_refund_is_worth_what_the_customer_paid_and_leaves_a_trace(): void
    {
        [$sale, $two, $one] = $this->sale();

        $data = $this->send('refund', [[$two, 1], [$one, 1]])->assertOk()->json('data');

        // 100 and 50 less their share of the 50 DH discount (20%); shipping is not refunded
        $this->assertEquals(120, $data['returned_value']);
        $this->assertEquals(120, $data['refund_due']);
        $this->assertEquals(0, $data['to_collect']);
        $this->assertNull($data['exchange_order_id']);

        $return = Order::find($data['return_order_id']);
        $this->assertSame('return', $return->type);
        $this->assertSame($sale->id, (int) $return->order_id);
        $this->assertSame(6, (int) $return->order_status_id);
        $this->assertSame($sale->code . '-RT', $return->code);
        $this->assertSame($data['return_order_code'], $return->code);
        $this->assertStringContainsString('120,00 DH', (string) $return->note);
        $this->assertEqualsCanonicalizing([$two, $one], $return->orderPvas()->pluck('source_order_pva_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0.0, (float) $return->orderPvas()->sum('price'));
        $this->assertEquals($sale->activePhones()->pluck('phones.id')->all(), $return->activePhones()->pluck('phones.id')->all());

        // the sale keeps its status and says what happened
        $this->assertSame(7, (int) $sale->fresh()->order_status_id);
        $note = DB::table('order_comment')->where('order_id', $sale->id)->latest('id')->first();
        $this->assertSame(config('orders.return_comment'), (int) $note->comment_id);
        $this->assertSame(7, (int) $note->order_status_id);
        $this->assertStringContainsString($return->code, $note->title);
        $this->assertTrue(DB::table('order_comment')->where('order_id', $return->id)->exists());
    }

    public function test_never_more_pieces_than_sold_less_those_already_back(): void
    {
        [$sale, $two, $one] = $this->sale();

        // the same line twice counts once, with both quantities
        $this->send('refund', [[$two, 1], [$two, 2]])->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return.0.quantity']]);
        $first = $this->send('refund', [[$two, 1]])->assertOk()->json('data');
        $this->assertSame(['Only 1 piece(s) of this product can still be returned.'], $this->send('refund', [[$two, 2]])->assertStatus(422)->json('data')['items_to_return.0.quantity']);
        $this->send('refund', [[$two, 1]])->assertOk();
        $this->assertSame(['This product has already been returned.'], $this->send('refund', [[$two, 1]])->assertStatus(422)->json('data')['items_to_return.0.quantity']);

        // a cancelled return gives its piece back
        Order::whereKey($first['return_order_id'])->update(['order_status_id' => 8]);
        $this->send('refund', [[$two, 1]])->assertOk();

        // what the order page and the customer page show
        $line = collect($this->getJson('/api/orders/' . $sale->id . '/edit?orderInfo=1&products[active][search]=')->assertOk()->json('data.products.active.data'))
            ->flatMap(fn ($product) => $product['productVariations'])->firstWhere('id', $two);
        $this->assertSame(2, $line['returned_quantity']);
        $this->assertSame(0, $line['returnable_quantity']);
        $this->assertEquals(80, $line['refund_unit_price']);

        $order = collect($this->getJson('/api/customers/' . $this->customerId)->assertOk()->json('data.orders.data'))->firstWhere('id', $sale->id);
        $this->assertNotNull($order, 'the sale is on the first page of the customer');
        $this->assertTrue($order['returnable'], 'the other line can still come back');
        $this->assertSame(7, $order['status_id']);
        $this->assertEquals(230, $order['total'], '250 of products less 50 of discount plus 30 of shipping, the removed line left out');
        $this->assertCount(2, $order['products']);
        $this->assertSame(1, collect($order['products'])->firstWhere('order_pva_id', $one)['returnable_quantity']);
    }

    public function test_one_sale_at_a_time_that_the_customer_has(): void
    {
        [$sale, $two] = $this->sale();
        [, $otherTwo] = $this->sale();
        $this->send('refund', [[$two, 1], [$otherTwo, 1]])->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return']]);

        foreach ([1, 4, 8, 11] as $status) {
            [, $line] = $this->sale($status);
            $this->send('refund', [[$line, 1]])->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return']]);
        }

        // a return cannot be returned
        $return = $this->send('refund', [[$two, 1]])->assertOk()->json('data.return_order_id');
        $returnLine = (int) OrderPva::where('order_id', $return)->value('id');
        $this->send('refund', [[$returnLine, 1]])->assertStatus(422);

        // a removed line is not part of the sale
        $removed = (int) OrderPva::where('order_id', $sale->id)->where('order_status_id', 2)->value('id');
        $this->send('refund', [[$removed, 1]])->assertStatus(422)->assertJsonStructure(['data' => ['items_to_return.0.source_order_pva_id']]);
    }

    public function test_an_exchange_collects_the_difference_and_the_shipping(): void
    {
        [$sale, $two] = $this->sale();

        // one piece back (worth 80) for a product at 150, 30 of shipping: 70 + 30 to collect
        $data = $this->send('exchange', [[$two, 1]], ['carrier_price' => 30, 'items_to_exchange' => [$this->exchangeItem(150)]])->assertOk()->json('data');
        $this->assertEquals(100, $data['to_collect']);
        $this->assertEquals(0, $data['refund_due']);

        $exchange = Order::find($data['exchange_order_id']);
        $this->assertSame('sale', $exchange->type);
        $this->assertSame(1, (int) $exchange->order_status_id);
        $this->assertSame($sale->id, (int) $exchange->order_id);
        $this->assertSame($sale->code . '-EX', $exchange->code);
        $this->assertEquals(30, $exchange->carrier_price);
        $this->assertEquals(80, $exchange->discount);
        $this->assertEquals([150.0], $exchange->orderPvas()->pluck('price')->map(fn ($price) => (float) $price)->all());
        $this->assertEquals($sale->activePhones()->pluck('phones.id')->all(), $exchange->activePhones()->pluck('phones.id')->all());
        $this->assertStringContainsString($sale->code, (string) $exchange->note);
        $this->assertSame(config('orders.exchange_comment'), (int) DB::table('order_comment')->where('order_id', $sale->id)->latest('id')->value('comment_id'));

        // the order list computes the same amount to collect
        $row = collect($this->getJson('/api/orders?' . http_build_query(['search' => $exchange->code, 'pagination' => ['per_page' => 10, 'current_page' => 0]]))->json('data'))->firstWhere('id', $exchange->id);
        $this->assertEquals(100, $row['total']);
    }

    public function test_a_cheaper_exchange_leaves_a_refund_due(): void
    {
        [, $two] = $this->sale();

        // two pieces back (160) for one product at 50 and no shipping: nothing to collect, 110 to give back
        $data = $this->send('exchange', [[$two, 2]], ['items_to_exchange' => [$this->exchangeItem(50)]])->assertOk()->json('data');
        $this->assertEquals(0, $data['to_collect']);
        $this->assertEquals(110, $data['refund_due']);

        $exchange = Order::find($data['exchange_order_id']);
        $this->assertEquals(50, $exchange->discount, 'the credit never exceeds the new products');
        $this->assertStringContainsString('110,00 DH', (string) Order::find($data['return_order_id'])->note);
    }

    public function test_the_original_order_is_delivered_when_it_was_in_delivery_and_a_paid_one_stays_paid(): void
    {
        [$inDelivery, $line] = $this->sale(6);
        $this->send('exchange', [[$line, 1]], ['items_to_exchange' => [$this->exchangeItem(100)]])->assertOk();
        $this->assertSame(7, (int) $inDelivery->fresh()->order_status_id);

        [$paid, $paidLine] = $this->sale(10);
        $this->send('exchange', [[$paidLine, 1]], ['items_to_exchange' => [$this->exchangeItem(100)]])->assertOk();
        $this->assertSame(10, (int) $paid->fresh()->order_status_id, 'an exchange must not take a paid order back to delivered');
    }

    public function test_an_exchange_item_can_be_sent_as_product_and_attributes(): void
    {
        [, $two] = $this->sale();
        $pva = DB::table('product_variation_attribute')->where('id', $this->variations[0])->first();
        $attributes = DB::table('variation_attributes')->where('variation_attribute_id', $pva->variation_attribute_id)->pluck('attribute_id')->all();
        if (! $attributes) {
            $this->markTestSkipped('The product variation has no attributes.');
        }

        $data = $this->send('exchange', [[$two, 1]], ['items_to_exchange' => [['product_id' => $pva->product_id, 'attributes' => $attributes, 'quantity' => 1, 'price' => 100]]])
            ->assertOk()->json('data');
        $this->assertNotNull($data['exchange_order_id']);

        // attributes that match no variation are refused before anything is written
        $this->send('exchange', [[$two, 1]], ['items_to_exchange' => [['product_id' => $pva->product_id, 'attributes' => [DB::table('attributes')->max('id')], 'quantity' => 1]]])
            ->assertStatus(422);
    }

    public function test_the_list_can_be_filtered_on_type_and_the_workspaces_leave_returns_out(): void
    {
        [, $two] = $this->sale();
        $return = Order::find($this->send('refund', [[$two, 1]])->assertOk()->json('data.return_order_id'));

        $rows = $this->getJson('/api/orders?types[]=return&pagination[per_page]=20&pagination[current_page]=0')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('return', $row['type']);
        }

        // in delivery without a tracking number, yet not a parcel to follow
        foreach (['tracking.in_delivery', 'tracking.no_tracking'] as $queue) {
            $found = collect($this->getJson('/api/orders?' . http_build_query([
                'queue' => $queue, 'search' => $return->code, 'pagination' => ['per_page' => 50, 'current_page' => 0],
            ]))->assertOk()->json('data'))->firstWhere('id', $return->id);
            $this->assertNull($found, "a return is not in $queue");
        }
    }
}
