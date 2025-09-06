# Stage 1: Cài dependencies bằng Composer
FROM composer:2.6 AS vendor
WORKDIR /app

# Copy toàn bộ project vào container
COPY . .

# Cài dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist


# Stage 2: PHP runtime
FROM php:8.2-fpm

# Cài extension Laravel cần
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev \
    && docker-php-ext-install pdo pdo_mysql zip gd

WORKDIR /var/www/html

# Copy từ stage vendor
COPY --from=vendor /app /var/www/html

# Phân quyền storage & bootstrap/cache
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

# Chạy Laravel server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
