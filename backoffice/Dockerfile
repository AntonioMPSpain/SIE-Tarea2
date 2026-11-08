FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite-libs sqlite-dev curl-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql

WORKDIR /app
COPY . /app

RUN mkdir -p /app/storage/database && chmod -R 775 /app/storage

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
