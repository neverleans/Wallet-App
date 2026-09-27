#!/usr/bin/env bash

echo "Running composer"

composer install --optimize-autoloader --no-dev --working-dir=/var/www/html || { echo "Composer failed"; exit 1; }

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Running migrations..."
php artisan migrate --force

# Mudar permissões, se necessário
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache