<?php
// Database Configuration Fix for Hostinger
// This script helps identify and fix database connection issues

echo "🔧 Hostinger Database Configuration Fix\n";
echo "=======================================\n\n";

echo "📋 Hostinger Database Info Required:\n";
echo "-----------------------------------\n";
echo "1. Database Host (usually: mysql.hostinger.com)\n";
echo "2. Database Name (like: u613260542_tradersclub)\n";
echo "3. Database Username (like: u613260542)\n";
echo "4. Database Password (your database password)\n\n";

echo "🔍 Checking current database configuration:\n";
echo "-----------------------------------------\n";

// Check if config.php exists
if (file_exists('config.php')) {
    echo "✅ config.php found\n";
    $config_content = file_get_contents('config.php');
    
    if (strpos($config_content, 'localhost') !== false || strpos($config_content, '127.0.0.1') !== false) {
        echo "⚠️  Using localhost - needs Hostinger database host\n";
    }
    if (strpos($config_content, 'root') !== false) {
        echo "⚠️  Using 'root' user - needs Hostinger database username\n";
    }
} else {
    echo "❌ config.php not found\n";
}

echo "\n🛠️  Solution Steps:\n";
echo "------------------\n";
echo "1. Get your Hostinger database credentials from cPanel\n";
echo "2. Update config.php with correct values:\n\n";

echo "<?php\n";
echo "// Hostinger Database Configuration\n";
echo "define('DB_HOST', 'mysql.hostinger.com'); // Your database host\n";
echo "define('DB_USER', 'u613260542'); // Your database username\n";
echo "define('DB_PASS', 'your_password_here'); // Your database password\n";
echo "define('DB_NAME', 'u613260542_tradersclub'); // Your database name\n\n";

echo "// Database connection\n";
echo "\$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);\n";
echo "if (\$mysqli->connect_error) {\n";
echo "    die('Database connection failed: ' . \$mysqli->connect_error);\n";
echo "}\n\n";

echo "// Rest of your config.php code...\n";
echo "?>\n\n";

echo "3. Or create .env file in your web directory:\n\n";
echo "DB_HOST=mysql.hostinger.com\n";
echo "DB_USER=u613260542\n";
echo "DB_PASS=your_password_here\n";
echo "DB_NAME=u613260542_tradersclub\n";
echo "APP_ENV=production\n\n";

echo "4. Update your config.php to use .env values\n\n";

echo "✅ Once database is configured:\n";
echo "   - Your registration system will work\n";
echo "   - Run the SQL migration files:\n";
echo "     mysql -u your_db_username -p your_db_name < create_user_otps_table.sql\n";
echo "     mysql -u your_db_username -p your_db_name < create_user_profiles_table.sql\n\n";

echo "🎯 Your website should then be accessible at:\n";
echo "   https://tradersclub.shaikhoology.com\n\n";

echo "📞 Need help? Check your Hostinger cPanel for database details.\n";
?>