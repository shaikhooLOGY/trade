#!/bin/bash

# Fix Git Deployment Issues for Hostinger
# This script resolves divergent branch conflicts

echo "🔧 Fixing Git deployment issues..."
echo "=================================="

# Navigate to your Hostinger web directory (usually public_html)
echo "Navigate to your website directory:"
echo "cd public_html (or your domain folder)"

echo ""
echo "1. Configure Git to handle divergent branches:"
echo "   git config pull.rebase false"
echo "   git config pull.ff only"

echo ""
echo "2. If conflicts persist, use one of these solutions:"
echo ""
echo "Option A - Force Pull (Recommended for this case):"
echo "   git fetch origin main"
echo "   git reset --hard origin/main"
echo ""
echo "Option B - Clean Deploy (Nuclear Option):"
echo "   rm -rf .git"
echo "   git clone https://github.com/shaikhooLOGY/trade.git ."
echo ""
echo "Option C - Merge Strategy:"
echo "   git pull origin main --no-rebase"
echo ""

echo "3. After fixing Git, run database migrations:"
echo "   mysql -u your_db_username -p your_db_name < create_user_otps_table.sql"
echo "   mysql -u your_db_username -p your_db_name < create_user_profiles_table.sql"
echo ""

echo "4. Set proper permissions:"
echo "   chmod 644 *.php"
echo "   chmod 644 *.sql"
echo "   chmod 755 admin/"
echo ""

echo "5. Update webhook URL in GitHub to include force pull:"
echo "   If using webhook, set the deployment script to use:"
echo "   git fetch origin main"
echo "   git reset --hard origin/main"

echo ""
echo "🚀 RECOMMENDED SOLUTION FOR THIS ERROR:"
echo "======================================="
echo "Since you have a clean deployment, use Option B:"
echo ""
echo "ssh username@your-domain.com"
echo "cd public_html"
echo "rm -rf .git"
echo "git clone https://github.com/shaikhooLOGY/trade.git ."
echo ""
echo "This will create a fresh copy of your registration workflow!"