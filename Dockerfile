FROM php:7.4-apache

# Install PDO MySQL and SQLite extensions (pdo and pdo_sqlite are built-in, pdo_mysql installed)
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Ensure data and uploads directories exist with proper permissions
RUN mkdir -p /var/www/html/data /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html
