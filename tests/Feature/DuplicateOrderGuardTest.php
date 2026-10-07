<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\Orders\DuplicateOrderGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Duplicate-order guard: phone normalization, quantity-aware comparison and the per-phone lock.
 * The comparison runs on a real order of the development database; skipped when there is none.
 */
class DuplicateOrderGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';

    public function test_phones_are_compared_in_their_normalized_form(): void
    {
        $this->assertSame(['0612345678'], DuplicateOrderGuard::normalizePhones(['+212 612-345-678', '0612345678', '612345678', '']));
        $this->assertSame(['0612345678', '0698765432'], DuplicateOrderGuard::normalizePhones(['06 98 76 54 32', '+212612345678']));
    }

    public function test_signature_ignores_order_and_counts_quantities(): void
    {
        $a = DuplicateOrderGuard::signature([['id' => 7, 'quantity' => 1], ['id' => 3, 'quantity' => 2]]);
        $b = DuplicateOrderGuard::signature([['id' => 3, 'quantity' => 2], ['id' => 7, 'quantity' => 1]]);
        $c = DuplicateOrderGuard::signature([['id' => 3, 'quantity' => 1], ['id' => 7, 'quantity' => 1]]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        // the same product on two lines is the same as one line with the summed quantity
        $this->assertSame(['3' => 3] + [], array_map('intval', DuplicateOrderGuard::signature([['id' => 3, 'quantity' => 1], ['id' => 3, 'quantity' => 2]])));
    }

    public function test_an_existing_order_is_found_only_with_the_same_products_and_quantities(): void
    {
        config(['app.url' => 'http://localhost']);
        $user = User::where('email', self::EMAIL)->first();
        if (! $user || ! ($accountUser = $user->accountUsers()->first())) {
            $this->markTestSkipped('Reference user not available in this database.');
        }
        Passport::actingAs($user, [], 'api');

        $order = Order::where('account_id', $accountUser->account_id)->whereHas('phones')->whereHas('orderPvas')->with(['phones', 'orderPvas'])->latest('id')->first();
        if (! $order) {
            $this->markTestSkipped('The account has no order with a phone and products.');
        }

        $guard = new DuplicateOrderGuard();
        $phones = DuplicateOrderGuard::normalizePhones($order->phones->pluck('title')->all());
        $lines = $order->orderPvas->map(fn ($pva) => ['id' => $pva->product_variation_attribute_id, 'quantity' => $pva->quantity])->all();
        $window = 60 * 24 * 365 * 5; // the order is old: widen the window so the test does not depend on the date

        $this->assertNotNull($guard->findDuplicate($accountUser->account_id, $phones, $lines, $window), 'same products and quantities');

        $more = $lines;
        $more[0]['quantity']++;
        $this->assertNull($guard->findDuplicate($accountUser->account_id, $phones, $more, $window), 'one more of a product is a different order');

        $this->assertNull($guard->findDuplicate($accountUser->account_id, ['0600000000'], $lines, $window), 'another phone');
        $this->assertNull($guard->findDuplicate($accountUser->account_id, $phones, $lines, 0), 'outside the time window');
    }

    public function test_a_second_request_for_the_same_phone_cannot_take_the_lock(): void
    {
        $first = new DuplicateOrderGuard();
        $second = new DuplicateOrderGuard();
        $account = 987654;

        try {
            $this->assertTrue($first->acquire($account, ['0611111111'], 0));
            $this->assertFalse($second->acquire($account, ['0611111111'], 0), 'the phone is locked by the first request');
            $this->assertTrue($second->acquire($account, ['0622222222'], 0), 'another phone is free');
        } finally {
            $first->release();
            $second->release();
        }

        $third = new DuplicateOrderGuard();
        $this->assertTrue($third->acquire($account, ['0611111111'], 0), 'free again after release');
        $third->release();
    }

    private function createPayload(array $overrides = []): array
    {
        $user = User::where('email', self::EMAIL)->first();
        $accountUser = $user?->accountUsers()->first();
        $accountId = $accountUser?->account_id;
        if (! $accountId) {
            $this->markTestSkipped('Reference user not available in this database.');
        }

        // a product of the account with a variation whose attributes we can send
        $pva = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->join('account_user as au', 'au.id', '=', 'p.account_user_id')
            ->where('au.account_id', $accountId)->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->select('pva.id', 'pva.product_id', 'pva.variation_attribute_id')->first();
        $attributes = $pva ? DB::table('variation_attributes')->where('variation_attribute_id', $pva->variation_attribute_id)->pluck('attribute_id')->all() : [];
        $brandSource = DB::table('brand_source')->where('account_id', $accountId)->value('id');
        $city = DB::table('cities')->value('id');
        if (! $pva || ! $attributes || ! $brandSource || ! $city) {
            $this->markTestSkipped('The account needs a product with attributes, a brand source and a city.');
        }

        return array_replace_recursive([[
            'customer' => [
                'name' => 'TEST duplicate guard',
                'phones' => [['title' => '0600099911', 'principal' => true]],
                'addresses' => [['title' => 'test address', 'city_id' => $city, 'principal' => true]],
            ],
            'brand_source_id' => $brandSource,
            'payment_type_id' => 1,
            'payment_method_id' => 1,
            'order_status_id' => 1,
            'products' => [['id' => $pva->product_id, 'quantity' => 1, 'price' => 100, 'attributes' => $attributes]],
        ]], $overrides);
    }

    public function test_creating_the_same_order_twice_is_refused_even_with_another_phone_format(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->baseUrl = 'http://localhost';
        Passport::actingAs(User::where('email', self::EMAIL)->first(), [], 'api');

        $payload = $this->createPayload();

        $first = $this->postJson('/api/orders', $payload);
        $this->assertSame(200, $first->status(), json_encode($first->json()));
        $first->assertJsonPath('statut', 1);

        // same phone written differently, same products and quantities
        $again = $this->createPayload(['0' => ['customer' => ['phones' => [['title' => '+212 600099911']]]]]);
        $refused = $this->postJson('/api/orders', $again);
        $refused->assertStatus(422);
        $this->assertStringContainsString('Duplicate order', (string) $refused->json('data'));

        // another quantity is another order
        $more = $this->createPayload(['0' => ['products' => [['quantity' => 2]]]]);
        $this->postJson('/api/orders', $more)->assertOk()->assertJsonPath('statut', 1);
    }
}
