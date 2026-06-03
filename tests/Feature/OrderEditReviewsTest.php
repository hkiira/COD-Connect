<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Brand;
use App\Models\BrandSource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderEditReviewsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_edit_api_returns_reviews_and_review_score()
    {
        // 1. Create essential models for auth & tenancy
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $account = Account::create([
            'title' => 'Test Account',
            'name' => 'Test Account',
        ]);

        $accountUser = AccountUser::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
        ]);

        // Authenticate the user
        Passport::actingAs($user);

        // 2. Create basic lookup data needed by OrderController@edit
        $brand = Brand::first() ?? Brand::create([
            'title' => 'Test Brand',
            'email' => 'brand@example.com',
            'account_id' => $account->id,
        ]);

        $source = Source::first() ?? Source::create([
            'title' => 'Test Source',
            'account_id' => $account->id,
        ]);

        $brandSource = BrandSource::where('brand_id', $brand->id)->where('source_id', $source->id)->first() ?? BrandSource::create([
            'brand_id' => $brand->id,
            'source_id' => $source->id,
            'account_id' => $account->id,
        ]);

        $orderStatus = OrderStatus::first() ?? OrderStatus::create([
            'title' => 'Test Status',
            'statut' => 1,
            'todelete' => 0,
            'account_id' => $account->id,
        ]);

        $customer = Customer::create([
            'name' => 'John Doe',
            'account_id' => $account->id,
        ]);

        $paymentType = \App\Models\PaymentType::first() ?? \App\Models\PaymentType::create(['title' => 'COD']);
        $warehouse = \App\Models\Warehouse::first() ?? \App\Models\Warehouse::create(['title' => 'Warehouse', 'account_id' => $account->id]);
        $paymentMethod = \App\Models\PaymentMethod::first() ?? \App\Models\PaymentMethod::create(['title' => 'Cash']);
        $city = \App\Models\City::first() ?? \App\Models\City::create(['title' => 'Casablanca']);

        // 3. Create the Order
        $order = Order::create([
            'code' => 'ORD-12345',
            'customer_id' => $customer->id,
            'brand_source_id' => $brandSource->id,
            'order_status_id' => $orderStatus->id,
            'account_id' => $account->id,
            'payment_type_id' => $paymentType?->id,
            'warehouse_id' => $warehouse?->id,
            'payment_method_id' => $paymentMethod?->id,
            'city_id' => $city?->id,
        ]);

        // 4. Create Review Questions
        $starQuestion = ReviewQuestion::create([
            'text' => 'Quality of the service?',
            'type' => 'stars',
            'is_active' => true,
        ]);

        $textQuestion = ReviewQuestion::create([
            'text' => 'Any additional comments?',
            'type' => 'text',
            'is_active' => true,
        ]);

        // 5. Create Review
        $review = Review::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
        ]);

        // 6. Create Review Answers
        $starAnswer = ReviewAnswer::create([
            'review_id' => $review->id,
            'review_question_id' => $starQuestion->id,
            'answer_value' => '5',
        ]);

        $textAnswer = ReviewAnswer::create([
            'review_id' => $review->id,
            'review_question_id' => $textQuestion->id,
            'answer_value' => 'Great service!',
        ]);

        // 7. Perform Request
        $response = $this->getJson("/api/orders/{$order->id}/edit?orderInfo=1");

        // 8. Assertions
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(1, $data['statut']);
        $this->assertArrayHasKey('orderInfo', $data['data']);
        
        $orderInfo = $data['data']['orderInfo'];
        $this->assertArrayHasKey('reviews', $orderInfo);
        $this->assertArrayHasKey('review_score', $orderInfo);

        $this->assertEquals(5.0, $orderInfo['review_score']);

        $expectedReviews = [
            [
                'id' => $starAnswer->id,
                'answer' => '5',
                'question' => [
                    'id' => $starQuestion->id,
                    'text' => 'Quality of the service?',
                    'type' => 'stars',
                ]
            ],
            [
                'id' => $textAnswer->id,
                'answer' => 'Great service!',
                'question' => [
                    'id' => $textQuestion->id,
                    'text' => 'Any additional comments?',
                    'type' => 'text',
                ]
            ]
        ];

        $this->assertEquals($expectedReviews, $orderInfo['reviews']);
    }

    public function test_order_edit_api_returns_null_reviews_when_none_exist()
    {
        // 1. Create essential models for auth & tenancy
        $user = User::create([
            'firstname' => 'Test2',
            'lastname' => 'User2',
            'name' => 'Test User 2',
            'email' => 'test2-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $account = Account::create([
            'title' => 'Test Account 2',
            'name' => 'Test Account 2',
        ]);

        $accountUser = AccountUser::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
        ]);

        // Authenticate the user
        Passport::actingAs($user);

        // 2. Create basic lookup data needed by OrderController@edit
        $brand = Brand::first() ?? Brand::create([
            'title' => 'Test Brand',
            'email' => 'brand@example.com',
            'account_id' => $account->id,
        ]);

        $source = Source::first() ?? Source::create([
            'title' => 'Test Source',
            'account_id' => $account->id,
        ]);

        $brandSource = BrandSource::where('brand_id', $brand->id)->where('source_id', $source->id)->first() ?? BrandSource::create([
            'brand_id' => $brand->id,
            'source_id' => $source->id,
            'account_id' => $account->id,
        ]);

        $orderStatus = OrderStatus::first() ?? OrderStatus::create([
            'title' => 'Test Status',
            'statut' => 1,
            'todelete' => 0,
            'account_id' => $account->id,
        ]);

        $customer = Customer::create([
            'name' => 'John Doe',
            'account_id' => $account->id,
        ]);

        $paymentType = \App\Models\PaymentType::first() ?? \App\Models\PaymentType::create(['title' => 'COD']);
        $warehouse = \App\Models\Warehouse::first() ?? \App\Models\Warehouse::create(['title' => 'Warehouse', 'account_id' => $account->id]);
        $paymentMethod = \App\Models\PaymentMethod::first() ?? \App\Models\PaymentMethod::create(['title' => 'Cash']);
        $city = \App\Models\City::first() ?? \App\Models\City::create(['title' => 'Casablanca']);

        // 3. Create the Order
        $order = Order::create([
            'code' => 'ORD-54321',
            'customer_id' => $customer->id,
            'brand_source_id' => $brandSource->id,
            'order_status_id' => $orderStatus->id,
            'account_id' => $account->id,
            'payment_type_id' => $paymentType?->id,
            'warehouse_id' => $warehouse?->id,
            'payment_method_id' => $paymentMethod?->id,
            'city_id' => $city?->id,
        ]);

        // 4. Perform Request
        $response = $this->getJson("/api/orders/{$order->id}/edit?orderInfo=1");

        // 5. Assertions
        $response->assertStatus(200);

        $data = $response->json();
        $orderInfo = $data['data']['orderInfo'];
        $this->assertEquals([], $orderInfo['reviews']);
        $this->assertNull($orderInfo['review_score']);
    }
}
