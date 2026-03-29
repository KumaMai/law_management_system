FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    fontconfig \
    fonts-thai-tlwg \
    libxrender1 \
    libxext6 \
    libx11-6 \
    libjpeg62-turbo \
    libpng16-16t64 \
    xfonts-75dpi \
    xfonts-base \
    wget \
    && wget -q https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_amd64.deb -O /tmp/wkhtmltox.deb \
    && dpkg -i /tmp/wkhtmltox.deb || apt-get install -f -y \
    && rm /tmp/wkhtmltox.deb \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY init-hash.sh /usr/local/bin/init-hash.sh
RUN chmod +x /usr/local/bin/init-hash.sh

WORKDIR /var/www/html

CMD sh -c "(/usr/local/bin/init-hash.sh &) && php-fpm"
