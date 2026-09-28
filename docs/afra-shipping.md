# Afra Shipping (carrier 26)

Run `php artisan migrate` before enabling the integration. The migration adds
`default_carriers.city_id_carrier` only where missing and creates Afra mapping,
operation-state and job-progress tables. It does not overwrite existing carrier
prices or city mappings.

If the server reports cURL error 60, install a current CA bundle and set
`AFRA_CA_BUNDLE` in the Laravel environment to its absolute readable path.
Do not disable TLS verification. Clear cached configuration after changing it:
`php artisan config:clear`.

Run the scheduler each minute and a database queue worker for Afra jobs:

```sh
php artisan schedule:run
php artisan queue:work database --queue=afra --timeout=3600 --tries=1
```

Schedule `schedule:run` using the operating system's task scheduler/cron;
run the worker as a supervised long-running process. The scheduler queues
status synchronization every 15 minutes. Existing jobs on other queues are
unaffected. `withoutOverlapping` needs a writable Laravel cache store; this
project reads `CACHE_DRIVER` from the environment. Ensure the chosen store is
writable on the deployment host.

Open carrier 26's edit page, then **Paramètres Afra**. Enter seller email and
password in **Compte**, test the connection, map cities and statuses before
starting a pickup or status sync. The password is encrypted with `APP_KEY` and
is never returned by the Afra settings API. Keep `APP_KEY` stable across deploys.

After an uncertain `create-order` or `return-request` response, the operation
is deliberately not sent again automatically. Check the order in Afra first.
Only a confirmed failed return can be manually retried from the order page.
