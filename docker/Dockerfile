# Magento 2.4.8 runtime: PHP 8.3 + Apache, the extensions Magento requires, Composer 2.
# Magento itself is not baked in: entrypoint.sh installs it into the magento_html volume on first boot.
FROM php:8.3-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ctype, curl, dom, mbstring, simplexml and sodium ship with the base image; the check below fails
# the build if any extension from HU §6.11 / magento/magento2 2.4.8 composer.json is missing.
RUN apt-get update \
  && apt-get install -y --no-install-recommends git unzip \
  && install-php-extensions bcmath ftp gd intl pdo_mysql soap sockets xsl zip opcache \
  && php -r '$m = ["bcmath","ctype","curl","dom","ftp","gd","intl","mbstring","pdo_mysql","simplexml","soap","sockets","sodium","xsl","zip","Zend OPcache","iconv","openssl"]; $miss = array_filter($m, fn ($e) => !extension_loaded($e)); if ($miss) { fwrite(STDERR, "missing: " . implode(",", $miss) . "\n"); exit(1); }' \
  && a2enmod rewrite headers expires \
  && rm -rf /var/lib/apt/lists/*

# Magento 2.4.8 has no index.php at the project root: the web root must be pub/.
RUN sed -ri 's!DocumentRoot /var/www/html$!DocumentRoot /var/www/html/pub!' /etc/apache2/sites-available/000-default.conf \
  && grep -q 'DocumentRoot /var/www/html/pub' /etc/apache2/sites-available/000-default.conf \
  && printf '<Directory /var/www/html/pub>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/magento.conf \
  && a2enconf magento

# php.ini: CLI without memory limit (create-project, di:compile); Apache capped at 2G via php_admin_value.
COPY php.ini /usr/local/etc/php/conf.d/zz-magento.ini
RUN printf 'php_admin_value memory_limit 2G\nphp_admin_value max_execution_time 1800\n' \
       > /etc/apache2/conf-available/magento-php.conf \
  && a2enconf magento-php

ENV COMPOSER_HOME=/var/www/.composer \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_PROCESS_TIMEOUT=1800 \
    COMPOSER_NO_INTERACTION=1

# www-data runs Composer and bin/magento; its HOME (/var/www) holds the Composer cache.
RUN mkdir -p /var/www/.composer && chown -R www-data:www-data /var/www

COPY entrypoint.sh /usr/local/bin/magento-entrypoint
COPY setup-store.sh /usr/local/bin/setup-store
COPY setup-product.php /usr/local/share/magento-docker/setup-product.php
RUN chmod 0755 /usr/local/bin/magento-entrypoint /usr/local/bin/setup-store

WORKDIR /var/www/html
CMD ["magento-entrypoint"]
