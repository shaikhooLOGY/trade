#!/bin/bash

# Live Server Database Connection Fix - Deployment Script
# Run this on the live server to fix the database connection

echo "🚀 LIVE SERVER DATABASE CONNECTION FIX"
echo "======================================"

echo "Step 1: Backing up current config..."
if [ -f "config.php" ]; then
    cp config.php config.php.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ Current config.php backed up"
fi

echo "Step 2: Deploying production configuration..."
cp config.production.php config.php
chmod 644 config.php
echo "✅ Production config deployed"

echo "Step 3: Setting up environment file..."
if [ -f "includes/.env" ]; then
    cp includes/.env includes/.env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ Current .env backed up"
fi

cp includes/.env.production includes/.env
chmod 600 includes/.env
echo "✅ Production environment configured"

echo "Step 4: Creating logs directory..."
mkdir -p logs
chmod 755 logs
echo "✅ Logs directory created"

echo "Step 5: Testing database connection..."
php db_test_production.php

echo "Step 6: Setting proper permissions..."
find . -name "*.php" -exec chmod 644 {} \;
find . -name ".env*" -exec chmod 600 {} \;
chmod 755 -R logs/
echo "✅ File permissions set"

echo ""
echo "🎉 DEPLOYMENT COMPLETE!"
echo "======================="
echo "The live server should now connect to the production database."
echo ""
echo "Verification steps:"
echo "1. Visit https://tradersclub.shaikhoology.com"
echo "2. Check if homepage loads without errors"
echo "3. Try user login functionality"
echo "4. Verify trade data displays correctly"
echo ""
echo "If issues persist:"
echo "- Check logs/database_errors.log"
echo "- Check logs/php_errors.log"
echo "- Verify database credentials in includes/.env"
echo ""