FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS \
    postgresql-dev \
    rabbitmq-c-dev \
    && docker-php-ext-install pdo pdo_pgsql sockets \
    && pecl install amqp redis \
    && docker-php-ext-enable amqp redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-interaction --optimize-autoloader --no-dev

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
