ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    bash \
    curl-dev \
    libzip-dev \
    zlib-dev \
    ca-certificates \
    linux-headers \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    icu-dev \
    icu-data-full \
    libxml2-dev \
    sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install exif gd intl pdo_sqlite zip \
    && apk del linux-headers \
    && rm -rf /var/cache/apk/* /tmp/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Run tests
CMD ["vendor/bin/phpunit"]