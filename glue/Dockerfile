FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
EXPOSE 8080
# ponytail: built-in server with PHP_CLI_SERVER_WORKERS handles a single site at low volume; swap for FrankenPHP or nginx+php-fpm in production
CMD ["php", "-q", "-S", "0.0.0.0:8080", "public/index.php"]
