#!/bin/sh
# Symfony build steps: bake env config and warm the production cache.
composer dump-env prod --no-interaction
php bin/console cache:warmup --env=prod
