FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.4-zts-bookworm AS compile

ENV PHP_SWOOLE_VERSION="v6.1.3"
ENV PHP_PARALLEL_VERSION="v1.2.8"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update && apt-get install -y \
    make \
    automake \
    autoconf \
    gcc \
    g++ \
    git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
    sockets

## Swoole extension
FROM compile AS swoole
RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-swoole-thread --enable-sockets && \
  make && make install

## ext-parallel Extension (for ZTS builds)
FROM compile AS parallel

RUN git clone --depth 1 --branch $PHP_PARALLEL_VERSION https://github.com/krakjoe/parallel.git && \
    cd parallel && \
    phpize && \
    ./configure && \
    make && \
    make install && \
    echo "extension=parallel.so" > /usr/local/etc/php/conf.d/parallel.ini

FROM compile AS final

LABEL maintainer="team@appwrite.io"

ARG DEBUG=false
ENV DEBUG=$DEBUG

WORKDIR /usr/src/code

RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini
RUN echo "memory_limit=512M" >> $PHP_INI_DIR/php.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=swoole /usr/local/lib/php/extensions/no-debug-zts-20240924/swoole.so /usr/local/lib/php/extensions/no-debug-zts-20240924/
COPY --from=parallel /usr/local/lib/php/extensions/no-debug-zts-20240924/parallel.so /usr/local/lib/php/extensions/no-debug-zts-20240924/
COPY --from=parallel /usr/local/etc/php/conf.d/parallel.ini /usr/local/etc/php/conf.d/parallel.ini

COPY . /usr/src/code

CMD [ "tail", "-f", "/dev/null" ]
