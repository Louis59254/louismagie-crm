# CRM LouisMagie — image PHP + Apache (pour Coolify / Docker)
FROM php:8.2-apache

# GD : le serveur prépare lui-même les variantes de logo lisibles sur fond
# sombre pour les pages publiques (brief, signature), sans dépendre du CRM.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Sert crm.html comme page d'accueil + l'API PHP
COPY crm.html /var/www/html/index.html
COPY api.php  /var/www/html/api.php
COPY robots.txt /var/www/html/robots.txt

# Dossiers de données + PDF (persistés via volume Coolify), accessibles en écriture
RUN mkdir -p /var/www/html/data /var/www/html/pdf \
    && chown -R www-data:www-data /var/www/html \
    && a2enmod rewrite headers

# ── Sécurité : les données et les PDF ne doivent JAMAIS être servis en direct.
# Ils ne sont accessibles que par api.php, après authentification.
RUN printf '%s\n' \
    '<Directory /var/www/html/data>' \
    '  Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/pdf>' \
    '  Require all denied' \
    '</Directory>' \
    '<FilesMatch "\.(json|log|bak|sqlite|db)$">' \
    '  Require all denied' \
    '</FilesMatch>' \
    '<Files "robots.txt">' \
    '  Require all granted' \
    '</Files>' \
    '# Le CRM doit toujours etre revalide : sans cela le navigateur garde' \
    '# une version perimee apres un deploiement.' \
    '<FilesMatch "\.html$">' \
    '  Header always set Cache-Control "no-cache, must-revalidate"' \
    '</FilesMatch>' \
    '# Rien de ce CRM ne doit finir dans un moteur de recherche' \
    'Header always set X-Robots-Tag "noindex, nofollow, noarchive, nosnippet"' \
    'Header always set X-Content-Type-Options "nosniff"' \
    'Header always set X-Frame-Options "SAMEORIGIN"' \
    'Header always set Referrer-Policy "no-referrer"' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    > /etc/apache2/conf-available/crm-secure.conf \
    && a2enconf crm-secure

EXPOSE 80
