FROM dunglas/frankenphp:1-php8.4-alpine AS base

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions pdo_mysql pdo_sqlite mbstring gd zip \
    && printf "upload_max_filesize=8M\npost_max_size=10M\n" > "$PHP_INI_DIR/conf.d/zz-app.ini"

FROM base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

FROM base AS runtime

COPY --from=dependencies /app/vendor ./vendor
COPY Caddyfile /etc/caddy/Caddyfile
COPY . .
RUN mkdir -p /app/storage/admin-reports \
    && chmod -R 0777 /app/storage

ENV PORT=80
ENV SESSION_DRIVER=mysql
ENV PROFILE_PHOTO_STORAGE=database
ENV DRIVER_DOCUMENT_STORAGE=database
ENV ALA_EH_TERMINALS_DB=/tmp/ala-eh-terminals.sqlite

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
