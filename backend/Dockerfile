FROM php:8.3-cli-alpine

ARG REDIS_EXT_VERSION=6.3.0
ARG REDIS_EXT_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

WORKDIR /app

# Change Alpine mirror to Aliyun
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories

# Install system dependencies and PHP extensions
# Added linux-headers which is required for compiling some PHP extensions like sockets/redis on Alpine
# Install from a pinned tarball because package-name resolution requires the
# PECL REST XML endpoint, which may be unavailable through mirrors/proxies.
RUN apk add --no-cache \
    bash \
    ca-certificates \
    curl \
    git \
    icu-dev \
    zlib-dev \
    libzip-dev \
    oniguruma-dev \
    curl-dev \
    linux-headers \
    $PHPIZE_DEPS \
  && update-ca-certificates \
  && docker-php-ext-install -j$(nproc) pdo_mysql sockets intl zip curl pcntl mbstring \
  && curl -fsSL "https://pecl.php.net/get/redis-${REDIS_EXT_VERSION}.tgz" -o /tmp/redis.tgz \
  && echo "${REDIS_EXT_SHA256}  /tmp/redis.tgz" | sha256sum -c - \
  && pecl install /tmp/redis.tgz \
  && docker-php-ext-enable redis \
  && rm -f /tmp/redis.tgz \
  && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Configure Composer mirror
RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY . ./

RUN mkdir -p runtime/logs

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=production

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "start.php", "start"]
