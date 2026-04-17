<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TypeAttribute;
use App\Models\Attribute;
use App\Models\City;
use App\Models\Customer;
use App\Models\Phone;
use App\Models\Product;
use App\Models\DefaultCarrier;
use App\Models\ProductVariationAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WoocommerceController extends Controller
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
                return $this->getAllOrders($id);
            case 'send_cities':
                return $this->sendCities();
            case 'export':
                return $this->exportOrdersToXlsx();
            case 'sync_product':
                return $this->syncProduct($request);
            case 'synced_attributes':
                return $this->syncedAttributes($request);
            case 'synced_products':
                return $this->syncedProducts($request);
            default:
                return "productsuppliers";
        }
        
    }
    public function sendCities()
    {
        $defaultCities=DefaultCarrier::where('carrier_id',22)->get();
        $cities = City::get();
        return $defaultCities->map(function ($defaultCity) {
            return ['id' => $defaultCity->city->id, 'name' => $defaultCity->city->titlear . " " . $defaultCity->city->title];
        });
    }
    public function syncedProducts(Request $request)
    {
        $datas=[];
        foreach ($request['wc_product_ids'] as $product) {
            $productModel = Product::whereRaw("JSON_CONTAINS(meta, '{\"id\": $product}')")->first();
            if($productModel) {
                $datas[]=$product;
            }
        }
        return $datas;
    }
    public function syncedAttributes(Request $request)
    {
        $datas=[];
        foreach ($request['wc_variation_ids'] as $variation) {
            $variationModel=ProductVariationAttribute::whereRaw("JSON_CONTAINS(meta, ?)", [json_encode(['id' => $variation])])->first();
            if($variationModel) {
                $datas[]=$variation;
            }
        }
        return $datas;
    }
    public function syncProduct(Request $request)
    {
        $product = Product::where('id', $request['selected_product_id'])->first();
        if($product){
            $variationsProduct=[];
            $variationsIdsProduct=[];
            foreach ($product->productVariationAttributes as $key=>$productVariationAttribute) {
                $variationAttribute = $productVariationAttribute->variationAttribute;
                if ($variationAttribute) {
                    $variationsProduct[$key] = $variationAttribute->childVariationAttributes->pluck('attribute_id')->toArray();
                        $variationsIdsProduct[$key] = $variationAttribute->id;
                }
            }
            foreach ($request['variations'] as $variation) {
                sort($variation["selected_attributes"]);
                $key = null;
                foreach ($variationsProduct as $index => $item) {
                        $sortedItem = $item;
                        sort($sortedItem);
                        if ($sortedItem === $variation["selected_attributes"]) {
                            $key = $index;
                            break;
                        }
                }
                if($key!== null){
                    $variationAttribute = $product->productVariationAttributes->where('variation_attribute_id', $variationsIdsProduct[$key])->first();
                    if ($variationAttribute) {
                        $existingMeta = $variationAttribute->meta ?? [];
                        // Ensure it's an array (in case it's stored as JSON string)
                        if (!is_array($existingMeta)) {
                            $existingMeta = json_decode($existingMeta, true) ?? [];
                        }

                        // Append new variation ID (only if it's not already in the array)
                        $newValue = ["id"=>$variation['wc_variation_id']];
                        if (!in_array($newValue, $existingMeta)) {
                            $existingMeta[] = $newValue;
                        }

                        // Update the meta column
                        $variationAttribute->update([
                            'meta' => $existingMeta,
                        ]);
                    }
                }
            }
            $existingMeta = $product->meta ?? [];
            // Ensure it's an array (in case it's stored as JSON string)
            if (!is_array($existingMeta)) {
                $existingMeta = json_decode($existingMeta, true) ?? [];
            }
            // Append new variation ID (only if it's not already in the array)
            $newValue = [
                    "id" => $request['wc_product_id'],
                    "name" => $request['wc_product_name'],
                    "slug" => $request['wc_product_slug'],
                    "permalink" => $request['wc_product_permalink'],
                ];
            if (!in_array($newValue, $existingMeta)) {
                $existingMeta[] = $newValue;
            }
            $product->update([
                "meta" => $existingMeta
            ]);
            return [
                'success' => true,
                'message' => 'Synchronisation effectuée avec succès.'
            ];
        }
        return [
            'success' => false,
            'message' => 'Produit séléctionner innexistant.'
        ];

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

    /**
     * GET Retrieve all orders.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllOrders($status)
    {
        $baseUrl = 'https://stylemen.net/wp-json/wc/v3/';
        $consumerKey = 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428';
        $consumerSecret = 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d';
        $endpoint = 'orders';
        $url = $baseUrl . $endpoint . '?consumer_key=' . $consumerKey . '&consumer_secret=' . $consumerSecret . "&status=" . $status . "&per_page=10";
        $response = Http::get($url);
        $orders = $response->json();
        $accountUsers = Account::find(getAccountUser()->account_id)->accountUsers->pluck('id')->toArray();
        $requests = collect($orders)->map(function ($order) use ($accountUsers) {
            $request = [];
            $city = City::where('id', $order['billing']['city'])->first();
            $request['customer']['name'] = $order['billing']['first_name'] == "  " ? "Client WebSite" : $order['billing']['first_name'];
            $request['customer']['phones'][] = ['title' => $order['billing']['phone'], 'principal' => true, 'phoneTypes' => [1]];
            $request['customer']['addresses'][] = ['title' => $order['billing']['address_1'], 'principal' => true, 'city_id' => $city ? $city->id : 4];
            $request['customer']['customer_type_id'] = 1;
            $request['warehouse_id'] = 30;
            $request['brand_source_id'] = 108;
            $request['payment_type_id'] = 1;
            $request['payment_method_id'] = 1;
            $request['order_status_id'] = 1;
            $request['meta'] = $order["id"];
            $products = [];
            foreach ($order["line_items"] as $item) {
                // $product = Product::where('meta->id', $item["product_id"])->orWhere('reference', $item["sku"])->whereIn("account_user_id", $accountUsers)->first();
                $variationId=$item["variation_id"];
                $pva=ProductVariationAttribute::whereRaw("JSON_CONTAINS(meta, ?)", [json_encode(['id' => $variationId])])->first();
                if ($pva) {
                    $attributes = Attribute::whereIn('meta->name', collect($item["meta_data"])->pluck('display_value')->toArray())->whereIn("account_user_id", $accountUsers)->get()->pluck('id')->toArray();
                    $products[] = [
                        "id" => $pva->product->id,
                        "quantity" => 1,//$item["quantity"],
                        "price" => $item["price"],
                        "attributes" => $pva->variationAttribute->childVariationAttributes->pluck('attribute_id')->toArray()
                    ];
                }
            }
            $request['products'] = $products;
            if (isset($request['products']))
                if ($request['products']) {
                    $storeResponse = OrderController::store(new Request([$request]));
                    $storePayload = method_exists($storeResponse, 'getData') ? $storeResponse->getData(true) : [];
                    if (($storePayload['statut'] ?? 0) == 1) {
                        $this->updateOrder($order['id'], 'completed');
                    }
                }
        })->filter()->values()->toArray();
        return response()->json([
            'status' => 'success',
            'message' => 'Order notification received.',
        ], 200);
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
