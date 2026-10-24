FROM dunglas/frankenphp:1.13.3-php8.4-alpine AS build

RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
# Redis is required: config/cache.php and the queue both use it, and a missing
# extension fails boot with "Class Redis not found".
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps
RUN docker-php-ext-install pcntl pdo pdo_mysql

FROM dunglas/frankenphp:1.13.3-php8.4-alpine AS runtime

RUN apk add supervisor

COPY --from=build /usr/local/bin/composer /usr/local/bin/composer
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions

COPY supervisord.conf /etc/supervisord.conf

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
