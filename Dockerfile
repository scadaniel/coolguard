FROM webdevops/php-apache:8.2

ENV WEB_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

RUN chown -R application:application /var/www/html