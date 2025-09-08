# Stage 1: Cài dependencies bằng Composer
FROM composer:2.6 AS vendor
WORKDIR /app

# Copy toàn bộ source code vào (để có file artisan)
COPY . .

# Cài dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Stage 2: PHP runtime + Apache
FROM php:8.2-apache

# Cài extension Laravel cần + enable rewrite
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql zip gd \
    && a2enmod rewrite

# Chỉnh DocumentRoot về thư mục public của Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Copy code từ stage vendor
COPY --from=vendor /app /var/www/html

# Phân quyền storage & bootstrap/cache
RUN chmod -R 777 storage bootstrap/cache

# Expose port 80 (Apache default)
EXPOSE 80

# Apache sẽ start mặc định
