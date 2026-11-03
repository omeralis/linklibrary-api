FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libonig-dev libxml2-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_pgsql intl mbstring gd bcmath zip \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSLo /tmp/composer-setup.php https://getcomposer.org/installer \
    && curl -fsSLo /tmp/composer.sig https://composer.github.io/installer.sig \
    && php -r 'if (hash_file("sha384", "/tmp/composer-setup.php") !== trim(file_get_contents("/tmp/composer.sig"))) exit(1);' \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet \
    && rm /tmp/composer-setup.php /tmp/composer.sig
WORKDIR /app
