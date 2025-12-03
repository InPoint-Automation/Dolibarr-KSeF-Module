#!/bin/bash

set -e

# Check directory
if [ ! -f "core/modules/modKSEF.class.php" ]; then
    echo "Error: Must run from module root directory"
    exit 1
fi

echo "Starting KSeF module build..."

# Download composer.phar if not present
if [ ! -f "composer.phar" ]; then
    echo "-> Downloading composer.phar..."
    curl -L -o composer.phar https://getcomposer.org/composer.phar
    chmod +x composer.phar
fi

# Download php-scoper.phar if not present
if [ ! -f "php-scoper.phar" ]; then
    echo "-> Downloading php-scoper.phar..."
    curl -L -o php-scoper.phar https://github.com/humbug/php-scoper/releases/download/0.18.18/php-scoper.phar
    chmod +x php-scoper.phar
fi

# Install dependencies
echo "-> Installing production dependencies only..."
./composer.phar install --no-dev --optimize-autoloader

# Scope
echo "-> Scoping dependencies..."
php build/scope-dependencies.php

# Build zip
echo "-> Creating release archive..."
php build/buildzip.php

echo "Build complete."
