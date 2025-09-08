# Stage 1: Composer install
FROM composer:2.6 AS vendor
WORKDIR /app

# Copy toàn bộ project
COPY . .

# Tăng giới hạn RAM cho Composer
ENV COMPOSER_MEMORY_LIMIT=-1

# Cài dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Stage 2: PHP + Apache
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git unzip zlib1g-dev libzip-dev libpng-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql zip gd \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

RUN chmod -R 777 storage bootstrap/cache

EXPOSE 80

CMD php artisan config:clear && \
    php artisan config:cache && \
    php artisan migrate --force && \
    apache2-foreground
