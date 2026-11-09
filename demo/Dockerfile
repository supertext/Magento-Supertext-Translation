# Supertext Magento demo: Mage-OS 3.6 (Magento Open Source 2.4.9 compatible) + this module,
# with store views for German (CH), French (CH) and Italian (CH).
# Build context is the repository root:
#   docker build -f demo/Dockerfile -t supertext-magento-demo .
# Everything persistent is in MySQL (DATABASE_URL), including app/etc/env.php; the search
# index lives in Elasticsearch and is rebuilt on every start. See docs/DEVELOPER.md -> Demo.

FROM php:8.3-apache AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libxslt1-dev \
      default-mysql-client git unzip curl ca-certificates \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" bcmath gd intl pdo_mysql soap sockets xsl zip opcache ftp \
 && a2enmod rewrite headers expires deflate \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Mage-OS, downloaded at build time (no Adobe Marketplace keys needed) ---------------
FROM base AS magento
ARG MAGEOS_VERSION=3.6.0
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
RUN composer create-project --no-interaction --no-progress --no-dev \
      --repository-url=https://repo.mage-os.org/ "mage-os/project-community-edition:${MAGEOS_VERSION}" /var/www/magento \
 && rm -rf /root/.composer/cache /root/.cache/composer

# --- Demo image -----------------------------------------------------------------------
FROM base
ENV MAGENTO_ROOT=/var/www/magento
COPY --from=magento /var/www/magento /var/www/magento

# This module, as it would be installed by hand (app/code).
COPY . /tmp/module
RUN mkdir -p /var/www/magento/app/code/Supertext/Translation \
 && cd /tmp/module && tar cf - --exclude=./.git --exclude=./demo --exclude=./docs --exclude=./tests --exclude=./Test --exclude=./.github . \
    | tar xf - -C /var/www/magento/app/code/Supertext/Translation \
 && rm -rf /tmp/module \
 && cd /var/www/magento \
 && php bin/magento module:enable --all \
 && php bin/magento module:disable Magento_TwoFactorAuth \
 && echo ok > pub/health

COPY demo/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/php.ini /usr/local/etc/php/conf.d/zz-magento.ini
COPY demo/setup.php demo/state.php demo/sample-content.json /opt/demo/
COPY demo/entrypoint.sh /usr/local/bin/supertext-demo-entrypoint
RUN chmod +x /usr/local/bin/supertext-demo-entrypoint && chown -R www-data:www-data /var/www/magento

WORKDIR /var/www/magento
ENTRYPOINT ["supertext-demo-entrypoint"]
CMD ["apache2-foreground"]
