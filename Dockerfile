# syntax=docker/dockerfile:1.7
#
# AnnouncementSlides web app image: nginx + php-fpm + queue worker + scheduler
# in one container (supervisord). MySQL only: the database is a separate service.
# See docker/README.md.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---- 1. PHP dependencies (arch-independent, runs on the build host) ----------
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress \
        --prefer-dist --ignore-platform-reqs
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY routes routes
RUN composer dump-autoload --no-dev --optimize --no-scripts --ignore-platform-reqs

# ---- 2. Frontend build (arch-independent, runs on the build host) ------------
FROM --platform=$BUILDPLATFORM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY resources resources
COPY public public
# resources/js/app.js imports Ziggy from vendor/
COPY --from=vendor /app/vendor/tightenco vendor/tightenco
RUN npm run build

# ---- 3. Runtime ---------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS app

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor ffmpeg su-exec curl tzdata mysql-client mariadb-connector-c \
 && install-php-extensions gd pdo_mysql zip pcntl opcache exif intl bcmath \
 && rm -f /usr/local/bin/install-php-extensions \
 && mkdir -p /run/nginx

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor vendor
COPY --from=assets --chown=www-data:www-data /app/public/build public/build
COPY --from=assets --chown=www-data:www-data /app/public/upscaler-models public/upscaler-models

RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache \
 && su-exec www-data php artisan package:discover --ansi

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION} \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=mysql

# Everything that must survive a container rebuild lives under storage/
# (uploads, thumbnails, widget bundles). The database is MySQL, a separate service.
VOLUME ["/var/www/html/storage"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up >/dev/null || exit 1

LABEL org.opencontainers.image.source="https://github.com/fiforms/announcementslides" \
      org.opencontainers.image.description="AnnouncementSlides web app"

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
