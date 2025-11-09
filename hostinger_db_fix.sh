#!/bin/bash

# Hostinger Database Configuration Fix Script
# Run this script on your Hostinger server to fix the database issue

echo "🔧 Hostinger Database Configuration Fix"
echo "======================================="
echo ""

echo "📋 STEP 1: Get your Hostinger database credentials"
echo "------------------------------------------------"
echo "1. Login to your Hostinger cPanel"
echo "2. Go to 'Databases' section"
echo "3. Note down:"
echo "   - Database Name (e.g., u613260542_tradersclub)"
echo "   - Database Username (e.g., u613260542)"
echo "   - Database Password (your database password)"
echo "   - Database Host (usually mysql.hostinger.com)"
echo ""

echo "📝 STEP 2: Create proper .env file"
echo "--------------------------------"

# Create the .env file
cat > .env << EOF
# Hostinger Production Environment Configuration
APP_ENV=production

# Database Configuration
DB_HOST=mysql.hostinger.com
DB_USER=u613260542_tradersclub
DB_PASS=YOUR_ACTUAL_PASSWORD_HERE
DB_NAME=u613260542_tradersclub

# SMTP Email Settings
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=your-email@tradersclub.shaikhoology.com
SMTP_PASS=your-email-password
MAIL_FROM=your-email@tradersclub.shaikhoology.com
MAIL_FROM_NAME=Shaikhoology Trading Club

# Site URL
SITE_URL=https://tradersclub.shaikhoology.com
BASE_URL=https://tradersclub.shaikhoology.com
EOF

echo "✅ Created .env file template"
echo ""

echo "🔑 STEP 3: Edit .env file with your credentials"
echo "---------------------------------------------"
echo "Please edit the .env file and replace:"
echo "  - DB_USER with your actual database username"
echo "  - DB_PASS with your actual database password"
echo "  - SMTP_USER with your actual email"
echo "  - SMTP_PASS with your actual email password"
echo ""

echo "Edit the file: nano .env"
echo ""

echo "🔧 STEP 4: Set file permissions"
echo "------------------------------"
chmod 644 .env
chmod 644 config.php
chmod 755 admin/
echo "✅ Set proper file permissions"
echo ""

echo "🗄️  STEP 5: Create database tables"
echo "--------------------------------"
echo "Run these commands in your database:"
echo "  mysql -u your_db_username -p your_db_name < create_user_otps_table.sql"
echo "  mysql -u your_db_username -p your_db_name < create_user_profiles_table.sql"
echo ""

echo "🚀 STEP 6: Test the website"
echo "--------------------------"
echo "Visit: https://tradersclub.shaikhoology.com"
echo "The registration system should now work!"
echo ""

echo "✅ Database configuration fix completed!"
echo "📞 If you need help with database credentials, check your Hostinger cPanel."