FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
      libfreetype6-dev unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql mbstring zip intl bcmath pcntl gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY apps/api/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY apps/api/docker/php-production.ini /usr/local/etc/php/conf.d/99-maintaingov.ini

WORKDIR /var/www/html
COPY apps/api/ ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
       storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80
CMD ["apache2-foreground"]
