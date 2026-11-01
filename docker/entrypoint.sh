#!/usr/bin/env bash
set -euo pipefail

cd /app

# Environment is only known at container start, so the Laravel caches are built here
# rather than in the image. Disable with TANDEM_OPTIMIZE=false (for example in local dev).
if [ "${TANDEM_OPTIMIZE:-true}" = "true" ]; then
    php artisan optimize --no-interaction
fi

# Run migrations from exactly one role (the web container in compose.yaml).
if [ "${TANDEM_RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
