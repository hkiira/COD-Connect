<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AsapDeliveryException;
use App\Exceptions\ParcelNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class AsapDeliveryService
{
    protected PendingRequest $client;
    protected string $token;
    protected string $secretKey;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('asapdelivery.base_url'))
            ->timeout(15)
            ->connectTimeout(5);

        if (config('app.env') === 'local' || !config('asapdelivery.verify_ssl', true)) {
            $this->client = $this->client->withoutVerifying();
        }

        $this->token = config('asapdelivery.token');
        $this->secretKey = config('asapdelivery.secret_key');
    }

    /**
     * Get all available cities and rates.
     *
     * @return array
     * @throws AsapDeliveryException
     */
    public function getCities(): array
    {
        $response = $this->client->get('cities.php');

        if ($response->failed()) {
            throw new AsapDeliveryException('Failed to retrieve cities from ASAP Delivery API.');
        }

        return $response->json();
    }

    /**
     * Track a parcel by its code.
     *
     * @param string $code
     * @return array
     * @throws ParcelNotFoundException|AsapDeliveryException
     */
    public function trackParcel(string $code): array
    {
        $response = $this->client->get('track.php', [
            'tk' => $this->token,
            'sk' => $this->secretKey,
            'code' => $code,
        ]);

        if ($response->failed()) {
            if ($response->status() === 404) {
                $data = $response->json();
                if (isset($data['message']) && (str_contains($data['message'], "Code doesn't exist") || str_contains($data['message'], "incorrect credentials"))) {
                    throw new ParcelNotFoundException($data['message']);
                }
            }
            throw new AsapDeliveryException("ASAP Delivery API request failed: {$response->reason()}");
        }

        $body = $response->body();

        if ($body === "Code doesn't exist" || $body === "Parameters code is missing" || $body === "Parameters code is empty") {
            throw new ParcelNotFoundException($body);
        }

        $data = $response->json();
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AsapDeliveryException("Invalid JSON response while tracking parcel: {$code}");
        }

        return $data;
    }

    /**
     * Add a new parcel.
     *
     * @param array $data
     * @return string The tracking code of the new parcel.
     * @throws AsapDeliveryException
     */
    public function addParcel(array $data): string
    {
        $payload = array_merge([
            'tk' => $this->token,
            'sk' => $this->secretKey,
        ], Arr::only($data, [
                        'fullname',
                        'phone',
                        'city',
                        'address',
                        'price',
                        'product',
                        'qty',
                        'note',
                        'code2',
                        'change',
                        'openpackage'
                    ]));

        $response = $this->client->get('addcolis.php', $payload);

        if ($response->failed()) {
            throw new AsapDeliveryException("ASAP Delivery API request failed: {$response->reason()}");
        }

        $body = $response->body();

        if (str_starts_with($body, 'Package added successfully')) {
            return trim(str_replace('Package added successfully: ', '', $body));
        }

        throw new AsapDeliveryException("Failed to add parcel: {$body}");
    }

    /**
     * List all parcels for the account.
     *
     * @return array
     * @throws AsapDeliveryException
     */
    public function listParcels(): array
    {
        $response = $this->client->get('colislist.php', [
            'tk' => $this->token,
            'sk' => $this->secretKey,
        ]);

        if ($response->failed()) {
            throw new AsapDeliveryException("ASAP Delivery API request failed: {$response->reason()}");
        }

        return $response->json();
    }

    /**
     * Update the status of a parcel.
     *
     * @param string $code
     * @param string $state
     * @param string|null $dateReported (Format: DD/MM/YYYY)
     * @param string|null $note
     * @return bool
     * @throws AsapDeliveryException
     */
    public function updateParcelStatus(string $code, string $state, ?string $dateReported = null, ?string $note = null): bool
    {
        $payload = array_filter([
            'tk' => $this->token,
            'sk' => $this->secretKey,
            'code' => $code,
            'state' => $state,
            'datereported' => $dateReported,
            'note' => $note,
        ]);

        $response = $this->client->get('editstate.php', $payload);

        if ($response->failed()) {
            throw new AsapDeliveryException("ASAP Delivery API request failed: {$response->reason()}");
        }

        return $response->body() === "Package has been updated successfully.";
    }
}
