FROM php:8.3-apache

# Install sistem dependensi yang dibutuhkan (termasuk libwebp untuk dukungan WebP)
RUN apt-get update && \
    apt-get install -y \
        libsnmp-dev \
        snmp \
        libjpeg-dev \
        libpng-dev \
        libfreetype6-dev \
        libwebp-dev \
        libsqlite3-dev \
        zip \
        qpdf \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Konfigurasi dan install ekstensi GD (dengan support JPEG, FreeType, dan WebP)
RUN docker-php-ext-configure gd \
        --with-jpeg \
        --with-freetype \
        --with-webp \
    && docker-php-ext-install gd snmp mysqli pdo pdo_mysql pdo_sqlite

# Aktifkan mod_rewrite Apache untuk SEO / Pretty URLs
RUN a2enmod rewrite

# Izinkan file .htaccess (AllowOverride All)
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf