FROM php:8.2-cli

ENV ITCHCLAIM_DOCKER TRUE

WORKDIR /app

RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json .
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --optimize

VOLUME [ "/data" ]

ENTRYPOINT [ "php", "claim-cron.php" ]