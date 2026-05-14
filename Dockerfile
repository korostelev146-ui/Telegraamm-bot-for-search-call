FROM php:8.4-cli-alpine

RUN apk add --no-cache icu-libs sqlite-libs \
    && apk add --no-cache --virtual .build-deps icu-dev sqlite-dev \
    && docker-php-ext-install intl pdo_sqlite \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && composer run-script post-install-cmd --no-interaction \
    && chmod +x bin/console docker-entrypoint.sh

CMD ["./docker-entrypoint.sh"]
