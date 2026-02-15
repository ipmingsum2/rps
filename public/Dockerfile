FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY rps-party.php /var/www/html/index.php

ENV APACHE_DOCUMENT_ROOT=/var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
