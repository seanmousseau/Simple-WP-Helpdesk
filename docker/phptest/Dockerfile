FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip curl bash \
    && addgroup -S phptest \
    && adduser -S phptest -G phptest

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace
RUN chown phptest:phptest /workspace

USER phptest

ENTRYPOINT ["bash", "-c"]
CMD ["composer install --no-interaction && vendor/bin/phpunit"]
