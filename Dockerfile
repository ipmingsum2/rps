# Dockerfile  (placed in the repository root)
FROM php:8.2-apache

# Install any PHP extensions you need
RUN apt-get update \
    && apt-get install -y libzip-dev unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Enable useful Apache modules
RUN a2enmod rewrite headers

# Copy only the public app into the Apache doc root
WORKDIR /var/www/html
COPY public/ /var/www/html/

# Ensure Apache can read the files
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
