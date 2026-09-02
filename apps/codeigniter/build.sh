#!/bin/sh
# CI4 build steps: ensure writable dirs exist and are owned by the FPM user.
mkdir -p writable/cache writable/logs writable/session writable/temp
chown -R www-data:www-data writable
