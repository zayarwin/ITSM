#!/bin/sh
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until php -r "new PDO('mysql:host=db;port=3306;dbname=itsm', 'itsm', 'itsm_secret');" 2>/dev/null; do
  sleep 2
done
echo "MySQL is ready."

# Run database migrations
php artisan migrate --force

# Start the Laravel development server
exec php artisan serve --host=0.0.0.0 --port=8000
