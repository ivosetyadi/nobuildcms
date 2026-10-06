# NoBuildCMS — runnable PHP image (Render / Railway / Fly / any Docker host)
FROM php:8.3-cli

# Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && docker-php-ext-install opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

# App source
COPY . .

# Hosts set $PORT; default to 8000 locally.
ENV PORT=8000
EXPOSE 8000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public public/index.php"]
