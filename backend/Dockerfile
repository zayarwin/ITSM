FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    mysql-client \
    oniguruma-dev

# Install PHP extensions (pdo_mysql instead of pdo_sqlite)
RUN docker-php-ext-install pdo pdo_mysql zip gd mbstring

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (layer cache optimization)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

# Copy application source
COPY . .

# Copy Docker-specific .env
COPY .env.docker .env

# Generate optimized autoloader and app key
RUN composer dump-autoload --optimize && \
    php artisan key:generate --no-interaction

# Prepare directories and permissions
RUN mkdir -p storage/logs storage/framework/cache \
        storage/framework/sessions storage/framework/views bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
