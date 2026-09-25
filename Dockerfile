FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev default-libmysqlclient-dev \
    && docker-php-ext-install -j$(nproc) pdo pdo_sqlite pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Faster PHP for production
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=4000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.revalidate_freq=0'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/nova-perf.ini

ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '%s\n' \
      '<Directory /app/public>' \
      '    Options FollowSymLinks' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      > /etc/apache2/conf-available/nova-dir.conf \
    && a2enconf nova-dir

WORKDIR /app
COPY . .

RUN cp app/config/env.example.php app/config/env.php \
    && mkdir -p storage \
    && chmod -R 777 storage \
    && printf '%s\n' \
      'RewriteEngine On' \
      'RewriteCond %{REQUEST_FILENAME} -f [OR]' \
      'RewriteCond %{REQUEST_FILENAME} -d' \
      'RewriteRule ^ - [L]' \
      'RewriteRule ^ router.php [QSA,L]' \
      > public/.htaccess

ENV APP_ENV=production
ENV APP_DEBUG=0
ENV PORT=8080

EXPOSE 8080

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-8080}>/\" /etc/apache2/sites-available/*.conf && apache2-foreground"]
