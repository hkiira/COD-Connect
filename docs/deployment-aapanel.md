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
