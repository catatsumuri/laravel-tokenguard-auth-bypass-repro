FROM php:7.4-apache

RUN sed -i \
        -e 's|http://deb.debian.org/debian-security|http://archive.debian.org/debian-security|' \
        -e 's|http://deb.debian.org/debian|http://archive.debian.org/debian|' \
        /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until \
    && apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --2.2 \
    && rm /tmp/composer-setup.php \
    && composer global config --no-interaction allow-plugins.kylekatarnls/update-helper true

WORKDIR /work
