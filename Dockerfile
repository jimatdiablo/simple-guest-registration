FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron snmp libsnmp-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY docker/scheduler/cron-entrypoint.sh /usr/local/bin/sgr-cron-entrypoint
RUN chmod +x /usr/local/bin/sgr-cron-entrypoint

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY app/ /var/www/html/
RUN mkdir -p /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage
