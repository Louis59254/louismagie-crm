# CRM LouisMagie — image PHP + Apache (pour Coolify / Docker)
FROM php:8.2-apache

# Sert crm.html comme page d'accueil + l'API PHP
COPY crm.html /var/www/html/index.html
COPY api.php  /var/www/html/api.php

# Dossiers de données + PDF (persistés via volume Coolify), accessibles en écriture
RUN mkdir -p /var/www/html/data /var/www/html/pdf \
    && chown -R www-data:www-data /var/www/html \
    && a2enmod rewrite headers

EXPOSE 80
