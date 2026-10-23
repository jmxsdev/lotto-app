# PHP 8.3.36 fixes CVE-2026-109618 and CVE-2026-109619.
FROM dunglas/frankenphp:1-php8.3.36

# Fail the build if the base image ever resolves to a vulnerable PHP.
RUN php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);'

RUN install-php-extensions pdo_mysql bcmath zip redis pcntl posix @composer

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
