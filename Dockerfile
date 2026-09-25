FROM php:8.3-cli-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
    && apk add --no-cache sqlite-libs \
    && docker-php-ext-install pdo pdo_sqlite \
    && apk del .build-deps

WORKDIR /app

COPY . .

RUN cp app/config/env.example.php app/config/env.php \
    && mkdir -p storage \
    && chmod -R 777 storage

ENV APP_ENV=production
ENV APP_DEBUG=0
ENV DB_DRIVER=sqlite
ENV PORT=8080

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public public/router.php"]
