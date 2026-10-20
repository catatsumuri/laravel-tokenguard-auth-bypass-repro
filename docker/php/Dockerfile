FROM composer:2.8 AS composer
FROM php:7.4-apache

ARG WWWGROUP=1000
ARG WWWUSER=1000

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN groupadd --force -g ${WWWGROUP} sail \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} sail
COPY docker/php/000-default.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
