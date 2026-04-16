FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize


FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
COPY tailwind.config.js* ./
COPY postcss.config.js* ./

RUN npm run build


FROM php:8.3-cli-alpine AS app

WORKDIR /app

RUN apk add --no-cache \
        fcgi \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN test -f /app/public/build/manifest.json \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R nobody:nobody storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache \
    && ln -sfn /app/storage/app/public /app/public/storage

ENV APP_ENV=production
ENV PORT=8080

EXPOSE 8080

CMD ["sh", "-c", "php artisan optimize:clear && php artisan serve --host=0.0.0.0 --port=8080"]