<?php

namespace Tests\Feature;

use App\Http\Controllers\AfraShippingController;
use App\Models\AfraSyncRun;
use App\Models\Order;
use App\Models\User;
use App\Services\AfraShippingClient;
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
            'cache.default' => 'array',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'services.afra.base_url' => 'https://afradelivery.com/api/seller',
            'services.afra.ca_bundle' => null,
            'services.afra.carrier_id' => 26,
            'services.afra.token_ttl' => 3000,
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
        DB::table('order_statuses')->insert([['id' => 1, 'title' => 'En attente'], ['id' => 6, 'title' => 'En livraison'],
            ['id' => 7, 'title' => 'Livrée'], ['id' => 9, 'title' => 'En souffrance']]);
        // Status comments: parents carry the status, children are the reasons agents pick.
        DB::table('comments')->insert([
            ['id' => 21, 'title' => 'Livrée', 'statut' => 1, 'current_statut' => 7, 'new_statut' => null, 'comment_id' => null],
            ['id' => 23, 'title' => 'En cours', 'statut' => 1, 'current_statut' => 6, 'new_statut' => null, 'comment_id' => null],
            ['id' => 25, 'title' => 'Livrée', 'statut' => 1, 'current_statut' => null, 'new_statut' => 7, 'comment_id' => 21],
            ['id' => 31, 'title' => 'Client Injoignable', 'statut' => 1, 'current_statut' => 6, 'new_statut' => null, 'comment_id' => 23],
        ]);
        $this->makeOrder(1);
    }

    private function schema(): void
    {
        Schema::create('account_user', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('user_id'), $t->integer('statut')->default(1)]);
        Schema::create('account_carrier_city', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_carrier_id'), $t->unsignedBigInteger('city_id'), $t->integer('price')->default(0), $t->integer('return')->default(0), $t->integer('statut')->default(1), $t->timestamps()]);
        Schema::create('afra_cities', fn (Blueprint $t) => [$t->unsignedBigInteger('id')->primary(), $t->string('name'), $t->decimal('delivery_price', 10, 2)->nullable(), $t->timestamp('removed_at')->nullable(), $t->timestamps()]);
        Schema::create('afra_city_changes', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('afra_city_id'), $t->string('type'), $t->string('old_value')->nullable(), $t->string('new_value')->nullable(), $t->string('auto_action')->nullable(), $t->timestamp('resolved_at')->nullable(), $t->unsignedBigInteger('resolved_by')->nullable(), $t->timestamps()]);
        Schema::create('notifications', fn (Blueprint $t) => [$t->uuid('id')->primary(), $t->string('type'), $t->morphs('notifiable'), $t->text('data'), $t->timestamp('read_at')->nullable(), $t->timestamps()]);
        Schema::create('account_carrier', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('carrier_id'), $t->string('username')->nullable(), $t->text('password')->nullable(), $t->text('token')->nullable(), $t->json('settings')->nullable(), $t->integer('autocode')->default(0), $t->integer('statut')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('cities', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->integer('statut')->default(1), $t->unsignedBigInteger('region_id')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('default_carriers', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('carrier_id'), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('city_id_carrier')->nullable(), $t->string('name')->nullable(), $t->integer('price')->default(0), $t->integer('return')->default(0), $t->integer('delivery_time')->nullable(), $t->integer('statut')->default(1), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_statuses', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('comments', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id')->nullable(), $t->unsignedBigInteger('comment_id')->nullable(), $t->string('title'), $t->integer('statut')->default(1), $t->unsignedBigInteger('current_statut')->nullable(), $t->unsignedBigInteger('new_statut')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('customers', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('phones', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('addresses', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('city_id'), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('phoneables', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('phone_id'), $t->unsignedBigInteger('phoneable_id'), $t->string('phoneable_type'), $t->integer('statut')->default(1), $t->timestamps()]);
        Schema::create('addressables', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('address_id'), $t->unsignedBigInteger('addressable_id'), $t->string('addressable_type'), $t->integer('statut')->default(1), $t->timestamps()]);
        Schema::create('products', fn (Blueprint $t) => [$t->id(), $t->string('title'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('product_variation_attribute', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('pickups', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_user_id'), $t->unsignedBigInteger('carrier_id'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('orders', fn (Blueprint $t) => [$t->id(), $t->string('code')->nullable(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('customer_id'), $t->unsignedBigInteger('pickup_id')->nullable(), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('order_status_id'), $t->unsignedBigInteger('shipment_id')->nullable(), $t->unsignedBigInteger('order_id')->nullable(), $t->string('type')->default('sale'), $t->string('shipping_code')->nullable(), $t->decimal('discount', 10, 2)->default(0), $t->decimal('carrier_price', 10, 2)->default(0), $t->decimal('real_carrier_price', 10, 2)->nullable(), $t->text('note')->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_pva', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_variation_attribute_id'), $t->unsignedBigInteger('order_status_id'), $t->integer('quantity'), $t->decimal('price', 10, 2), $t->decimal('realprice', 10, 2)->nullable(), $t->decimal('initial_price', 10, 2)->nullable(), $t->decimal('discount', 10, 2)->nullable(), $t->softDeletes(), $t->timestamps()]);
        Schema::create('order_comment', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('comment_id')->nullable(), $t->unsignedBigInteger('account_user_id'), $t->unsignedBigInteger('order_status_id'), $t->string('title')->nullable(), $t->dateTime('postpone')->nullable(), $t->integer('score')->nullable(), $t->string('type')->default('comment'), $t->softDeletes(), $t->timestamps()]);
        Schema::create('afra_status_mappings', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('afra_status_id'), $t->unsignedBigInteger('comment_id')->nullable(), $t->boolean('is_return')->default(false), $t->unsignedBigInteger('order_status_id')->nullable(), $t->timestamps()]);
        Schema::create('afra_sync_runs', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('account_id'), $t->unsignedBigInteger('account_user_id')->nullable(), $t->unsignedBigInteger('pickup_id')->nullable(), $t->string('kind'), $t->string('status'), $t->integer('processed')->default(0), $t->integer('synchronized')->default(0), $t->integer('skipped')->default(0), $t->integer('failed')->default(0), $t->text('message')->nullable(), $t->timestamps()]);
        Schema::create('afra_order_operations', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id')->unique(), $t->string('create_state')->nullable(), $t->timestamp('create_attempted_at')->nullable(), $t->string('return_state')->nullable(), $t->string('delete_state')->nullable(), $t->string('exchange_state')->nullable(), $t->string('remote_status')->nullable(), $t->timestamp('remote_status_at')->nullable(), $t->timestamp('missing_since')->nullable(), $t->text('last_error')->nullable(), $t->timestamps()]);
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
        DB::table('orders')->insert(['id' => $id, 'code' => 'CMD'.$id, 'account_id' => 1, 'customer_id' => $id, 'pickup_id' => 1,
            'city_id' => $city, 'order_status_id' => 1, 'discount' => 2, 'carrier_price' => 4.02, 'created_at' => now()]);
        DB::table('order_pva')->insert(['order_id' => $id, 'product_variation_attribute_id' => $id,
            'order_status_id' => 1, 'quantity' => 3, 'price' => 10.01]);
    }

    private function actAsAccountUser(): void
    {
        $user = new User();
        $user->id = 1;
        Auth::shouldReceive('user')->andReturn($user);
    }

    // region payload

    public function test_payload_spreads_the_discount_to_the_exact_cent(): void
    {
        $payload = app(AfraShippingService::class)->payload(Order::find(1));
        $totalCents = collect($payload['products'])->sum(fn ($p) => (int) round($p['unit_price'] * 100) * $p['quantity']);

        $this->assertSame(3205, $totalCents); // 3 × 10.01 − 2 + 4.02
        $this->assertCount(2, $payload['products']); // 2 units at 10.68, 1 unit carrying the extra cent
        $this->assertSame(101, $payload['city_id']);
        $this->assertSame('Client 1 - CMD1', $payload['client_name']);
        $this->assertSame('normal', $payload['order_type']);
        $this->assertSame('no', $payload['test_product']);
        $this->assertArrayNotHasKey('agency_id', $payload);
    }

    public function test_test_product_follows_the_account_setting(): void
    {
        DB::table('account_carrier')->where('id', 1)->update(['settings' => json_encode(['test_product' => true])]);

        $this->assertSame('yes', app(AfraShippingService::class)->payload(Order::find(1))['test_product']);
    }

    // endregion

    // region authentication

    public function test_token_is_reused_and_renewed_after_a_401(): void
    {
        $logins = 0;
        $rejectedOnce = false;
        Http::fake(function ($request) use (&$logins, &$rejectedOnce) {
            if (str_ends_with($request->url(), '/login')) {
                $logins++;
                return Http::response(['access_token' => 'token-'.$logins]);
            }
            if (!$rejectedOnce && $request->hasHeader('Authorization', 'Bearer token-1') && str_contains($request->url(), 'current_page=2')) {
                $rejectedOnce = true;
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }
            return Http::response(['orders' => [], 'pagination' => ['last_page' => 1]]);
        });

        $client = new AfraShippingClient(1);
        $client->getOrders(1);
        (new AfraShippingClient(1))->getOrders(1); // another request: cached token, no new login
        $this->assertSame(1, $logins);

        $client->getOrders(2); // token rejected → one new login, call retried
        $this->assertSame(2, $logins);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'current_page=2') && $request->hasHeader('Authorization', 'Bearer token-2'));
    }

    public function test_reference_lists_are_authenticated_and_cached(): void
    {
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-cities' => Http::response([['id' => 101, 'name' => 'Rabat', 'delivery_price' => 35]])]);

        $this->assertSame(101, (new AfraShippingClient(1))->getCities()[0]['id']);
        (new AfraShippingClient(1))->getCities();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/get-cities') && $request->hasHeader('Authorization', 'Bearer token'));
        Http::assertSentCount(2); // one login, one get-cities: the list is cached
    }

    public function test_wrong_credentials_give_a_clear_message(): void
    {
        Http::fake(['*/login' => Http::response(['message' => 'Invalid credentials'], 401)]);

        $this->expectExceptionMessage('Email ou mot de passe Afra incorrect.');
        (new AfraShippingClient(1))->login();
    }

    // endregion

    // region create

    public function test_pickup_partial_success_records_code_and_failure_comment(): void
    {
        $this->makeOrder(2, 2); // city 2 has no Afra city
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/create-order' => Http::response(['status' => 'success', 'message' => 'ok', 'order_number' => 12345])]);
        $run = AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'pickup_id' => 1, 'kind' => 'pickup', 'status' => 'running']);

        app(AfraShippingService::class)->syncPickup($run);

        $this->assertSame('12345', Order::find(1)->shipping_code);
        $this->assertNull(Order::find(2)->shipping_code);
        $this->assertSame(1, $run->fresh()->failed);
        $this->assertSame(1, $run->fresh()->synchronized);
        $this->assertDatabaseHas('order_comment', ['order_id' => 2]);

        app(AfraShippingService::class)->syncPickup($run); // order 1 is never sent twice
        Http::assertSentCount(2); // one login, one create-order
    }

    public function test_uncertain_create_is_matched_in_afra_list_instead_of_sent_again(): void
    {
        $created = 0;
        Http::fake(function ($request) use (&$created) {
            $url = $request->url();
            if (str_ends_with($url, '/login')) return Http::response(['access_token' => 'token']);
            if (str_ends_with($url, '/create-order')) {
                $created++;
                throw new ConnectionException('timeout');
            }
            return Http::response(['pagination' => ['last_page' => 1], 'orders' => [
                ['number' => '999', 'client' => 'Client 1 - CMD1', 'status' => 'En attente'],
            ]]);
        });
        $service = app(AfraShippingService::class);
        $client = $service->client(1);

        $this->assertSame('failed', $service->create(Order::find(1), 1, $client));
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 1, 'create_state' => 'uncertain']);

        $this->assertSame('synchronized', $service->create(Order::find(1), 1, $client));
        $this->assertSame('999', Order::find(1)->shipping_code);
        $this->assertSame(1, $created);
    }

    public function test_create_accepted_without_number_is_found_on_the_last_page_and_never_sent_twice(): void
    {
        $created = 0;
        $visible = true;
        Http::fake(function ($request) use (&$created, &$visible) {
            $url = $request->url();
            if (str_ends_with($url, '/login')) return Http::response(['access_token' => 'token']);
            if (str_ends_with($url, '/create-order')) {
                $created++;
                return Http::response(['status' => 'success', 'message' => 'Order created successfully']); // no number, as live
            }
            // Afra lists oldest first: the new order is on the last page
            if (str_contains($url, 'current_page=2')) {
                return Http::response(['pagination' => ['last_page' => 2], 'orders' => $visible
                    ? [['number' => '1381791249342', 'client' => 'Client 1 - CMD1', 'status' => 'En attente']] : []]);
            }
            return Http::response(['pagination' => ['last_page' => 2], 'orders' => [['number' => '1', 'client' => 'Ancien - X', 'status' => 'Livré']]]);
        });
        $service = app(AfraShippingService::class);

        $this->assertSame('synchronized', $service->create(Order::find(1), 1, $service->client(1)));
        $this->assertSame('1381791249342', Order::find(1)->shipping_code);

        // Not visible yet: kept as "accepted", looked for again, never created a second time
        $this->makeOrder(2);
        $visible = false;
        $this->assertSame('failed', $service->create(Order::find(2), 1, $service->client(1)));
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 2, 'create_state' => 'accepted']);
        $this->assertSame('skipped', $service->create(Order::find(2), 1, $service->client(1)));
        $this->assertSame(2, $created);
    }

    public function test_write_calls_accept_the_success_forms_seen_live(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1']);
        $answers = [['status' => true, 'message' => 'Order Deleted Successfully'], ['message' => 'Order Deleted Successfully'], ['status' => 'Success']];
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/delete-order' => Http::sequence()->push($answers[0])->push($answers[1])->push($answers[2])
                ->push(['status' => 'error', 'message' => 'Order not found'])]);
        $client = new AfraShippingClient(1);

        $client->deleteOrder('A');
        $client->deleteOrder('B');
        $client->deleteOrder('C');
        $this->expectExceptionMessage('Order not found');
        $client->deleteOrder('D');
    }

    public function test_a_refused_order_refused_again_can_still_be_retried(): void
    {
        $this->makeOrder(2, 2); // city 2 is not linked to Afra: refused every time
        Http::fake(['*/login' => Http::response(['access_token' => 'token'])]);
        $service = app(AfraShippingService::class);

        $this->assertSame('failed', $service->create(Order::find(2), 1, $service->client(1)));
        $this->assertSame('failed', $service->create(Order::find(2), 1, $service->client(1)));
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 2, 'create_state' => 'failed']); // not stuck on "pending"
    }

    public function test_uncertain_create_not_found_is_sent_again(): void
    {
        DB::table('afra_order_operations')->insert(['order_id' => 1, 'create_state' => 'uncertain']);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-orders*' => Http::response(['pagination' => ['last_page' => 1], 'orders' => []]),
            '*/create-order' => Http::response(['status' => 'success', 'order_number' => '777'])]);
        $service = app(AfraShippingService::class);

        $this->assertSame('synchronized', $service->create(Order::find(1), 1, $service->client(1)));
        $this->assertSame('777', Order::find(1)->shipping_code);
    }

    // endregion

    // region update / return

    public function test_update_failure_keeps_local_change_and_return_is_sent_only_once(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1', 'order_status_id' => 9]);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/update-order' => Http::response(['status' => 'error', 'message' => 'refus']),
            '*/return-request' => Http::response(['status' => 'success', 'message' => 'ok'])]);
        $service = app(AfraShippingService::class);
        $order = Order::find(1);

        $this->assertFalse($service->update($order, 1)['success']);
        $this->assertSame('AF-1', $order->fresh()->shipping_code);
        $this->assertDatabaseHas('order_comment', ['order_id' => 1]);
        $this->assertTrue($service->requestReturn($order, 1)['success']);
        $this->assertFalse($service->requestReturn($order, 1)['success']);
        Http::assertSentCount(3); // one login (cached), one update, one return
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/update-order') && $request['order_number'] === 'AF-1');
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

    // endregion

    // region statuses

    public function test_status_sync_adds_the_mapped_comment_once_and_never_requests_a_return(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1', 'order_status_id' => 6]);
        $this->makeOrder(2); // another account, same Afra number: ignored
        DB::table('orders')->where('id', 2)->update(['account_id' => 2, 'shipping_code' => 'AF-1']);
        $this->makeOrder(3); // open order whose Afra status has no mapping
        DB::table('orders')->where('id', 3)->update(['shipping_code' => 'AF-3', 'order_status_id' => 6]);
        DB::table('afra_status_mappings')->insert(['account_id' => 1, 'afra_status_id' => 5, 'comment_id' => 25]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/login')) return Http::response(['access_token' => 'token']);
            if (str_contains($url, 'get-available-status')) return Http::response([['id' => 5, 'fr_name' => 'Livré'], ['id' => 8, 'fr_name' => 'Inconnu']]);
            if (str_contains($url, 'current_page=1')) return Http::response(['pagination' => ['last_page' => 2], 'orders' => [
                ['number' => 'X-0', 'status' => 'Livré'],
                ['number' => 'AF-1', 'status' => 'Livre'],
            ]]);
            return Http::response(['pagination' => ['last_page' => 2], 'orders' => [['number' => 'AF-3', 'status' => 'Inconnu']]]);
        });
        $service = app(AfraShippingService::class);

        $run = AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'kind' => 'statuses', 'status' => 'running']);
        $service->syncStatuses($run);

        $this->assertSame(7, (int) Order::find(1)->order_status_id);
        $this->assertSame(1, (int) Order::find(2)->order_status_id);
        $this->assertSame(6, (int) Order::find(3)->order_status_id);
        $this->assertDatabaseHas('order_comment', ['order_id' => 1, 'comment_id' => 25, 'order_status_id' => 7, 'account_user_id' => 1]);
        $this->assertSame(1, $run->fresh()->synchronized);
        $this->assertStringContainsString('Inconnu', $run->fresh()->message);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'return-request'));

        // Same Afra status on the next run: nothing added. (Order 1 is now closed, order 3 still unmapped.)
        DB::table('orders')->where('id', 1)->update(['order_status_id' => 6]);
        $second = AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'kind' => 'statuses', 'status' => 'running']);
        $service->syncStatuses($second);
        $this->assertSame(1, DB::table('order_comment')->where('order_id', 1)->count());
    }

    public function test_status_sync_stops_reading_pages_once_every_open_order_was_seen(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1', 'order_status_id' => 6]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/login')) return Http::response(['access_token' => 'token']);
            if (str_contains($url, 'get-available-status')) return Http::response([['id' => 4, 'fr_name' => 'En Livraison']]);
            return Http::response(['pagination' => ['last_page' => 50], 'orders' => [['number' => 'AF-1', 'status' => 'En Livraison']]]);
        });

        app(AfraShippingService::class)->syncStatuses(AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'kind' => 'statuses', 'status' => 'running']));

        Http::assertSentCount(3); // login, statuses, page 1 only
    }

    // endregion

    // region settings page

    public function test_city_and_status_mapping_preserve_prices_and_reject_duplicate_city(): void
    {
        $this->actAsAccountUser();
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-cities' => Http::response([['id' => 101, 'name' => 'Rabat'], ['id' => 102, 'name' => 'Casa']]),
            '*/get-available-status' => Http::response([['id' => 5, 'fr_name' => 'Livré']])]);
        $controller = app(AfraShippingController::class);

        $this->assertSame(200, $controller->mapCity(new Request(['afra_city_id' => 101, 'city_id' => 1]))->status());
        $this->assertSame(422, $controller->mapCity(new Request(['afra_city_id' => 102, 'city_id' => 1]))->status());
        $this->assertDatabaseHas('default_carriers', ['city_id' => 1, 'city_id_carrier' => 101, 'price' => 32]);

        $this->assertSame(200, $controller->mapStatus(new Request(['afra_status_id' => 5, 'comment_id' => 25]))->status());
        $this->assertDatabaseHas('afra_status_mappings', ['account_id' => 1, 'afra_status_id' => 5, 'comment_id' => 25]);
        $this->assertSame(422, $controller->mapStatus(new Request(['afra_status_id' => 5, 'comment_id' => 21]))->status()); // a parent is not a reason
    }

    public function test_linking_a_city_without_afra_tariff_creates_it_with_afra_price(): void
    {
        DB::table('cities')->insert(['id' => 3, 'title' => 'Fès']);

        app(AfraShippingService::class)->linkCity(['id' => 103, 'name' => 'Fes', 'delivery_price' => 39], 3);

        $this->assertDatabaseHas('default_carriers', ['carrier_id' => 26, 'city_id' => 3, 'city_id_carrier' => 103, 'price' => 39]);
    }

    public function test_auto_link_matches_names_and_creates_missing_cities_only_when_asked(): void
    {
        DB::table('cities')->insert(['id' => 3, 'title' => 'Fès']);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-cities' => Http::response([
                ['id' => 101, 'name' => 'Rabat', 'delivery_price' => 35],   // already linked
                ['id' => 103, 'name' => 'FES', 'delivery_price' => 39],     // same name as "Fès"
                ['id' => 104, 'name' => 'Afra -Nador', 'delivery_price' => 39], // not in our cities
            ])]);
        $service = app(AfraShippingService::class);

        $report = $service->autoLinkCities(1, [101, 103, 104], false);
        $this->assertSame(['FES'], $report['linked']);
        $this->assertSame([], $report['created']);
        $this->assertCount(2, $report['skipped']);
        $this->assertDatabaseHas('default_carriers', ['city_id' => 3, 'city_id_carrier' => 103]);
        $this->assertDatabaseMissing('cities', ['title' => 'Afra -Nador']);

        $report = $service->autoLinkCities(1, [104], true);
        $this->assertSame(['Afra -Nador'], $report['created']);
        $created = DB::table('cities')->where('title', 'Afra -Nador')->first();
        $this->assertDatabaseHas('default_carriers', ['city_id' => $created->id, 'city_id_carrier' => 104, 'price' => 39]);
    }

    public function test_status_list_shows_what_each_comment_does(): void
    {
        $this->actAsAccountUser();
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-available-status' => Http::response([['id' => 5, 'fr_name' => 'Livré']])]);

        DB::table('comments')->insert([
            ['id' => 50, 'title' => 'Commande Payée', 'statut' => 1, 'current_statut' => 10, 'new_statut' => null, 'comment_id' => null],
            ['id' => 51, 'title' => 'Ajouter la facture de livraison', 'statut' => 1, 'current_statut' => null, 'new_statut' => null, 'comment_id' => 50],
        ]);

        $data = app(AfraShippingController::class)->statuses()->getData(true);
        $livree = collect($data['comments'])->firstWhere('id', 25);

        $this->assertSame(7, $livree['status_id']);
        $this->assertSame('Livrée', $livree['group']);
        // "Paid" is only set by a payment slip, never by an Afra status
        $this->assertNull(collect($data['comments'])->firstWhere('id', 51));
    }

    public function test_a_status_can_be_marked_as_return_without_a_comment(): void
    {
        $this->actAsAccountUser();
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-available-status' => Http::response([['id' => 7, 'fr_name' => 'Retourné']])]);
        $controller = app(AfraShippingController::class);

        $controller->mapStatus(new Request(['afra_status_id' => 7, 'is_return' => true]));
        $this->assertDatabaseHas('afra_status_mappings', ['afra_status_id' => 7, 'is_return' => 1, 'comment_id' => null]);

        $controller->mapStatus(new Request(['afra_status_id' => 7, 'comment_id' => 31])); // keeps is_return
        $this->assertDatabaseHas('afra_status_mappings', ['afra_status_id' => 7, 'is_return' => 1, 'comment_id' => 31]);

        $controller->mapStatus(new Request(['afra_status_id' => 7, 'comment_id' => null, 'is_return' => false]));
        $this->assertDatabaseMissing('afra_status_mappings', ['afra_status_id' => 7]);
    }

    public function test_account_response_does_not_expose_password(): void
    {
        $this->actAsAccountUser();
        $response = app(AfraShippingController::class)->account();

        $this->assertSame('seller@example.com', $response->getData(true)['email']);
        $this->assertArrayNotHasKey('password', $response->getData(true));
        $this->assertArrayNotHasKey('password', \App\Models\AccountCarrier::find(1)->toArray());
    }

    public function test_account_password_is_encrypted_and_test_product_saved(): void
    {
        $this->actAsAccountUser();
        $response = app(AfraShippingController::class)->saveAccount(new Request([
            'email' => 'new@example.com', 'password' => 'new-secret', 'test_product' => true,
        ]));

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->getData(true)['settings']['test_product']);
        $stored = DB::table('account_carrier')->where('id', 1)->first();
        $this->assertSame('new@example.com', $stored->username);
        $this->assertSame('new-secret', Crypt::decryptString($stored->password));
    }

    // endregion

    // region city watch

    public function test_city_watch_records_changes_links_same_names_and_notifies(): void
    {
        $this->actAsAccountUser();
        DB::table('cities')->insert(['id' => 3, 'title' => 'Fès']);
        Http::fake([
            '*/login' => Http::response(['access_token' => 'token']),
            '*/get-cities' => Http::sequence()
                ->push([['id' => 101, 'name' => 'Rabat', 'delivery_price' => 35], ['id' => 102, 'name' => 'Casa', 'delivery_price' => 40], ['id' => 105, 'name' => 'Tanger', 'delivery_price' => 30]])
                ->push([['id' => 101, 'name' => 'Rabat Agdal', 'delivery_price' => 35], ['id' => 102, 'name' => 'Casa', 'delivery_price' => 45],
                    ['id' => 103, 'name' => 'FES', 'delivery_price' => 39], ['id' => 104, 'name' => 'Nouvelle', 'delivery_price' => 20]]),
        ]);
        $watcher = app(\App\Services\AfraCityWatcher::class);

        $first = $watcher->check();
        $this->assertTrue($first['first_run']);
        $this->assertSame(0, DB::table('afra_city_changes')->count());
        $this->assertSame(0, DB::table('notifications')->count());

        $second = $watcher->check();
        $this->assertSame(['first_run' => false, 'added' => 2, 'linked' => 1, 'renamed' => 1, 'price' => 1, 'removed' => 1], $second);
        $this->assertDatabaseHas('afra_city_changes', ['afra_city_id' => 103, 'type' => 'added', 'auto_action' => 'linked']);
        $this->assertDatabaseHas('default_carriers', ['city_id' => 3, 'city_id_carrier' => 103]);
        $this->assertDatabaseHas('afra_city_changes', ['afra_city_id' => 104, 'type' => 'added', 'resolved_at' => null]);
        $this->assertDatabaseHas('afra_city_changes', ['afra_city_id' => 101, 'type' => 'renamed', 'old_value' => 'Rabat', 'new_value' => 'Rabat Agdal']);
        $this->assertDatabaseHas('afra_city_changes', ['afra_city_id' => 105, 'type' => 'removed']);

        $notification = DB::table('notifications')->first();
        $this->assertSame(1, (int) $notification->notifiable_id);
        $this->assertStringContainsString('2 nouvelle(s) ville(s) dont 1 associée(s)', json_decode($notification->data, true)['message']);

        // The new Afra price is applied to the linked local city only when asked.
        DB::table('default_carriers')->where('city_id', 2)->update(['city_id_carrier' => 102]);
        $priceChange = DB::table('afra_city_changes')->where('type', 'price')->first();
        $this->assertSame(200, app(AfraShippingController::class)->applyCityPrice($priceChange->id)->status());
        $this->assertDatabaseHas('default_carriers', ['city_id' => 2, 'price' => 45]);
    }

    public function test_an_empty_city_list_is_not_read_as_every_city_removed(): void
    {
        DB::table('afra_cities')->insert(['id' => 101, 'name' => 'Rabat']);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']), '*/get-cities' => Http::response([])]);

        $this->expectExceptionMessage('liste de villes vide');
        app(\App\Services\AfraCityWatcher::class)->check();
    }

    // endregion

    // region find codes, delete, exchange

    public function test_missing_codes_are_found_by_code_then_by_unique_phone(): void
    {
        $this->makeOrder(2);
        DB::table('phones')->where('id', 2)->update(['title' => '0699999999']);
        $this->makeOrder(3); // same phone as order 1, and two Afra orders with that phone
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-orders*' => Http::response(['pagination' => ['last_page' => 1], 'orders' => [
                ['number' => '111', 'client' => 'Client 1-CMD1', 'phone_number' => '0612345678', 'status' => 'En attente'], // old export name
                ['number' => '222', 'client' => 'Autre nom', 'phone_number' => '+212 699 99 99 99', 'status' => 'Ramassé'],
                ['number' => '333', 'client' => 'X', 'phone_number' => '0612345678', 'status' => 'En attente'],
                ['number' => '444', 'client' => 'Y', 'phone_number' => '06 12 34 56 78', 'status' => 'En attente'],
            ]])]);
        $service = app(AfraShippingService::class);

        $preview = $service->matchMissingCodes(1, null, false, 1);
        $this->assertSame(['111' => 'code', '222' => 'phone'], collect($preview['matched'])->pluck('via', 'afra_number')->all());
        $this->assertSame([3], collect($preview['ambiguous'])->pluck('order_id')->all());
        $this->assertNull(Order::find(1)->shipping_code); // preview writes nothing

        $service->matchMissingCodes(1, null, true, 1);
        $this->assertSame('111', Order::find(1)->shipping_code);
        $this->assertSame('222', Order::find(2)->shipping_code);
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 2, 'create_state' => 'sent', 'remote_status' => 'Ramassé']);
    }

    public function test_cancel_deletes_at_afra_only_before_pickup(): void
    {
        DB::table('orders')->whereIn('id', [1])->update(['shipping_code' => 'AF-1']);
        $this->makeOrder(2);
        DB::table('orders')->where('id', 2)->update(['shipping_code' => 'AF-2']);
        DB::table('afra_order_operations')->insert([
            ['order_id' => 1, 'remote_status' => 'En attente'],
            ['order_id' => 2, 'remote_status' => 'Ramassé'],
        ]);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/delete-order' => Http::response(['status' => 'success', 'message' => 'Order Deleted Successfully'])]);
        $service = app(AfraShippingService::class);

        $this->assertSame([1 => 'AF-1', 2 => 'AF-2'], AfraShippingService::afraCodes([1, 2]));
        $this->assertTrue($service->cancelRemovedOrders([1 => 'AF-1'], 1)[1]['success']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/delete-order') && $r['order_number'] === 'AF-1');
        // the dead Afra number is forgotten: put back in an Afra pickup, the order is sent again
        $this->assertNull(Order::find(1)->shipping_code);
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 1, 'create_state' => null, 'delete_state' => null]);

        $this->assertFalse($service->cancelAtAfra(Order::find(2), 'AF-2', 1)['success']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/delete-order') && $r['order_number'] === 'AF-2');
        $this->assertSame(1, DB::table('notifications')->count()); // the team is told to handle it with Afra
    }

    public function test_exchange_order_is_declared_after_its_creation(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-OLD']);
        $this->makeOrder(2);
        DB::table('orders')->where('id', 2)->update(['order_id' => 1]);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/create-order' => Http::response(['status' => 'success', 'order_number' => 'AF-NEW']),
            '*/create-exchange' => Http::response(['status' => 'success', 'message' => 'Exchange created Successfully'])]);
        $run = AfraSyncRun::create(['account_id' => 1, 'account_user_id' => 1, 'pickup_id' => 1, 'kind' => 'pickup', 'status' => 'running']);

        app(AfraShippingService::class)->syncPickup($run);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create-exchange')
            && $r['old_order_number'] === 'AF-OLD' && $r['new_order_number'] === 'AF-NEW');
        $this->assertDatabaseHas('afra_order_operations', ['order_id' => 2, 'exchange_state' => 'sent']);
    }

    // endregion

    // region returns and payments

    /** Operations service whose slip creation is captured instead of going through ShipmentController. */
    private function operations(array &$slips): \App\Services\AfraOperationsService
    {
        return new class(app(AfraShippingService::class), $slips) extends \App\Services\AfraOperationsService {
            public function __construct(AfraShippingService $shipping, private array &$captured)
            {
                parent::__construct($shipping);
            }

            protected function storeSlip(array $slip): array
            {
                $this->captured[] = $slip;

                return ['statut' => 1, 'data' => [['id' => 50, 'code' => 'SHIP-1']]];
            }
        };
    }

    public function test_returns_marked_at_afra_are_listed_and_received_on_a_return_slip(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => 'AF-1', 'order_status_id' => 9, 'real_carrier_price' => 35]);
        $this->makeOrder(2); // Afra status not marked as return
        DB::table('orders')->where('id', 2)->update(['shipping_code' => 'AF-2', 'order_status_id' => 6]);
        DB::table('afra_status_mappings')->insert(['account_id' => 1, 'afra_status_id' => 7, 'is_return' => true]);
        DB::table('afra_order_operations')->insert([
            ['order_id' => 1, 'remote_status' => 'Retourné'],
            ['order_id' => 2, 'remote_status' => 'En Livraison'],
        ]);
        Http::fake(['*/login' => Http::response(['access_token' => 'token']),
            '*/get-available-status' => Http::response([['id' => 4, 'fr_name' => 'En Livraison'], ['id' => 7, 'fr_name' => 'Retourné']])]);
        $slips = [];
        $operations = $this->operations($slips);

        $this->assertSame(['AF-1'], collect($operations->returnsToReceive(1))->pluck('shipping_code')->all());
        $this->assertNull($operations->lookupReturn(1, 'CMD1')['warning']);
        $this->assertStringContainsString('pas encore', $operations->lookupReturn(1, 'AF-2')['warning']);

        $result = $operations->receiveReturns(1, 30, [1, 2]);
        $this->assertSame(2, $result['orders']);
        $this->assertSame(2, $slips[0]['shipment_type_id']); // return slip: stock back
        $this->assertSame(26, $slips[0]['carrier_id']);
        $this->assertSame(35.0, collect($slips[0]['orders'])->firstWhere('id', 1)['carrier_price']); // fee kept
    }

    public function test_payment_text_from_pdf_is_matched_and_becomes_a_payment_slip(): void
    {
        DB::table('orders')->where('id', 1)->update(['shipping_code' => '611766332727', 'order_status_id' => 7]);
        $this->makeOrder(2); // already paid
        DB::table('orders')->where('id', 2)->update(['shipping_code' => '611766332728', 'order_status_id' => 10, 'shipment_id' => 9]);
        $text = "FACTURE AFRA\n611766332727 Client 1 0612345678 Rabat 32.05\n611766332728 Client 2\n13811790610318138 inconnu"; // real Afra numbers go up to 17 digits
        $slips = [];
        $operations = $this->operations($slips);

        $report = $operations->reconcilePayment(1, $text);
        $this->assertSame([1], collect($report['to_pay'])->pluck('id')->all());
        $this->assertSame(32.0, $report['to_pay'][0]['fee']); // Rabat default Afra price
        $this->assertSame(32.05, $report['totals']['collected']);
        $this->assertSame([2], collect($report['already_paid'])->pluck('id')->all());
        $this->assertSame(['13811790610318138'], $report['unknown']); // the phone number is not taken for an Afra number

        $operations->createPayment(1, 30, [['id' => 1, 'fee' => 30]], 2.05);
        $this->assertSame(1, $slips[0]['shipment_type_id']);
        $this->assertSame(2.05, $slips[0]['given_amount']);
        $this->assertSame([['id' => 1, 'carrier_price' => 30.0]], $slips[0]['orders']);

        // the account's special price wins over Afra's default price
        DB::table('account_carrier_city')->insert(['account_carrier_id' => 1, 'city_id' => 1, 'price' => 28]);
        $this->assertSame(28.0, $operations->reconcilePayment(1, $text)['to_pay'][0]['fee']);

        $this->expectExceptionMessage('ne sont plus à payer');
        $operations->createPayment(1, 30, [['id' => 2, 'fee' => 30]], 0);
    }

    // endregion
}
