# Production image for hosts that run containers (Render's free web service, Fly.io, any VPS).
# serversideup/php bundles PHP-FPM + Nginx tuned for Laravel, with pdo_mysql, opcache and Composer.
# It listens on 8080 and, with AUTORUN_ENABLED, runs `migrate --force`, `storage:link` and the
# config/route/view caches on every start, so env changes apply on the next deploy or restart.
FROM serversideup/php:8.3-fpm-nginx

ENV AUTORUN_ENABLED=true \
    PHP_OPCACHE_ENABLE=1 \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Build as root, then hand the files to the unprivileged user the image runs as.
USER root

# Dependencies first so they're cached between builds when only app code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data /var/www/html

USER www-data
