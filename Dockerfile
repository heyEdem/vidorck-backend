FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd exif bcmath intl zip pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' 'memory_limit=512M' 'upload_max_filesize=8M' \
        'post_max_size=10M' > "$PHP_INI_DIR/conf.d/app.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/data/database.sqlite \
    LARAVEL_STORAGE_PATH=/var/data/storage \
    PORT=10000

WORKDIR /var/www/html

COPY . .

RUN mkdir -p bootstrap/cache \
        "$LARAVEL_STORAGE_PATH/framework/cache/data" \
        "$LARAVEL_STORAGE_PATH/framework/sessions" \
        "$LARAVEL_STORAGE_PATH/framework/views" \
        "$LARAVEL_STORAGE_PATH/logs" \
    && composer install --no-dev --prefer-dist --no-interaction \
        --no-progress --optimize-autoloader

COPY --chmod=755 bin/render-start /usr/local/bin/start-app

EXPOSE 10000

CMD ["/usr/local/bin/start-app"]
