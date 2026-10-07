# Production deployment with aaPanel

The application is deployed on the server itself: pull the code, then run `scripts/deploy.sh`. There is no
GitHub Actions deployment (it needed SSH secrets and a deploy user; it was removed).

## One-time server setup

1. Create the website in aaPanel with the Laravel `public` directory as document root.
2. Clone the repository into the application folder (the `origin` remote must point to
   `https://github.com/hkiira/COD-Connect.git`). For a private repository add the server's SSH key under
   **GitHub → Settings → Deploy keys** (read-only is enough) and clone with the SSH URL.
3. Create `.env` from `.env.example` and fill it in (database, `APP_KEY`, mail, the `WOOCOMMERCE_*` and `AFRA_*`
   variables below). Never commit `.env` and never change `APP_KEY` afterwards: stored credentials are encrypted
   with it.
4. Make sure PHP CLI is 8.3 or newer (`php -v`). If `php` points to an older version use the full aaPanel path, for
   example `/www/server/php/83/bin/php`.
5. The web user must be able to write `storage` and `bootstrap/cache`:
   `chown -R www:www storage bootstrap/cache`.
6. Add the cron below (aaPanel → Cron → Shell Script, every minute): the scheduler runs the Afra and WooCommerce
   jobs, so without it nothing is imported or pushed automatically.

   ```bash
   cd /www/wwwroot/<site> && php artisan schedule:run >> /dev/null 2>&1
   ```

## Deploying an update

Over SSH (or aaPanel → Terminal), in the application folder:

```bash
cd /www/wwwroot/<site>
bash scripts/deploy.sh                # branch main
bash scripts/deploy.sh feat/something # another branch
```

With another PHP or a Composer that is not in `PATH`:

```bash
PHP_BIN=/www/server/php/83/bin/php COMPOSER_BIN=/usr/local/bin/composer bash scripts/deploy.sh
```

The script refuses to run when tracked files were edited by hand on the server (`git stash` or
`git checkout -- .` first), fast-forwards to `origin/<branch>`, runs `composer install --no-dev`,
`php artisan migrate --force`, rebuilds the caches (`artisan optimize`) and restarts the queue workers. It never
touches `.env`, `storage` or any file that Git does not track.

If something goes wrong, go back to the previous commit and deploy again:

```bash
git log --oneline -5
git checkout <previous commit>    # then: php artisan optimize:clear && php artisan optimize
```

(Migrations are not undone automatically: restore the database backup if one has to be reverted.)

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
