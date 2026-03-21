#!/bin/bash

cd /home/site/wwwroot

# Install dependencies
composer install --no-dev --optimize-autoloader

# Laravel setup
php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
chmod -R 775 storage bootstrap/cache

# Start Apache (for PHP)
apache2-foreground