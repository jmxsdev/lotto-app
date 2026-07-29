FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql bcmath

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix

COPY . .

RUN chmod -R 775 storage bootstrap/cache && \
    mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs && \
    chmod -R 777 storage/framework storage/logs

EXPOSE 10000

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

CMD ["/usr/local/bin/entrypoint.sh"]
