<?php

namespace Tests\Feature;

use App\Http\Controllers\AfraShippingController;
use App\Models\AfraSyncRun;
use App\Models\Order;
use App\Models\User;
use App\Services\AfraShippingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AfraShippingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'services.afra.base_url' => 'https://afradelivery.com/api/seller',
            'services.afra.ca_bundle' => null,
        ]);
        DB::purge('sqlite');
        $this->schema();
        DB::table('account_user')->insert(['id' => 1, 'account_id' => 1, 'user_id' => 1]);
        DB::table('account_carrier')->insert(['id' => 1, 'account_id' => 1, 'carrier_id' => 26,
            'username' => 'seller@example.com', 'password' => Crypt::encryptString('secret'), 'autocode' => 0]);
        DB::table('cities')->insert([['id' => 1, 'title' => 'Rabat'], ['id' => 2, 'title' => 'Casa']]);
        DB::table('default_carriers')->insert([
            ['id' => 1, 'carrier_id' => 26, 'city_id' => 1, 'city_id_carrier' => 101, 'price' => 32],
            ['id' => 2, 'carrier_id' => 26, 'city_id' => 2, 'city_id_carrier' => null, 'price' => 40],
        ]);
        DB::table('order_statuses')->insert([['id' => 1, 'title' => 'En attente'], ['id' => 9, 'title' => 'En souffrance']]);
        $this->makeOrder(1);
    }

    private function schema(): void
    {
        Schema::create('account_user', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('user_id')]);
        Schema::create('account_carrier', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('carrier_id'), $t->string('username')->nullable(), $t->string('password')->nullable(), $t->integer('autocode')->default(0), $t->integer('statut')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('cities', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('default_carriers', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('carrier_id'), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('city_id_carrier')->nullable(), $t->integer('price')->default(0), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_statuses', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('customers', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('phones', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('addresses', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('city_id'), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('phoneables', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('phone_id'), $t->unsignedBigInteger('phoneable_id'), $t->string('phoneable_type'), $t->integer('statut')->default(1), $t->timestamps()]);
        Schema::create('addressables', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('address_id'), $t->unsignedBigInteger('addressable_id'), $t->string('addressable_type'), $t->integer('statut')->default(1), $t->timestamps()]);
        Schema::create('products', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('product_variation_attribute', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('pickups', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_user_id'), $t->unsignedBigInteger('carrier_id'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('orders', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('customer_id'), $t->unsignedBigInteger('pickup_id'), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('order_status_id'), $t->string('shipping_code')->nullable(), $t->decimal('discount', 10, 2)->default(0), $t->decimal('carrier_price', 10, 2)->default(0), $t->text('note')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_pva', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_variation_attribute_id'), $t->unsignedBigInteger('order_status_id'), $t->integer('quantity'), $t->decimal('price', 10, 2), $t->decimal('realprice', 10, 2)->nullable(), $t->decimal('initial_price', 10, 2)->nullable(), $t->decimal('discount', 10, 2)->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_comment', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('account_user_id'), $t->unsignedBigInteger('order_status_id'), $t->string('title')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('afra_status_mappings', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('afra_status_id'), $t->unsignedBigInteger('order_status_id'), $t->timestamps()]);
        Schema::create('afra_sync_runs', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('account_user_id')->nullable(), $t->unsignedBigInteger('pickup_id')->nullable(), $t->string('kind'), $t->string('status'), $t->integer('processed')->default(0), $t->integer('synchronized')->default(0), $t->integer('skipped')->default(0), $t->integer('failed')->default(0), $t->text('message')->nullable(), $t->timestamps()]);
        Schema::create('afra_order_operations', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id')->unique(), $t->string('create_state')->nullable(), $t->string('return_state')->nullable(), $t->text('last_error')->nullable(), $t->timestamps()]);
    }

    private function makeOrder(int $id, int $city = 1): void
    {
        DB::table('customers')->insert(['id' => $id, 'name' => 'Client '.$id]);
        DB::table('phones')->insert(['id' => $id, 'title' => '0612345678']);
        DB::table('phoneables')->insert(['phone_id' => $id, 'phoneable_id' => $id, 'phoneable_type' => 'App\\Models\\Customer', 'statut' => 1]);
        DB::table('addresses')->insert(['id' => $id, 'city_id' => $city, 'title' => 'Rue '.$id]);
        DB::table('addressables')->insert(['address_id' => $id, 'addressable_id' => $id, 'addressable_type' => 'App\\Models\\Customer', 'statut' => 1]);
        DB::table('products')->insert(['id' => $id, 'title' => 'Produit '.$id]);
        DB::table('product_variation_attribute')->insert(['id' => $id, 'product_id' => $id]);
        DB::table('pickups')->insertOrIgnore(['id' => 1, 'account_user_id' => 1, 'carrier_id' => 26]);
        DB::table('orders')->insert(['id' => $id, 'account_id' => 1, 'customer_id' => $id, 'pickup_id' => 1,
            'city_id' => $city, 'order_status_id' => 1, 'discount' => 2, 'carrier_price' => 4.02]);
        DB::table('order_pva')->insert(['order_id' => $id, 'product_variation_attribute_id' => $id,
            'order_status_id' => 1, 'quantity' => 3, 'price' => 10.01]);
    }

    private function fakeLogin(): void
    {
        Http::fake(['*/login' => Http::response(['access_token' => 'token'])]);
    }

    public function test_payload_allocates_adjustment_to_exact_cents(): void
    {
        $payload = app(AfraShippingService::class)->payload(Order::find(1));
        $totalCents = collect($payload['products'])->sum(fn ($p) => (int) round($p['unit_price'] * 100) * $p['quantity']);
        $this->assertSame(3205, $totalCents);
        $this->assertSame(101, $payload['city_id']);
        $this->assertSame('normal', $payload['order_type']);
        $this->assertArrayNotHasKey('agency_id', $payload);
    }

    public function test_pickup_partial_success_records_code_and_failure_comment(): void
    {
        $this->makeOrder(2, 2);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/create-order' => Http::response(['status' => 'success', 'order_number' => 'AF-1'])]);
        $run = AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'pickup_id' => 1, 'kind' => 'pickup', 'status' => 'running']);
        app(AfraShippingService::class)->syncPickup($run);
        $this->assertSame('AF-1', Order::find(1)->shipping_code);
        $this->assertNull(Order::find(2)->shipping_code);
        $this->assertSame(1, $run->fresh()->failed);
        $this->assertSame(1, $run->fresh()->synchronized);
        $this->assertDatabaseHas('order_comment', ['order_id' => 2]);
        app(AfraShippingService::class)->syncPickup($run);
        $this->assertSame(1, DB::table('afra_order_operations')->where('order_id', 1)->count());
    }

    public function test_status_pagination_uses_mapping_without_return_request(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1']);
        $this->makeOrder(2);
        DB::table('orders')->where('id', 2)->update(['account_id' => 2, 'shipping_code' => 'AF-1']);
        $this->makeOrder(3);
        DB::table('pickups')->insert(['id' => 2, 'account_user_id' => 1, 'carrier_id' => 22]);
        DB::table('orders')->where('id', 3)->update(['pickup_id' => 2, 'shipping_code' => 'AF-2']);
        DB::table('afra_status_mappings')->insert(['account_id' => 1, 'afra_status_id' => 10, 'order_status_id' => 9]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/login')) return Http::response(['access_token' => 'token']);
            if (str_contains($url, 'get-available-status')) return Http::response([['id' => 10, 'fr_name' => 'En souffrance']]);
            if (str_contains($url, 'current_page=1')) return Http::response(['orders' => [
                ['number' => 'AF-1', 'status' => 'En souffrance'],
                ['number' => 'AF-2', 'status' => 'En souffrance'],
            ], 'last_page' => 2]);
            return Http::response(['orders' => [['number' => 'AF-1', 'status' => 'Inconnu']], 'last_page' => 2]);
        });
        $run = AfraSyncRun::create(['account_id' => 1, 'kind' => 'statuses', 'status' => 'running']);
        app(AfraShippingService::class)->syncStatuses($run);
        $this->assertSame(9, (int) Order::find(1)->order_status_id);
        $this->assertSame(1, (int) Order::find(2)->order_status_id);
        $this->assertSame(1, (int) Order::find(3)->order_status_id);
        $this->assertSame(1, $run->fresh()->synchronized);
        $this->assertSame(2, $run->fresh()->skipped);
        $this->assertStringContainsString('Inconnu', $run->fresh()->message);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'return-request'));
    }

    public function test_update_failure_keeps_local_change_and_return_is_sent_only_once(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1', 'order_status_id' => 9]);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/update-order' => Http::response(['status' => 'error', 'message' => 'refus']),
            '*/return-request' => Http::response(['status' => 'success'])]);
        $service = app(AfraShippingService::class);
        $order = Order::find(1);
        $this->assertFalse($service->update($order, 1)['success']);
        $this->assertSame('AF-1', $order->fresh()->shipping_code);
        $this->assertDatabaseHas('order_comment', ['order_id' => 1]);
        $this->assertTrue($service->requestReturn($order, 1)['success']);
        $this->assertFalse($service->requestReturn($order, 1)['success']);
        Http::assertSentCount(4); // one login for each operation, update and return only
    }

    public function test_successful_update_reports_synchronization_done(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1']);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/update-order' => Http::response(['status' => 'success'])]);
        $result = app(AfraShippingService::class)->update(Order::find(1), 1);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('synchronisation effectuée', $result['message']);
        $this->assertSame(0, DB::table('order_comment')->count());
    }

    public function test_uncertain_create_is_not_sent_twice(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_ends_with($request->url(), '/login')) return Http::response(['access_token' => 'token']);
            $attempts++;
            throw new ConnectionException('timeout');
        });
        $service = app(AfraShippingService::class);
        $client = $service->client(1);
        $this->assertSame('failed', $service->create(Order::find(1), 1, $client));
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 1, 'create_state' => 'uncertain']);
        $this->assertSame('skipped', $service->create(Order::find(1), 1, $client));
        $this->assertSame(1, $attempts);
    }

    public function test_uncertain_return_is_not_automatically_retried(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1']);
        $returnAttempts = 0;
        Http::fake(function ($request) use (&$returnAttempts) {
            if (str_ends_with($request->url(), '/login')) return Http::response(['access_token' => 'token']);
            $returnAttempts++;
            throw new ConnectionException('timeout');
        });
        $service = app(AfraShippingService::class);
        $order = Order::find(1);
        $this->assertFalse($service->requestReturn($order, 1)['success']);
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 1, 'return_state' => 'uncertain']);
        $this->assertFalse($service->requestReturn($order, 1)['success']);
        $this->assertSame(1, $returnAttempts);
    }

    public function test_city_and_status_mapping_preserve_prices_and_reject_duplicate_city(): void
    {
        $user = new User();
        $user->id = 1;
        Auth::shouldReceive('user')->andReturn($user);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-cities' => Http::response([['id' => 101, 'name' => 'Rabat'], ['id' => 102, 'name' => 'Casa']]),
            '*/get-available-status' => Http::response([['id' => 10, 'fr_name' => 'En souffrance']])]);
        $controller = app(AfraShippingController::class);
        $this->assertSame(200, $controller->mapCity(new Request(['afra_city_id' => 101, 'city_id' => 1]), app(AfraShippingService::class))->status());
        $this->assertSame(422, $controller->mapCity(new Request(['afra_city_id' => 102, 'city_id' => 1]), app(AfraShippingService::class))->status());
        $this->assertDatabaseHas('default_carriers', ['city_id' => 1, 'city_id_carrier' => 101, 'price' => 32]);
        $this->assertSame(200, $controller->mapStatus(new Request(['afra_status_id' => 10, 'order_status_id' => 9]), app(AfraShippingService::class))->status());
        $this->assertDatabaseHas('afra_status_mappings', ['account_id' => 1, 'afra_status_id' => 10, 'order_status_id' => 9]);
    }

    public function test_account_response_does_not_expose_password(): void
    {
        $user = new User();
        $user->id = 1;
        Auth::shouldReceive('user')->andReturn($user);
        $response = app(AfraShippingController::class)->account();
        $this->assertSame('seller@example.com', $response->getData(true)['email']);
        $this->assertArrayNotHasKey('password', $response->getData(true));
        $this->assertArrayNotHasKey('password', \App\Models\AccountCarrier::find(1)->toArray());
    }

    public function test_account_password_is_encrypted_when_updated(): void
    {
        $user = new User();
        $user->id = 1;
        Auth::shouldReceive('user')->andReturn($user);
        $response = app(AfraShippingController::class)->saveAccount(new Request([
            'email' => 'new@example.com', 'password' => 'new-secret',
        ]));
        $this->assertSame(200, $response->status());
        $stored = DB::table('account_carrier')->where('id', 1)->first();
        $this->assertSame('new@example.com', $stored->username);
        $this->assertNotSame('new-secret', $stored->password);
        $this->assertSame('new-secret', Crypt::decryptString($stored->password));
    }
}
