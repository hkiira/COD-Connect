<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * GET /api/orders must not run a query per order: a page of 10 stays under a fixed query budget,
 * and the query parameters cannot be used to ask for unbounded or arbitrary sorting.
 * Runs against the development database; skipped when the reference user has no orders.
 */
class OrderListQueryBudgetTest extends TestCase
{
    use DatabaseTransactions;

    private const EMAIL = 'abder.elachqar@gmail.com';
    private const BUDGET = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->baseUrl = 'http://localhost';

        $user = User::where('email', self::EMAIL)->first();
        if (! $user || ! $user->accountUsers()->first()) {
            $this->markTestSkipped('Reference user not available in this database.');
        }

        Passport::actingAs($user, [], 'api');
    }

    private function countQueries(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($uri);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queries];
    }

    public function test_a_page_of_orders_stays_under_the_query_budget(): void
    {
        [$response, $queries] = $this->countQueries('/api/orders?pagination[per_page]=10&pagination[current_page]=0');

        $response->assertOk();
        $rows = count($response->json('data'));
        $this->assertGreaterThan(0, $rows, 'the account needs orders for this test');
        $this->assertLessThanOrEqual(self::BUDGET, $queries, "{$queries} queries for {$rows} orders");
    }

    public function test_a_bigger_page_stays_under_the_same_budget(): void
    {
        // the number of queries depends on which relations the page touches, not on how many rows it has
        [$response, $queries] = $this->countQueries('/api/orders?pagination[per_page]=50&pagination[current_page]=0');

        $response->assertOk();
        $this->assertLessThanOrEqual(self::BUDGET, $queries, "{$queries} queries for ".count($response->json('data')).' orders');
    }

    public function test_page_size_is_capped(): void
    {
        $response = $this->getJson('/api/orders?pagination[per_page]=100000&pagination[current_page]=0');

        $response->assertOk();
        $this->assertSame(100, $response->json('per_page'));
        $this->assertLessThanOrEqual(100, count($response->json('data')));
    }

    public function test_the_sort_column_is_whitelisted(): void
    {
        $this->getJson('/api/orders?pagination[per_page]=5&sort[0][column]=id`;drop&sort[0][order]=desc')->assertOk();
        $this->getJson('/api/orders?pagination[per_page]=5&sort[0][column]=total&sort[0][order]=sideways')->assertOk();
    }

    public function test_pagination_is_optional(): void
    {
        $this->getJson('/api/orders')->assertOk();
    }
}
