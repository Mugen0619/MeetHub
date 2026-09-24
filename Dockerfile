FROM php:8.5-cli

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        unzip \
        git \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libonig-dev \
        libicu-dev \
        curl \
        gnupg \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql mbstring bcmath gd zip intl sockets \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y -qq --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# E2Eテスト(Pestのブラウザテスト)用のChromiumと、その実行に必要なOSライブラリ。
# コンテナを作り直しても消えないようイメージに含める(node_modulesはホストと共有のため、ブラウザ本体は別の場所に置く)。
# バージョンはpackage.jsonのplaywrightと揃えること(ずれた場合はテスト実行時にインストールを促すエラーになる)
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN npx -y playwright@1.63.0 install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/* /root/.npm

WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
