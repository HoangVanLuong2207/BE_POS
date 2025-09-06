# Stage 1: Build PHP dependencies với Composer
FROM composer:2.6 AS vendor

WORKDIR /app

# Copy toàn bộ project trước
COPY . .

# Cài dependency production
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist


# Stage 2: PHP Application
FROM php:8.2-fpm

# Cài thư viện hệ thống + PHP extensions cần cho Laravel
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install pdo pdo_mysql zip

WORKDIR /var/www/html

# Copy từ stage vendor (đã có vendor + artisan + source code)
COPY --from=vendor /app /var/www/html

# Quyền cho Laravel
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

# Lệnh chạy khi container start
CMD php artisan config:clear && \
    php artisan cache:clear && \
    php artisan route:clear && \
    php artisan serve --host=0.0.0.0 --port=8000
