FROM docker.1panel.live/library/php:8.3-fpm

# apt 换腾讯云源（Debian bookworm 为 deb822 格式）
RUN sed -i 's#deb.debian.org#mirrors.tencent.com#g; s#security.debian.org#mirrors.tencent.com#g' \
        /etc/apt/sources.list.d/debian.sources || true

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev libpq-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=docker.1panel.live/library/composer:2 /usr/bin/composer /usr/bin/composer
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
WORKDIR /var/www
