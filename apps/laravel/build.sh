#!/bin/sh
# Laravel build steps: cache config/routes/views/events (standard production
# deployment optimization), then make runtime-writable dirs writable.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan event:cache --no-interaction
chmod -R ug+rwX storage bootstrap/cache
