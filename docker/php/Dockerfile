FROM php:8.2-fpm

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY init-hash.sh /usr/local/bin/init-hash.sh
RUN chmod +x /usr/local/bin/init-hash.sh

WORKDIR /var/www/html

CMD sh -c "(/usr/local/bin/init-hash.sh &) && php-fpm"
