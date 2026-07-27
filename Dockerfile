# Laravel開発用のPHPコンテナです。
# 現在は仕様書と参考画像のみですが、Laravelのファイルを追加した後も
# 同じコンテナ設定を使用できるようにしています。
FROM php:8.4-apache

# Laravelで一般的に使用するPHP拡張をインストールします。
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Composer本体を公式イメージからコピーします。
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Laravelの公開ディレクトリをApacheのドキュメントルートにします。
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY . .

# 依存関係ファイルがある場合は、ビルド時にComposer依存関係を解決します。
# 現在のフォルダにはまだLaravel本体がないため、この処理はスキップされます。
RUN if [ -f composer.json ]; then \
        composer install --no-interaction --prefer-dist; \
    fi

# Laravelが書き込みを必要とするディレクトリを準備します。
RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

