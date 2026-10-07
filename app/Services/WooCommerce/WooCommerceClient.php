<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerce\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the REST API of one WooCommerce store. Credentials go in the Authorization header
 * (never in the URL) and TLS is verified unless the store was explicitly set up otherwise.
 */
class WooCommerceClient
{
    public function __construct(private readonly Store $store)
    {
    }

    public static function fromStore(Store $store): self
    {
        return new self($store);
    }

    private function http(): PendingRequest
    {
        $verify = $this->store->verify_ssl
            ? ($this->store->ca_bundle ?: config('services.woocommerce.ca_bundle') ?: true)
            : false;

        return Http::withBasicAuth((string) $this->store->consumer_key, (string) $this->store->consumer_secret)
            ->withOptions(['verify' => $verify])
            ->timeout((int) config('services.woocommerce.timeout', 30))
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return $this->store->apiUrl() . '/' . ltrim($path, '/');
    }

    /** @throws WooCommerceException */
    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $method === 'get'
                ? $this->http()->get($this->url($path), $data)
                : $this->http()->{$method}($this->url($path), $data);
        } catch (ConnectionException $e) {
            throw new WooCommerceException('Cannot reach the store: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $message = $response->json('message') ?: ('HTTP ' . $response->status());
            throw new WooCommerceException($message, $response->status());
        }

        return $response;
    }

    /** @return array{data: array, total: int, total_pages: int} */
    public function paginated(string $path, array $query = []): array
    {
        $response = $this->send('get', $path, $query);

        return [
            'data' => $response->json() ?? [],
            'total' => (int) $response->header('X-WP-Total'),
            'total_pages' => (int) $response->header('X-WP-TotalPages'),
        ];
    }

    /** Smallest authenticated call: tells whether the URL and the keys work and what the store sells. */
    public function ping(): array
    {
        $orders = $this->paginated('orders', ['per_page' => 1]);
        $products = $this->paginated('products', ['per_page' => 1]);

        return ['orders' => $orders['total'], 'products' => $products['total']];
    }

    public function orders(array $query = []): array
    {
        return $this->paginated('orders', $query);
    }

    public function order(int $id): array
    {
        return $this->send('get', "orders/{$id}")->json();
    }

    public function updateOrderStatus(int $id, string $status): array
    {
        return $this->send('put', "orders/{$id}", ['status' => $status])->json();
    }

    public function products(array $query = []): array
    {
        return $this->paginated('products', $query);
    }

    public function product(int $id): array
    {
        return $this->send('get', "products/{$id}")->json();
    }

    public function variations(int $productId, array $query = []): array
    {
        return $this->paginated("products/{$productId}/variations", $query + ['per_page' => 100]);
    }
}
