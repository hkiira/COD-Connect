# Production deployment with aaPanel

The workflow at `.github/workflows/deploy-production.yml` deploys every push to
`main`. It connects to the aaPanel server over SSH, fast-forwards the existing
Git checkout, installs PHP dependencies, runs migrations, refreshes Laravel's
cache, and signals queue workers to restart.

The workflow never copies `.env` or ignored runtime files from GitHub. It also
aborts when tracked files on the server have been edited manually.

## One-time server setup

1. Create the website in aaPanel and make sure its document root is the Laravel
   `public` directory.
2. Clone `https://github.com/hkiira/COD-Connect.git` into the application
   directory, or use aaPanel's Git deployment feature. The checkout must have
   an `origin` remote and the `main` branch.
3. Give the SSH deploy user read/write access to the application directory and
   read access to `.env`, `storage`, and `bootstrap/cache`.
4. Make sure the server can fetch the private GitHub repository. The usual
   aaPanel setup is an SSH deploy key generated for the server and added to the
   repository under **Settings → Deploy keys**. This key is separate from the
   key used by GitHub Actions to log in to the server.
5. Verify that the CLI PHP version is PHP 8.3 or newer. If `php` does not
   point to that version, set the optional `DEPLOY_PHP_BIN` secret to the full
   aaPanel PHP path, for example `/www/server/php/83/bin/php`.

Do not commit the server `.env` file. Keep `APP_KEY` unchanged across deploys.

## GitHub repository secrets

Add these under **Repository → Settings → Secrets and variables → Actions**:

| Secret | Required | Value |
| --- | --- | --- |
| `DEPLOY_HOST` | yes | Server IP or hostname |
| `DEPLOY_PORT` | no | SSH port; leave unset for `22` |
| `DEPLOY_USER` | yes | Dedicated SSH deploy user, not the aaPanel password |
| `DEPLOY_KEY` | yes | Private key whose public key is in the server user's `authorized_keys` |
| `DEPLOY_KNOWN_HOSTS` | yes | Verified output of `ssh-keyscan -p PORT HOST` |
| `DEPLOY_PATH` | yes | Absolute application path, such as `/www/wwwroot/example.com` |
| `DEPLOY_PHP_BIN` | no | Full PHP CLI path when `php` is not PHP 8.3+ |
| `DEPLOY_COMPOSER_BIN` | no | Composer executable path when `composer` is not in `PATH` |
| `DEPLOY_NPM_BUILD` | no | Set to `true` only when the server must build Vite assets |

Use an ED25519 key for the Actions-to-server connection. Generate it on a
trusted machine, add only its public key to the server, and paste only the
private key into `DEPLOY_KEY`. Never send the private key in chat or commit it
to the repository.

Before adding `DEPLOY_KNOWN_HOSTS`, compare the server fingerprint with the one
shown by aaPanel or your hosting provider. This prevents an SSH key
misconfiguration from silently connecting to the wrong host.

## First test

After adding the secrets, open the repository's **Actions** tab and run
**Deploy production → Run workflow**. Later pushes to `main` will deploy
automatically. A failed run normally means one of these is missing: the SSH
authorized key, the verified known-hosts entry, the application path, or the
server's GitHub deploy key.

## Database maintenance after a deploy or a restore

The order, product and catalog indexes live in idempotent migrations (they skip
what already exists). After a restore from a dump, or on a first deploy, run:

```bash
php artisan migrate --force
```

If a dump taken before these migrations is restored, the indexes on `orders`
(`account_id, order_status_id, created_at`, `account_id, code`, `shipping_code`,
`pickup_id, order_status_id`) and `order_comment` disappear and the order list
becomes slow; running the command above recreates them. On a large table the
first run can take about 15 seconds.

Data repair commands are dry runs unless `--apply` is passed:

| Command | Purpose |
| --- | --- |
| `php artisan orders:backfill-type` | Mark old returns/exchanges with `type = 'return'`. |
| `php artisan orders:dedupe-codes --since=YYYY-MM-DD` | List duplicate order codes; `--apply` renames them (changes visible codes). |

## WooCommerce control panel

Stores are configured in the app (WooCommerce → Stores): URL, REST keys (stored encrypted), defaults and
auto-import. The keys are no longer read from `.env` by the screens. After deploying:

1. `php artisan migrate --force` creates the `woocommerce_*` tables.
2. For an installation that used the single store of the `.env` file, run once (dry run first):

   ```bash
   php artisan woocommerce:migrate-legacy --account=<account id that owns the store>
   php artisan woocommerce:migrate-legacy --account=<account id> --apply
   ```

   It creates the store from `WOOCOMMERCE_BASE_URL` / `_CONSUMER_KEY` / `_CONSUMER_SECRET`, copies the variation
   ids kept in `product_variation_attribute.meta` and the order ids kept in `orders.meta` into the link tables
   (the old columns are left untouched) and seeds the status mapping.
3. The scheduler (`php artisan schedule:run` every minute, already needed for Afra) now runs:
   - `wc:sync-stores` every 5 minutes: imports the new, fully matched orders of the stores that have auto-import on;
   - a worker for the `woocommerce` queue every minute: the automatic status pushes are queued by the order
     observer, so a slow store never slows the screen that changed a status. Failures are retried 3 times and
     listed in WooCommerce → Activity, where they can be retried by hand.
4. Statuses are pushed back only for the statuses switched on in WooCommerce → Status mapping.

The old `wc:sync-processing-orders` command and the `api/woocommerce/{model}` proxy are removed. The REST keys
that used to be written in the code are still in the git history: **rotate them in WooCommerce**. The remaining
one-off importer (`ImportController`) reads its keys from `WOOCOMMERCE_IMPORT_KEY` / `WOOCOMMERCE_IMPORT_SECRET`,
and `OldSysController` from the `WOOCOMMERCE_*` variables.
