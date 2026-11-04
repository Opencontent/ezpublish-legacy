# Immagine di prova: CMS su PHP 8.3 (solo test locale del branch php8 del kernel)
FROM webdevops/php-nginx:8.3-alpine
RUN apk add --no-cache librdkafka \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS librdkafka-dev \
 && pecl install rdkafka \
 && docker-php-ext-enable rdkafka \
 && apk del .build
ADD php-nginx /opt/docker/
COPY php-nginx/php/zz-security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY opt/19-istances.sh opt/21-istances-check.sh /opt/docker/provision/entrypoint.d/
COPY opt/istances-generate.php /opt/docker/bin/
COPY opt/lib /opt/docker/lib
COPY cron-root /var/spool/cron/crontabs/root
WORKDIR /var/www/html
