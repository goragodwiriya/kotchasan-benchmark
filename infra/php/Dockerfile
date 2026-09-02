# Unified PHP-FPM image for every framework under test.
# Build context = repository root. ARG APP selects which app to copy.
ARG PHP_IMAGE=php:8.4-fpm-bookworm
FROM ${PHP_IMAGE}

# mlocati installer pulls distro packages; extension versions come with the
# pinned base image so the compiled stack stays reproducible.
COPY --from=mlocati/php-extension-installer:2.8 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring intl gd zip bcmath opcache

COPY --from=composer:2.8.11 /usr/bin/composer /usr/bin/composer

ARG APP
COPY apps/${APP} /app
# Shared benchmark helpers — byte-identical copy in every app container
COPY infra/php/bench_shared.php /app/bench_shared.php
WORKDIR /app

# Install dependencies (no-dev) if the app is composer-based
RUN if [ -f composer.json ]; then \
        COMPOSER_ALLOW_SUPERUSER=1 composer install \
            --no-dev --optimize-autoloader --no-interaction --no-progress \
            --ignore-platform-req=ext-redis; \
    fi

# App-specific build steps (e.g. Laravel config/route cache, Symfony cache warmup)
RUN if [ -f build.sh ]; then chmod +x build.sh && ./build.sh; fi

# Uniform PHP + FPM runtime configuration for every app
COPY infra/php/php.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY infra/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-bench.conf
