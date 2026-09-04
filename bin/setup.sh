#!/usr/bin/env bash
#
# Prepares the project for local development. Safe to run more than once.
# The only requirements on the host are Docker and Bash.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Used only to install Composer dependencies before the application image
# exists. It matches the PHP version in compose.yaml so that dependencies are
# resolved by the same PHP version that later runs them.
COMPOSER_IMAGE="laravelsail/php84-composer:latest"

if ! docker info > /dev/null 2>&1; then
    echo "Docker does not appear to be running. Start it and run this script again." >&2
    exit 1
fi

# This has to happen before "sail up". compose.yaml mounts a script out of
# vendor/ into the MySQL container, and if vendor/ is missing Docker silently
# creates a directory in its place, leaving the testing database uncreated.
if [ ! -d vendor ]; then
    echo "==> Installing Composer dependencies"
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --volume "$(pwd)":/var/www/html \
        --workdir /var/www/html \
        "$COMPOSER_IMAGE" \
        composer install --ignore-platform-reqs
fi

if [ ! -f .env ]; then
    echo "==> Creating .env from .env.example"
    cp .env.example .env
fi

echo "==> Starting containers"
# Blocks until MySQL reports healthy, because of the depends_on condition.
./vendor/bin/sail up --detach

# The key is only generated when absent so that reruns do not invalidate
# anything already encrypted with the existing key.
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> Generating application key"
    ./vendor/bin/sail artisan key:generate
fi

echo "==> Running migrations"
./vendor/bin/sail artisan migrate

echo
echo "Done. The API is available at http://localhost:8000"
