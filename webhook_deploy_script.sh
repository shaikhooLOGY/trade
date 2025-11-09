#!/bin/bash

# Webhook Deployment Script for GitHub to Hostinger
# This script handles deployment without branch conflicts

echo "🚀 GitHub Webhook Deployment Script"
echo "=================================="

# Navigate to web directory
cd public_html

# Backup current site (optional)
# cp -r . ../backup_$(date +%Y%m%d_%H%M%S)

# Remove old git to avoid conflicts
echo "🧹 Cleaning up old Git repository..."
rm -rf .git

# Clone fresh from GitHub
echo "📥 Cloning fresh from GitHub..."
git clone https://github.com/shaikhooLOGY/trade.git .

# Set proper permissions
echo "🔧 Setting permissions..."
chmod 644 *.php *.sql
chmod 755 admin/ logs/ includes/
chmod 755 deploy_to_hostinger.sh
chmod 755 fix_deployment_git.sh

# Create logs directory if it doesn't exist
if [ ! -d "logs" ]; then
    mkdir logs
    chmod 755 logs
fi

echo "✅ Deployment completed successfully!"
echo ""
echo "🔄 Database Migration Required:"
echo "Run these commands to create required tables:"
echo "mysql -u your_db_username -p your_db_name < create_user_otps_table.sql"
echo "mysql -u your_db_username -p your_db_name < create_user_profiles_table.sql"
echo ""
echo "🌐 Your registration workflow is now live!"