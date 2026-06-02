# ASAP Delivery Integration

This document explains how to use the ASAP Delivery Morocco API integration in this Laravel project.

## Configuration

Add these environment variables to your `.env` file:

```env
ASAP_DELIVERY_BASE_URL=https://api.asapdelivery.ma
ASAP_DELIVERY_TOKEN=your_api_token
ASAP_DELIVERY_SECRET_KEY=your_secret_key
```

The config file is located at `config/asapdelivery.php`.

## Service Class

The integration is implemented in `App\Services\AsapDeliveryService`.

### Available methods

- `getCities(): array`
  - Calls `GET /cities.php`
  - Returns the list of cities and rates.

- `trackParcel(string $code): array`
  - Calls `GET /track.php`
  - Requires `tk`, `sk`, and `code`.
  - Throws `App\Exceptions\ParcelNotFoundException` if the parcel code is invalid.
  - Throws `App\Exceptions\AsapDeliveryException` for other API errors.

- `addParcel(array $data): string`
  - Calls `GET /addcolis.php`
  - Requires the payload keys:
    - `fullname`
    - `phone`
    - `city`
    - `address`
    - `price`
    - `product`
    - `qty`
    - `change`
    - `openpackage`
  - Optional keys:
    - `note`
    - `code2`
  - Returns the new parcel tracking code.

- `listParcels(): array`
  - Calls `GET /colislist.php`
  - Returns the list of parcels for the account.

- `updateParcelStatus(string $code, string $state, ?string $dateReported = null, ?string $note = null): bool`
  - Calls `GET /editstate.php`
  - Returns `true` when the update succeeds.

## Example usage

Use the service via dependency injection:

```php
use App\Services\AsapDeliveryService;

class SomeController extends Controller
{
    public function __construct(private AsapDeliveryService $asapDelivery)
    {
    }

    public function example()
    {
        $cities = $this->asapDelivery->getCities();
        $status = $this->asapDelivery->trackParcel('TRACK123');
        $code = $this->asapDelivery->addParcel([
            'fullname' => 'John Doe',
            'phone' => '0612345678',
            'city' => 'Rabat',
            'address' => '123 Main St',
            'price' => 250,
            'product' => 'T-shirt;Shoes',
            'qty' => '1;2',
            'note' => 'Leave at reception',
            'code2' => 'ORDER-1234',
            'change' => 0,
            'openpackage' => 1,
        ]);

        $list = $this->asapDelivery->listParcels();
        $updated = $this->asapDelivery->updateParcelStatus('TRACK123', 'Livré', now()->format('d/m/Y'), 'Delivered');

        return compact('cities', 'status', 'code', 'list', 'updated');
    }
}
```

## How to use the API and webhook in this project

### API usage

The integration exposes test routes in `routes/api.php`; they are useful for quick Postman verification and for direct development testing.

If your API uses authentication, include the same `Authorization` header or token method used elsewhere in the project.

### Endpoint: `GET /api/asapdelivery/cities`

- Purpose: retrieve available ASAP Delivery cities and rates.
- Request type: `GET`
- Body: none
- Example:

```bash
curl -X GET "https://your-app.test/api/asapdelivery/cities" \
  -H "Authorization: Bearer <token>"
```

- Expected response: JSON array of cities:

```json
[
  {"id": 1, "name": "Casablanca", "price": 50},
  {"id": 2, "name": "Rabat", "price": 45}
]
```

### Endpoint: `GET /api/asapdelivery/track/{code}`

- Purpose: track a single parcel by ASAP Delivery code.
- Request type: `GET`
- URL params: `code` is the parcel tracking code.
- Example:

```bash
curl -X GET "https://your-app.test/api/asapdelivery/track/TRACK123" \
  -H "Authorization: Bearer <token>"
```

- Expected response: parsed tracking data, e.g.:

```json
{
  "code": "TRACK123",
  "status": "Livré",
  "delivery_date": "2026-06-02",
  "events": [
    {"date": "2026-06-01", "state": "En attente de ramassage"}
  ]
}
```

- Failure behavior: returns a `ParcelNotFoundException` internally when the remote API responds with invalid code errors.

### Endpoint: `POST /api/asapdelivery/add`

- Purpose: create a parcel order in ASAP Delivery.
- Request type: `POST`
- Body type: `x-www-form-urlencoded`
- Required fields:
  - `fullname`
  - `phone`
  - `city`
  - `address`
  - `price`
  - `product`
  - `qty`
  - `change`
  - `openpackage`
- Optional fields:
  - `note`
  - `code2`
- Example:

