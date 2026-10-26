# PHP 8.3: 8.2 deja de recibir parches el 31 de diciembre de 2026 y corría con
# PHP2Shell (CVE-2026-109618 y CVE-2026-109619, corregidos en 8.3.36).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql zip

RUN a2enmod rewrite

# El progreso de subida en sesión es la vía de PHP2Shell y de CVE-2026-115560,
# que a 2026-10-25 todavía no tiene parche. La aplicación no lo usa.
RUN echo "session.upload_progress.enabled = 0" > "$PHP_INI_DIR/conf.d/sin-upload-progress.ini"

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html