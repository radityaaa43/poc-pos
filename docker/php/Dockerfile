# Local development image: PHP 8.4 CLI + extensions required by composer.lock
FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql gd zip intl bcmath exif pcntl opcache

# opcache for CLI matters a lot here: source is bind-mounted from Windows (slow fs)
RUN { \
      echo "opcache.enable_cli=1"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=2"; \
      echo "memory_limit=512M"; \
      echo "upload_max_filesize=20M"; \
      echo "post_max_size=25M"; \
    } > /usr/local/etc/php/conf.d/zz-dev.ini

WORKDIR /var/www/html
