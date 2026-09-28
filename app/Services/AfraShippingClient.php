<?php

namespace App\Services;

use App\Models\AccountCarrier;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AfraShippingClient
{
    private ?string $token = null;

    public function __construct(private int $accountId)
    {
    }

    private function http()
    {
        $options = [];
        $bundle = config('services.afra.ca_bundle');
        if ($bundle) {
            if (!is_file($bundle) || !is_readable($bundle)) {
                throw new RuntimeException('Le CA bundle Afra est introuvable ou illisible.');
            }
            $options['verify'] = $bundle;
        }
        // TLS validation remains enabled when no custom bundle is configured.
        return Http::acceptJson()->asJson()->timeout(30)->withOptions($options);
    }

    public function login(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $link = AccountCarrier::where('account_id', $this->accountId)->where('carrier_id', 26)->first();
        if (!$link || !$link->username || !$link->password) {
            throw new RuntimeException('Identifiants Afra non configurés pour ce compte.');
        }
        try {
            $password = Crypt::decryptString($link->password);
        } catch (\Throwable $e) {
            throw new RuntimeException('Le mot de passe Afra doit être saisi à nouveau dans Compte.');
        }
        $response = $this->http()->post($this->url('login'), [
            'email' => $link->username,
            'password' => $password,
        ]);
        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException('Connexion à AfraDelivery impossible (HTTP '.$response->status().').');
        }
        return $this->token = (string) $response->json('access_token');
    }

    public function getOrders(int $page = 1, int $perPage = 100, ?int $statusId = null): array
    {
        $query = ['current_page' => $page, 'per_page' => $perPage];
        if ($statusId !== null) {
            $query['status_id'] = $statusId;
        }
        return $this->request('get', 'get-orders', $query);
    }

    public function getCities(): array
    {
        return $this->request('get', 'get-cities');
    }

    public function getStatuses(): array
    {
        return $this->request('get', 'get-available-status');
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', 'create-order', $payload);
    }

    public function updateOrder(array $payload): array
    {
        return $this->request('put', 'update-order', $payload);
    }

    public function returnRequest(string $number): array
    {
        return $this->request('post', 'return-request', ['order_number' => $number]);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $request = $this->http()->withToken($this->login());
        $response = $method === 'get'
            ? $request->get($this->url($path), $data)
            : $request->{$method}($this->url($path), $data);
        if (!$response->successful()) {
            if ($response->status() >= 500) {
                throw new AfraUncertainException('AfraDelivery ne confirme pas la requête (HTTP '.$response->status().').');
            }
            throw new RuntimeException('AfraDelivery a refusé la requête (HTTP '.$response->status().'): '.(string) $response->json('message'));
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new AfraUncertainException('Réponse AfraDelivery non lisible.');
        }
        return $json;
    }

    private function url(string $path): string
    {
        return rtrim(config('services.afra.base_url'), '/').'/'.$path;
    }
}
