FROM php:8.2-apache@sha256:affc043fbd9acaa9a6394a71d162726fc0a6e4bea0400a3b94f925b6130858dd

ARG BUILD_DATE=""
ARG VCS_REF=""
ARG VERSION="dev"

LABEL org.opencontainers.image.title="Simple Guest Registration" \
      org.opencontainers.image.description="PHP and MySQL guest registration workflow with Gunslinger, DDNet, and SNMP integration hooks." \
      org.opencontainers.image.source="https://github.com/jimatdiablo/simple-guest-registration" \
      org.opencontainers.image.vendor="Diablo Data" \
      org.opencontainers.image.licenses="LicenseRef-Diablo-Data-Source-Available" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.version="${VERSION}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron curl snmp libsnmp-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY docker/scheduler/cron-entrypoint.sh /usr/local/bin/sgr-cron-entrypoint
COPY docker/app-entrypoint.sh /usr/local/bin/sgr-app-entrypoint
RUN chmod +x /usr/local/bin/sgr-cron-entrypoint /usr/local/bin/sgr-app-entrypoint

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY app/ /var/www/html/
RUN mkdir -p /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage

ENTRYPOINT ["sgr-app-entrypoint"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1/?action=health" || exit 1
