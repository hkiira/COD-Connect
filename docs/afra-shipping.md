# Afra Delivery integration

API reference: https://www.afradelivery.com/api-documentation/seller (Seller API 1.0.1).

## Install / deploy

1. `.env`: `AFRA_CARRIER_ID` = `carriers.id` of AFRA DELIVERY (26 by default). On the Next.js
   side, `NEXT_PUBLIC_AFRA_CARRIER_ID` must have the same value.
2. Migrations (in this order):
   - `2026_09_27_000001_create_afra_shipping_tables` (mapping, operation and run tables)
   - `2026_10_05_100000_encrypt_account_carrier_credentials` (carrier passwords encrypted with `APP_KEY`)
   - `2026_10_05_110000_rework_afra_integration` (account settings, status → comment mapping)
   - `2026_10_05_120000_create_afra_city_watch_and_notifications` (city list copy, changes, bell notifications)
   Keep `APP_KEY` stable across deploys: the stored passwords are encrypted with it.
3. Cron (cPanel): `* * * * * php /path/to/artisan schedule:run`. Nothing else: there is no
   permanent queue worker; the scheduler starts `queue:work --queue=afra --stop-when-empty`
   every minute, so a sync started from the app begins within a minute.
4. The `jobs` table must exist (database queue).

If the server reports cURL error 60 (PHP has no CA bundle, e.g. WAMP), set `AFRA_CA_BUNDLE` to a
readable CA bundle path; locally, Git for Windows ships one:
`AFRA_CA_BUNDLE="C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt"`
(never disable TLS verification), then `php artisan config:clear`.

## Setup in the app

Carrier AFRA DELIVERY → **Afra settings**:

- **Compte**: seller email + password, "client can test the parcel" option (`test_product`),
  "Tester la connexion".
- **Villes**: link each Afra city to a local city (`default_carriers.city_id_carrier`). An order
  whose city is not linked is not sent.
- **Statuts**: for each Afra status, the status comment an agent would choose. Unlinked
  statuses are ignored (listed in the run message).

## What happens

| When | Afra call | Code |
|---|---|---|
| Pickup "Synchroniser" | `create-order` for each order without shipping code | `AfraShippingService::syncPickup` |
| An Afra order is edited (customer, address, products, totals, note) | `update-order` | `OrderController::update`, `CustomerController::update` |
| An agent puts an Afra order in "En souffrance" (9) | `return-request` (once) | `OrderController::update` |
| Every 15 min, or "Sync statuts" | `get-orders` until every open local Afra order is seen | `AfraShippingService::syncStatuses` |

- **Authentication**: `POST /login` → `access_token`, cached per account
  (`AFRA_TOKEN_TTL`, 3000 s); on a 401 the token is renewed once and the call retried.
  Cities, agencies and statuses also need the token (the documentation shows them without one,
  the API answers 401), and are cached one hour.
- **Amounts**: Afra has no discount field. The amount to collect (products − discount + shipping
  charged to the customer) is spread over the product lines to the cent.
- **Client name** is sent as `Name - ORDERCODE` (as in the Excel export). It is how an order whose
  creation got no clear answer (timeout, 5xx) is found again in Afra's list: the next send looks
  for it first and only creates it again when it is not there. Order page → "Renvoyer à Afra".
- **Incoming statuses** add the mapped comment to the order history and set its status. They never
  trigger `update-order` or `return-request`. Each Afra status is applied once per order
  (`afra_order_operations.remote_status`).
- A return request that was refused or got no answer can be retried from the order page.

## City list changes

Afra has no webhook. `afra:check-cities` (daily at 06:00, or "Vérifier maintenant" in Villes)
compares `get-cities` with the copy in `afra_cities` and records each change in `afra_city_changes`:

- **added**: linked automatically when a local city has the same name (accents, case and
  punctuation ignored); otherwise listed with "Associer" / "Créer et associer".
- **renamed**, **removed**: listed for information.
- **price**: listed with "Appliquer" (copies Afra's price into the local Afra tariff). Never applied
  automatically: local prices may be special prices.

The first run only saves the copy. When something changed, every active user of the accounts that
use Afra gets a bell notification (Laravel database notifications, `GET /api/notifications`).

## Returns, payments, tracking (Afra page tabs)

Afra's API has no invoice or return-slip endpoint; the end of the cycle is done in the app
(`AfraOperationsService`, slips created with `ShipmentController::store` like the ASAP sync):

- **Statuts**: a status can be ticked **Retour** (`afra_status_mappings.is_return`). Comments leading to
  Payée (10) / Retournée (11) cannot be mapped: only a payment / return slip sets those.
- **Retours**: orders whose last Afra status is a "Retour" status and that are on no slip. The warehouse
  scans each parcel received (order code or Afra number), then "Créer le bon de retour" makes one
  FR slip: stock back, orders Retournée. The known delivery fee is kept.
- **Paiements**: Afra sends a PDF only. Paste its text: the Afra numbers (9–20 digits, phone numbers
  left out) are matched with delivered orders; fee = our Afra tariff (special price, else default),
  editable; "Créer le bon de paiement" makes one FL slip with the amount received.
- **Suivi**: last runs, orders whose send / return / delete / exchange failed or got no answer,
  orders not found at Afra (`missing_since`), and "Rechercher chez Afra" for orders without Afra number
  (by order code in the client name, else by phone when only one order matches on each side; preview,
  then "Appliquer"). Also on the pickup page.

Other calls:

- **delete-order**: when an Afra order leaves its Afra pickup or is cancelled, while its last Afra
  status is still before pickup (En attente, Confirmation en Attente, Confirmé…). Otherwise nothing is
  sent; a comment and a bell notification say it must be settled with Afra.
- **create-exchange**: after the creation of an exchange order (a sale linked to an original order
  that has an Afra number).
- **Delivery swap**: the order that takes over an Afra parcel sends `update-order` (new products/amount).

`get-agencies` (stock orders) exists in `AfraShippingClient` but nothing calls it.
