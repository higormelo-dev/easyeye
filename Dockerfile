FROM php:8.4-fpm

# set your user name, ex: user=higor
ARG user=medicare
ARG uid=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    apt-utils \
	build-essential \
	git \
	curl \
	libcurl4 \
	libcurl4-openssl-dev \
    libpq-dev \
	zlib1g-dev \
	libzip-dev \
	zip \
	libbz2-dev \
	locales \
	libmcrypt-dev \
	libicu-dev \
	libonig-dev \
	libxml2-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql pdo_mysql
RUN docker-php-ext-install mbstring zip exif pcntl bcmath bz2 intl xml

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

# Install redis
RUN pecl install -o -f redis \
    &&  rm -rf /tmp/pear \
    &&  docker-php-ext-enable redis

# Set working directory
WORKDIR /var/www/html/app

# Copy custom configurations PHP
COPY .docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

USER $user