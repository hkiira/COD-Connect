<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\TypeAttribute;
use App\Models\Attribute;
use App\Models\City;
use App\Models\Customer;
use App\Models\Phone;
use App\Models\Product;
use App\Models\DefaultCarrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class OldSysController extends Controller
{
    /**
     * GET Retrieve all products.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function rest(Request $request, $entity, $id = null, $type = null)
    {
        switch ($entity) {
            case 'products':
                return $this->getAllProducts();
            case 'variations':
                return $this->getAllVariations();
            case 'attribute':
                return $this->getAttribute($id);
            case 'attributes':
                return $this->getAllAttributes();
            case 'termes':
                return $this->getAllTermes($id);
            case 'orders':
                return $this->getAllOrders($request);
            case 'send_cities':
                return $this->sendCities();
            case 'export':
                return $this->exportOrdersToXlsx();
            default:
                return "productsuppliers";
        }
    }
    public function sendCities()
    {
        $defaultCities = DefaultCarrier::where('carrier_id', 22)->get();
        $cities = City::get();
        return $defaultCities->map(function ($defaultCity) {
            return ['id' => $defaultCity->city->id, 'name' => $defaultCity->city->titlear . " " . $defaultCity->city->title];
        });
    }
    public function getAllProducts()
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products';
        $noMoreData = false;
        $data = [];
        $i = 1;
        $accountUsers = Account::find(getAccountUser()->account_id)->accountUsers->pluck('id')->toArray();
        while ($noMoreData == false) {
            $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret . '&page=' . $i;
            $response = Http::get($url);
            $products = $response->json();
            if ($products == []) {
                $noMoreData = true;
            } else {
                foreach ($products as $product) {
                    $hasProduct = Product::where('reference', $product['slug'])->whereIn('account_user_id', $accountUsers)->first();
                    if ($hasProduct) {
                        $hasProduct->update([
                            "meta" => [
                                "id" => $product['id'],
                                "name" => $product['name'],
                                "slug" => $product['slug'],
                                "permalink" => $product['permalink'],
                            ]
                        ]);
                    }
                }
            }
            $i++;
        }
        return $data;
    }

    /**
     * GET Retrieve a specific product.
     *
     * @param  int  $id Product ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProduct($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $product = $response->json();

        return response()->json($product);
    }

    /**
     * POST Create a new product.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createProduct(Request $request)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::post($url, $data);
        $createdProduct = $response->json();

        return response()->json($createdProduct);
    }

    /**
     * PUT Update an existing product.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id Product ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProduct(Request $request, $id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::put($url, $data);
        $updatedProduct = $response->json();

        return response()->json($updatedProduct);
    }

    /**
     * DELETE Delete a product.
     *
     * @param  int  $id Product ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProduct($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::delete($url);
        $deletedProduct = $response->json();

        return response()->json($deletedProduct);
    }
    public function updateOrders()
    {
        // hada circuit dial commande en attente
        // 8 confirmer
        // 1 bqate en attente

        $array = [
            8 => 38,
            70 => 42,
            83 => 42,
            84 => 42,
            71 => 43,
            62 => 43,
            1 => 41,
            68 => 40,
            86 => 55,
            85 => 54,
        ];
    }
    /**
     * GET Retrieve all orders.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllOrders(Request $requests)
    {
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
        $orders = collect($requests)->map(function ($order) use ($accountUsers) {
            $products = [];


            $request['products'] = $products;
            if (isset($request['products']))
                if ($request['products']) {
                    $storeResponse = OrderController::store(new Request([$request]));
                    $storePayload = method_exists($storeResponse, 'getData') ? $storeResponse->getData(true) : [];
                    if (($storePayload['statut'] ?? 0) == 1) {
                        $this->updateOrder($order['id'], 'pending');
                    }
                }
        })->filter()->values()->toArray();

        return response()->json($orders);
    }

    /**
     * GET Retrieve a specific order.
     *
     * @param  int  $id Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrder($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'orders/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $order = $response->json();

        return response()->json($order);
    }

    /**
     * POST Create a new order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createOrder(Request $request)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'orders';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::post($url, $data);
        $createdOrder = $response->json();

        return response()->json($createdOrder);
    }

    /**
     * PUT Update an existing order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrder($id, $status)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'orders/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;
        $data = [
            'status' => $status
        ];
        // $data = $request->all();

        $response = Http::put($url, $data);
        $updatedOrder = $response->json();

        return response()->json($updatedOrder);
    }

    /**
     * DELETE Delete an order.
     *
     * @param  int  $id Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteOrder($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'orders/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::delete($url);
        $deletedOrder = $response->json();

        return response()->json($deletedOrder);
    }

    /**
     * GET Retrieve all customers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllCustomers()
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'customers';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $customers = $response->json();

        return response()->json($customers);
    }

    /**
     * GET Retrieve a specific customer.
     *
     * @param  int  $id Customer ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCustomer($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'customers/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $customer = $response->json();

        return response()->json($customer);
    }

    /**
     * POST Create a new customer.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createCustomer(Request $request)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'customers';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::post($url, $data);
        $createdCustomer = $response->json();

        return response()->json($createdCustomer);
    }

    /**
     * PUT Update an existing customer.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id Customer ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCustomer(Request $request, $id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'customers/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::put($url, $data);
        $updatedCustomer = $response->json();

        return response()->json($updatedCustomer);
    }

    /**
     * DELETE Delete a customer.
     *
     * @param  int  $id Customer ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteCustomer($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'customers/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::delete($url);
        $deletedCustomer = $response->json();

        return response()->json($deletedCustomer);
    }

    /**
     * GET Retrieve all coupons.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllCoupons()
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'coupons';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $coupons = $response->json();

        return response()->json($coupons);
    }

    /**
     * GET Retrieve a specific coupon.
     *
     * @param  int  $id Coupon ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoupon($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'coupons/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $coupon = $response->json();

        return response()->json($coupon);
    }

    /**
     * POST Create a new coupon.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createCoupon(Request $request)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'coupons';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::post($url, $data);
        $createdCoupon = $response->json();

        return response()->json($createdCoupon);
    }

    /**
     * PUT Update an existing coupon.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id Coupon ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCoupon(Request $request, $id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'coupons/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::put($url, $data);
        $updatedCoupon = $response->json();

        return response()->json($updatedCoupon);
    }

    /**
     * DELETE Delete a coupon.
     *
     * @param  int  $id Coupon ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteCoupon($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'coupons/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::delete($url);
        $deletedCoupon = $response->json();

        return response()->json($deletedCoupon);
    }

    /**
     * GET Retrieve a specific product category.
     *
     * @param  int  $id Product Category ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductCategory($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'product_categories/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $productCategory = $response->json();

        return response()->json($productCategory);
    }

    /**
     * POST Create a new product category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createProductCategory(Request $request)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'product_categories';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::post($url, $data);
        $createdProductCategory = $response->json();

        return response()->json($createdProductCategory);
    }

    /**
     * PUT Update an existing product category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id Product Category ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProductCategory(Request $request, $id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'product_categories/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $data = $request->all();

        $response = Http::put($url, $data);
        $updatedProductCategory = $response->json();

        return response()->json($updatedProductCategory);
    }

    /**
     * DELETE Delete a product category.
     *
     * @param  int  $id Product Category ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteProductCategory($id)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'product_categories/' . $id;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::delete($url);
        $deletedProductCategory = $response->json();

        return response()->json($deletedProductCategory);
    }

    /**
     * GET Retrieve all product variations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllVariations()
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/variations';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;

        $response = Http::get($url);
        $variations = $response->json();

        return response()->json($variations);
    }

    /**
     * GET Retrieve all product attributes.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllAttributes()
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/attributes';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;
        $response = Http::get($url);
        $attributes = $response->json();
        $datas = [];
        if ($attributes) {
            foreach ($attributes as $attribute) {
                $attributeMeta = $attribute['id'];
                $attributeName = $attribute['name'];
                $accountUsers = Account::find(getAccountUser()->account_id)->accountUsers->pluck('id')->toArray();
                $typeAttribute = TypeAttribute::where('meta->id', $attributeMeta)->whereIn("account_user_id", $accountUsers)->first();
                if (!$typeAttribute) {
                    $datas[] = $typeAttribute;
                    TypeAttribute::create([
                        'meta' => [
                            'id' => $attribute['id'],
                            'name' => $attribute['name'],
                            'slug' => $attribute['slug']
                        ],
                        'title' => $attributeName,
                        'description' => '',
                        'account_user_id' => getAccountUser()->id
                    ]);
                }
            }
        }
        return response()->json($attributes);
    }
    public function getAllTermes($attributeMeta)
    {
        $accountUsers = Account::find(getAccountUser()->account_id)->accountUsers->pluck('id')->toArray();
        $attributeType = TypeAttribute::where('meta->id', $attributeMeta)->whereIn("account_user_id", $accountUsers)->first();

        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/attributes/' . $attributeMeta . '/terms';
        $i = 1;
        $noMoreData = false;
        $datas = [];
        while ($noMoreData == false) {
            $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;
            $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret . '&page=' . $i;
            $response = Http::get($url);
            $termes = $response->json();
            if ($termes) {
                foreach ($termes as $terme) {
                    $datas[] = $terme;
                    $termeMeta = $terme['id'];
                    $termeName = $terme['name'];
                    $term = Attribute::where('meta->id', $termeMeta)->whereIn("account_user_id", $accountUsers)->first();
                    if ($term) {
                        $term->update([
                            'meta' => [
                                'id' => $terme['id'],
                                'name' => $terme['name'],
                                'slug' => $terme['slug']
                            ]
                        ]);
                    } else {
                        Attribute::create(['meta' => [
                            'id' => $terme['id'],
                            'name' => $terme['name'],
                            'slug' => $terme['slug']
                        ], 'title' => $termeName, 'description' => '', 'account_user_id' => getAccountUser()->id, 'types_attribute_id' => $attributeType->id]);
                    }
                }
            } else {
                $noMoreData = true;
            }
            $i++;
        }
        return response()->json($datas);
    }
    public function getAttribute($attributeId)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'products/attributes/' . $attributeId;
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret;
        $response = Http::get($url);
        $terme = $response->json();
        return $terme;
    }
}
