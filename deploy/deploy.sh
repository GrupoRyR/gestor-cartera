#!/usr/bin/env bash
#
# Routine deploy of Gestor de cartera on cPanel (GoDaddy). Runs ON THE SERVER
# over SSH, never from a developer machine or an agent session:
#
#   cd ~/<app-folder> && bash deploy/deploy.sh
#
# Based on the cpanel-deploy skill template. Safe to run as often as needed.
# It never builds assets: the server has no Node, so public/build arrives
# compiled from main (the deploy session adds it in its own pull request).
# It contains no secrets: everything sensitive lives in the server's .env
# (chmod 600).
#
# Optional variables:
#   PHP=/usr/local/bin/php                PHP binary (the same one the cPanel cron uses)
#   COMPOSER_BIN="php ~/composer.phar"    Composer command when `composer` is not on PATH
#
# COMPOSER_BIN, not COMPOSER: Composer reads COMPOSER as the composer.json name.

set -euo pipefail

# Always work from the project root, wherever the script is called from.
cd "$(dirname "${BASH_SOURCE[0]}")/.."

PHP="${PHP:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

log() {
    printf '\n==> %s\n' "$1"
}

fail() {
    printf '\nERROR: %s\n' "$1" >&2
    exit 1
}

bring_up() {
    log "Bringing the application back up"
    "$PHP" artisan up || true
}

# Before touching anything: a .env copied from a development machine
# (APP_ENV=local, APP_DEBUG=true, http) must never reach production.
log "Checking the production .env"
[ -f .env ] || fail ".env is missing. Create it from .env.example first."
chmod 600 .env
bash deploy/check-env.sh .env || fail "Fix the .env values above and run the deploy again."

log "Fetching latest code"
git fetch --quiet

# Refuse to deploy a commit without compiled assets: every page would fail.
if ! git cat-file -e '@{upstream}:public/build/manifest.json' 2>/dev/null; then
    fail "The upstream branch has no public/build/manifest.json. Merge the assets pull request first."
fi

# On the first install there is no vendor/ yet, so maintenance mode is not available.
if [ -f vendor/autoload.php ]; then
    log "Entering maintenance mode"
    "$PHP" artisan down --retry=60
fi

# Whatever happens from here on, the site must not stay in maintenance mode.
trap bring_up EXIT

# Merge exactly the commit checked above: a second fetch (git pull) could
# bring a newer commit without public/build.
log "Updating to the checked commit"
git merge --ff-only '@{upstream}'

log "Installing PHP dependencies"
# shellcheck disable=SC2086 # COMPOSER_BIN may be "php composer.phar" on purpose.
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

log "Running database migrations"
"$PHP" artisan migrate --force

# DatabaseSeeder only runs each module's <Module>PermissionSeeder outside
# local (idempotent: it creates missing permissions and roles), so new
# permissions reach production with the code that checks them.
log "Syncing module permissions"
"$PHP" artisan db:seed --force

log "Caching config, routes, views and events"
"$PHP" artisan optimize

trap - EXIT
bring_up

log "Deploy finished"
