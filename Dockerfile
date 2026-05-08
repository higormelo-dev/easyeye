FROM php:8.4-fpm-bookworm

ARG APP_USER=easyeye
ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        git \
        libcurl4-openssl-dev \
        libpq-dev \
        libzip-dev \
        libbz2-dev \
        libonig-dev \
        libicu-dev \
        libxml2-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxpm-dev \
        # Deps de renderização para wkhtmltopdf
        fontconfig \
        libxrender1 \
        libxext6 \
        xfonts-75dpi \
        xfonts-base \
        supervisor \
        netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# wkhtmltopdf com patches Qt — usa .deb oficial Bookworm (glibc nativo)
RUN curl -fsSL https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_amd64.deb \
    -o /tmp/wkhtmltox.deb \
    && apt-get update && apt-get install -y --no-install-recommends /tmp/wkhtmltox.deb \
    && rm -f /tmp/wkhtmltox.deb \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
        --with-xpm \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        bz2 \
        calendar \
        dom \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        simplexml \
        soap \
        xml \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g "${GID}" "${APP_USER}" \
    && useradd -u "${UID}" -g "${APP_USER}" -G www-data -m -d "/home/${APP_USER}" "${APP_USER}" \
    && mkdir -p "/home/${APP_USER}/.composer" \
    && mkdir -p /var/log/supervisor /var/run/supervisor /etc/supervisor/conf.d \
    && chown -R "${APP_USER}:${APP_USER}" "/home/${APP_USER}" /var/log/supervisor /var/run/supervisor

WORKDIR /var/www/html/app

COPY .docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini
COPY .docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

USER root
RUN chmod +x /usr/local/bin/entrypoint.sh

USER ${APP_USER}

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
