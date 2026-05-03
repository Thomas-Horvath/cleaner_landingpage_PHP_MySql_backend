FROM php:8.4.20-apache-bookworm

# Apache rewrite, .htaccess támogatás és a szükséges PHP bővítmények fejlesztői környezethez.
RUN a2enmod rewrite \
    && sed -ri 's!/var/www/!/var/www/html!g' /etc/apache2/apache2.conf \
    && sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Az Apache dokumentumgyökere marad a mountolt /var/www/html mappa.
WORKDIR /var/www/html
