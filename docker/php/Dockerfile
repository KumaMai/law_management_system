FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    wkhtmltopdf \
    fontconfig \
    fonts-thai-tlwg \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY init-hash.sh /usr/local/bin/init-hash.sh
RUN chmod +x /usr/local/bin/init-hash.sh

WORKDIR /var/www/html

CMD sh -c "(/usr/local/bin/init-hash.sh &) && php-fpm"