```bash
curl -X POST "https://your-app.test/api/asapdelivery/add" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "fullname=John Doe" \
  -d "phone=0612345678" \
  -d "city=Rabat" \
  -d "address=123 Main St" \
  -d "price=250" \
  -d "product=T-shirt;Shoes" \
  -d "qty=1;2" \
  -d "note=Leave at reception" \
  -d "code2=ORDER-1234" \
  -d "change=0" \
  -d "openpackage=1"
```

- Expected response:

```json
{
  "code": "NEWCODE123"
}
```

- Failure behavior: returns an exception when the ASAP Delivery API reports missing or invalid parameters.

### Endpoint: `GET /api/asapdelivery/list`

- Purpose: retrieve the account parcel list from ASAP Delivery.
- Request type: `GET`
- Example:

```bash
curl -X GET "https://your-app.test/api/asapdelivery/list" \
  -H "Authorization: Bearer <token>"
```

- Expected response: JSON array of parcel records.

### Endpoint: `POST /api/asapdelivery/update-status`

- Purpose: update a parcel status for testing or driver simulation.
- Request type: `POST`
- Body type: `x-www-form-urlencoded`
- Fields:
  - `code`
  - `state`
  - `datereported` (optional)
  - `note` (optional)
- Example:

```bash
curl -X POST "https://your-app.test/api/asapdelivery/update-status" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "code=TRACK123" \
  -d "state=Livré" \
  -d "datereported=02/06/2026" \
  -d "note=Delivery confirmed"
```

- Expected response:

```json
{
  "success": true
}
```

### Webhook usage

The webhook endpoint is configured in `routes/api.php` as:

- `GET /api/webhooks/asap-delivery`

It is secured by the middleware `App\Http\Middleware\VerifyAsapDeliveryWebhook`, which requires the header:

- `X-Webhook-Source: AsapDelivery`

The webhook accepts query parameters:

- `code` — tracking code
- `state` — new status
- `datereported` — unix timestamp
- `note` — optional text

If the request is valid, the controller dispatches the event `App\Events\AsapDeliveryStatusUpdated` and returns:

```json
{"success":true}
```

Example webhook request:

```bash
curl -G "https://your-app.test/api/webhooks/asap-delivery" \
  -H "X-Webhook-Source: AsapDelivery" \
  --data-urlencode "code=TEST12345" \
  --data-urlencode "state=Livré" \
  --data-urlencode "datereported=1717180800" \
  --data-urlencode "note=Delivered on time"
```

### Using the service directly in code

Inject `App\Services\AsapDeliveryService` into a controller, job, or command.

```php
$service = app(App\Services\AsapDeliveryService::class);
$cities = $service->getCities();
$parcel = $service->trackParcel('TRACK123');
```

### Postman tips

For POST requests use `x-www-form-urlencoded` and send the required fields.

For the webhook, use `GET` and include the required custom header.

## API routes for Postman testing

These routes are registered in `routes/api.php` and are protected by `auth:api` and `VerifyDomain` middleware.

- `GET /api/asapdelivery/cities`
- `GET /api/asapdelivery/track/{code}`
- `POST /api/asapdelivery/add`
- `GET /api/asapdelivery/list`
- `POST /api/asapdelivery/update-status`

### Example Postman request for `add`

- URL: `POST https://your-app.test/api/asapdelivery/add`
- Body type: `x-www-form-urlencoded`
- Fields:
  - `fullname`
  - `phone`
  - `city`
  - `address`
  - `price`
  - `product`
  - `qty`
  - `note`
  - `code2`
  - `change`
  - `openpackage`

### Example Postman request for `update-status`

- URL: `POST https://your-app.test/api/asapdelivery/update-status`
- Body type: `x-www-form-urlencoded`
- Fields:
  - `code`
  - `state`
  - `datereported`
  - `note`

## Webhook endpoint

The webhook endpoint is `GET /api/webhooks/asap-delivery`.

### Required header

- `X-Webhook-Source: AsapDelivery`

### Query parameters

- `code` (string)
- `state` (string)
- `datereported` (unix timestamp)
- `note` (optional string)

### Example webhook call

```bash
curl -G https://your-app.test/api/webhooks/asap-delivery \
  -H 'X-Webhook-Source: AsapDelivery' \
  --data-urlencode 'code=TEST12345' \
  --data-urlencode 'state=Livré' \
  --data-urlencode 'datereported=1717180800' \
  --data-urlencode 'note=Delivered on time'
```

Expected response:

```json
{"success":true}
```

## Events

The webhook dispatches the `App\Events\AsapDeliveryStatusUpdated` event with:

- `code`
- `state`
- `timestamp`
- `note`

You can add listeners in `app/Providers/EventServiceProvider.php` to react to updates.

## Testing

Run the Laravel feature tests with:

```bash
php artisan test --filter AsapDeliveryServiceTest
```

If you use Postman, make sure your app is reachable and your API authentication is properly configured.
