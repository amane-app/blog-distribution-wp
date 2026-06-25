FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip subversion libzip-dev default-mysql-client \
    && docker-php-ext-install mysqli pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
