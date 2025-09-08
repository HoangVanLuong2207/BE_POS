# Stage 1: Composer install
FROM composer:2.6 AS vendor
WORKDIR /app

# Copy toàn bộ project (bao gồm artisan)
COPY . .

# Cài dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Stage 2: PHP + Apache
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql zip gd \
    && a2enmod rewrite

WORKDIR /var/www/html

# Copy code từ stage vendor
COPY --from=vendor /app /var/www/html

# Phân quyền cho Laravel
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 80

# 🚀 Khi container start → migrate DB rồi mới chạy Apache
CMD php artisan config:clear && \
    php artisan config:cache && \
    php artisan migrate --force && \
    apache2-foreground
