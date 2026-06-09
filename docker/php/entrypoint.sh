#!/bin/sh
set -e

php artisan migrate --force --isolated

exec "$@"
