#!/usr/bin/env bash
#
# Deploys the latest commit of a branch on the server itself (aaPanel). Run it from the application folder:
#
#   bash scripts/deploy.sh                 # branch main
#   bash scripts/deploy.sh feat/something  # another branch
#
# Optional environment: PHP_BIN (e.g. /www/server/php/83/bin/php), COMPOSER_BIN.
#
# It never touches .env, storage or other files that are not tracked by Git.

set -Eeuo pipefail

BRANCH="${1:-main}"
PHP_BIN="${PHP_BIN:-php}"

cd "$(dirname "${BASH_SOURCE[0]}")/.."

[[ -d .git ]] || { echo "This folder is not a Git checkout." >&2; exit 1; }
[[ -f artisan ]] || { echo "Run this from the Laravel application folder." >&2; exit 1; }

# the same PHP for artisan and for Composer's "#!/usr/bin/env php"
if [[ "$PHP_BIN" == */* ]]; then
    export PATH="$(dirname "$PHP_BIN"):$PATH"
fi

"$PHP_BIN" -r 'if (version_compare(PHP_VERSION, "8.3.0", "<")) { fwrite(STDERR, "PHP 8.3 or newer is required, found " . PHP_VERSION . PHP_EOL); exit(1); }'

run_composer() {
    if [[ -n "${COMPOSER_BIN:-}" ]]; then
        "$COMPOSER_BIN" "$@"
    elif command -v composer >/dev/null 2>&1; then
        composer "$@"
    elif [[ -f /www/server/composer/composer.phar ]]; then
        "$PHP_BIN" /www/server/composer/composer.phar "$@"
    elif [[ -f composer.phar ]]; then
        "$PHP_BIN" composer.phar "$@"
    else
        echo "Composer was not found: set COMPOSER_BIN." >&2
        exit 1
    fi
}

# do not overwrite changes made by hand on the server
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Tracked files were edited on the server; deploy aborted:" >&2
    git status --short >&2
    echo "Keep them (git stash) or drop them (git checkout -- .), then run this again." >&2
    exit 1
fi

echo "==> Getting $BRANCH"
git fetch --prune origin "$BRANCH"
if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
    git checkout --quiet "$BRANCH"
else
    git checkout --quiet -B "$BRANCH" "origin/$BRANCH"
fi
git merge --ff-only "origin/$BRANCH"

echo "==> Installing PHP dependencies"
run_composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

echo "==> Database migrations"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force

[[ -e public/storage ]] || "$PHP_BIN" artisan storage:link

echo "==> Caches and queue workers"
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart

echo "Deployed commit $(git rev-parse --short HEAD) on $BRANCH"
