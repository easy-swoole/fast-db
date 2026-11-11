# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.4.27-cli-bookworm
FROM ${PHP_IMAGE}
ARG SWOOLE_VERSION=6.2.4

COPY --from=composer:2.11.1 /usr/bin/composer /usr/local/bin/composer

# 原生 mysqli 用于测试观察连接；业务查询仍使用组件自己的协程驱动。
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS ca-certificates curl git unzip libssl-dev zlib1g-dev; \
    docker-php-ext-install -j"$(nproc)" sockets mysqli; \
    mkdir -p /usr/src/swoole; \
    curl --fail --show-error --location --retry 3 \
        "https://github.com/swoole/swoole-src/archive/refs/tags/v${SWOOLE_VERSION}.tar.gz" \
        | tar -xz --strip-components=1 -C /usr/src/swoole; \
    cd /usr/src/swoole; \
    phpize; \
    set -- --with-openssl-dir=/usr --enable-sockets --disable-brotli; \
    case "$SWOOLE_VERSION" in 5.*) set -- "$@" --enable-openssl ;; esac; \
    ./configure "$@"; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable swoole; \
    php -r 'foreach (["swoole", "mysqli", "pdo_sqlite", "sockets", "openssl", "zlib", "dom", "xml", "xmlwriter", "mbstring"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } }'; \
    test "$(php -r 'echo phpversion("swoole");')" = "$SWOOLE_VERSION"; \
    rm -rf /usr/src/swoole /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /workspace
CMD ["bash", "/source/tests/docker/run-tests.sh"]
