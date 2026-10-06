<?php

namespace App\Services;

use App\Models\AccountCarrier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AFRA Delivery Seller API (https://www.afradelivery.com/api-documentation/seller), for one account.
 *
 * Authentication: POST /login with the seller email + password returns an access_token, sent as
 * "Authorization: Bearer". The token is cached per account; on a 401 it is dropped and the call is
 * retried once with a fresh login. Every call needs the token, the reference lists too (cities,
 * agencies, statuses), although the documentation shows them without one.
 *
 * Errors: AfraException when Afra answers with a refusal (4xx, or "status" other than "success"),
 * AfraUncertainException when we cannot know whether the call was applied (timeout, 5xx).
 */
class AfraShippingClient
{
    /** A read that times out is tried this many times in all. */
    private const READ_ATTEMPTS = 3;

    private ?AccountCarrier $link = null;

    public function __construct(private int $accountId)
    {
    }

    public static function carrierId(): int
    {
        return (int) config('services.afra.carrier_id');
    }

    // region auth

    /** The account's Afra login (account_carrier row), credentials required. */
    public function link(): AccountCarrier
    {
        $this->link ??= AccountCarrier::where('account_id', $this->accountId)->where('carrier_id', self::carrierId())->first();
        if (!$this->link?->username || !$this->link?->password) {
            throw new AfraException('Identifiants Afra non configurés pour ce compte.');
        }

        return $this->link;
    }

    /** Logs in with the stored credentials (bypassing the cache) and returns the access token. */
    public function login(): string
    {
        $link = $this->link();
        $response = $this->send(fn () => $this->http()->post($this->url('login'), [
            'email'    => $link->username,
            'password' => $link->password, // decrypted by the EncryptedCredential cast
        ]));

        $token = $response->json('access_token');
        if (!$response->successful() || !$token) {
            throw new AfraException($response->status() === 401 || $response->status() === 422
                ? 'Email ou mot de passe Afra incorrect.'
                : 'Connexion à AfraDelivery impossible (HTTP '.$response->status().').');
        }
        Cache::put($this->tokenKey(), $token, config('services.afra.token_ttl'));

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    private function token(): string
    {
        return Cache::get($this->tokenKey()) ?? $this->login();
    }

    /** Per account and per login, so changing the credentials never reuses an old token. */
    private function tokenKey(): string
    {
        return 'afra_token_'.$this->accountId.'_'.md5((string) $this->link()->username.'|'.$this->link()->password);
    }

    // endregion

    // region endpoints

    /** GET /get-orders → ['pagination' => [...], 'orders' => [...]] */
    public function getOrders(int $page = 1, int $perPage = 100, ?int $statusId = null): array
    {
        return $this->authorized('get', 'get-orders', array_filter([
            'current_page' => $page,
            'per_page'     => $perPage,
            'status_id'    => $statusId,
        ]));
    }

    /** [['id', 'name', 'delivery_price'], ...] */
    public function getCities(): array
    {
        return $this->publicList('get-cities');
    }

    /** [['id', 'fr_name', 'color'], ...] */
    public function getStatuses(): array
    {
        return $this->publicList('get-available-status');
    }

    /** [['id', 'name'], ...] */
    public function getAgencies(): array
    {
        return $this->publicList('get-agencies');
    }

    /** Returns the Afra order number. */
    public function createOrder(array $payload): string
    {
        $json = $this->write('post', 'create-order', $payload, 'Création refusée par Afra.');
        // The documentation shows "order_number"; the live API has answered "success" without it.
        $number = collect(['order_number', 'data.order_number', 'number', 'data.number', 'order.number', 'data.order.number'])
            ->map(fn ($key) => data_get($json, $key))->first(fn ($value) => !empty($value));
        if (!$number) {
            Log::warning('Afra create-order: success without order number', ['response' => $json]);
            throw new AfraNumberMissingException('Afra a accepté la commande sans renvoyer de numéro.');
        }

        return (string) $number;
    }

    public function updateOrder(string $number, array $payload): void
    {
        $this->write('put', 'update-order', ['order_number' => $number] + $payload, 'Modification refusée par Afra.');
    }

    public function returnRequest(string $number): void
    {
        $this->write('post', 'return-request', ['order_number' => $number], 'Retour refusé par Afra.');
    }

    public function deleteOrder(string $number): void
    {
        $this->write('delete', 'delete-order', ['order_number' => $number], 'Suppression refusée par Afra.');
    }

    public function createExchange(string $oldNumber, string $newNumber): void
    {
        $this->write('post', 'create-exchange', [
            'old_order_number' => $oldNumber,
            'new_order_number' => $newNumber,
        ], 'Échange refusé par Afra.');
    }

    // endregion

    // region transport

    private function http(): PendingRequest
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
        return Http::acceptJson()->asJson()->timeout((int) config('services.afra.timeout', 60))->withOptions($options);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.afra.base_url'), '/').'/'.$path;
    }

    /**
     * Reference lists change rarely: cached for an hour, shared by all accounts. The documentation
     * shows them without a token, but the API answers 401 without one, so they are authenticated.
     */
    private function publicList(string $path): array
    {
        return Cache::remember('afra_'.$path, 3600, fn () => array_values($this->authorized('get', $path)));
    }

    /** A write call: Afra answers {"status": "success", "message": ...}. */
    private function write(string $method, string $path, array $data, string $refused): array
    {
        $json = $this->authorized($method, $path, $data);
        if (!self::isSuccess($json)) {
            Log::warning('Afra '.$path.': answer not understood as a success', ['response' => $json]);
            throw new AfraException((string) ($json['message'] ?? $refused));
        }

        return $json;
    }

    /**
     * The documentation answers {"status": "success"}; the live API has answered a 2xx with
     * "Order Deleted Successfully" and another status value. Accept the usual success forms.
     */
    private static function isSuccess(array $json): bool
    {
        $status = $json['status'] ?? $json['success'] ?? null;
        if ($status === true || $status === 1 || $status === 200) {
            return true;
        }
        if (is_string($status)) {
            return in_array(strtolower(trim($status)), ['success', 'ok', 'true', '200'], true);
        }

        return $status === null && str_contains(strtolower((string) ($json['message'] ?? '')), 'success');
    }

    /** Authenticated call, re-logging in once when the cached token was rejected. */
    private function authorized(string $method, string $path, array $data = []): array
    {
        // Afra's list pages can be slow: a read (GET) that times out is tried again. A write is never
        // repeated, since Afra may have applied it (it becomes "uncertain" instead).
        $attempts = $method === 'get' ? self::READ_ATTEMPTS : 1;
        $call = function (string $token) use ($method, $path, $data, $attempts) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    return $this->send(fn () => $this->http()->withToken($token)->{$method}($this->url($path), $data));
                } catch (AfraUncertainException $e) {
                    if ($attempt >= $attempts) {
                        throw $e;
                    }
                    sleep(2 * $attempt);
                }
            }
        };

        $response = $call($this->token());
        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $call($this->login());
        }

        if ($response->serverError()) {
            throw new AfraUncertainException('AfraDelivery ne confirme pas la requête (HTTP '.$response->status().').');
        }
        if (!$response->successful()) {
            $message = $response->json('message') ?: collect($response->json('errors') ?? [])->flatten()->implode(' ');
            throw new AfraException('AfraDelivery a refusé la requête (HTTP '.$response->status().')'.($message ? ': '.$message : '.'));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new AfraUncertainException('Réponse AfraDelivery non lisible.');
        }

        return $json;
    }

    /** A request that never reached Afra or timed out may still have been applied: uncertain. */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new AfraUncertainException('AfraDelivery injoignable: '.$e->getMessage(), 0, $e);
        }
    }

    // endregion
}
