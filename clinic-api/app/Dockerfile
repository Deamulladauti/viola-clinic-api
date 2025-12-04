# =========================================
# 1) Build stage – install Composer deps
# =========================================
FROM composer:2 AS vendor

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev)
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader


# =========================================
# 2) Runtime stage – PHP 8.2 + Apache
# =========================================
FROM php:8.2-apache

# Install system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy app code
COPY . .

# Copy vendor from build stage
COPY --from=vendor /app/vendor ./vendor

# Configure Apache to serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite

# Laravel permissions
RUN chown -R www-data:www-data \
      storage \
      bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Expose HTTP port (Render maps this)
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
