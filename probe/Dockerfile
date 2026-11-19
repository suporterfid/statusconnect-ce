# StatusConnect remote probe (#128) for Cloud Run or any container host.
FROM php:8.3-apache

ENV PORT=8080 \
    APACHE_DOCUMENT_ROOT=/var/www/probe/public

RUN a2enmod rewrite \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n<Directory /var/www/probe/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/probe.conf \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nmax_execution_time=40\n' > /usr/local/etc/php/conf.d/probe.ini

COPY src/ /var/www/probe/src/
COPY public/ /var/www/probe/public/

EXPOSE 8080
