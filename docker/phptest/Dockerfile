FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip curl bash make python3 py3-pip $PHPIZE_DEPS \
    && pip3 install --no-cache-dir --break-system-packages semgrep==1.157.0 \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS \
    && addgroup -S phptest \
    && adduser -S phptest -G phptest

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace
RUN chown phptest:phptest /workspace

USER phptest

ENTRYPOINT ["bash", "-c"]
CMD ["composer install --no-interaction --prefer-dist --no-scripts && make test"]
