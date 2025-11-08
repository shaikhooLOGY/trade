# Database Setup Guide for Live Server

## CRITICAL: Live Server Database Connection Fix

### Problem
Live server was using local database credentials (`root/newpass/live_tc_local`) instead of production credentials.

### Solution
Updated configuration to use production database credentials with proper environment variables.

## Production Database Credentials

**Database Name**: `u613260542_tcmtm`  
**Database Host**: `localhost` (Hostinger shared hosting)  
**Database User**: `u613260542_tcmtm`  
**Database Password**: `TC@Shaikhoology25`

## Database Structure Status

✅ **VERIFIED**: Production database contains all required tables and data

**Tables Found**:
- `users` - User accounts and profiles
- `trades` - Trading records
- `leaderboard` - User rankings
- `mtm_models` - Trading model programs
- `mtm_enrollments` - User enrollments
- `mtm_tasks` - Model tasks
- `mtm_task_progress` - Task completion tracking
- `audit_logs` - System audit trail
- `notifications` - User notifications
- And 10+ additional supporting tables

## Deployment Steps

### Step 1: Replace config.php
1. Copy `config.production.php` to `config.php` on live server
2. Ensure proper file permissions (644)

### Step 2: Configure Environment
1. Copy `includes/.env.production` to `includes/.env` on live server
2. Set proper file permissions (600 for security)

### Step 3: Verify Database Connection
1. Run `db_test_production.php` to test connection
2. Check `logs/database_connect.log` for success confirmation

### Step 4: Test Application
1. Visit site homepage - should load without errors
2. Try user login - should work
3. Check trade dashboard - should display data

## Database Migration (If Needed)

If database needs to be recreated:

```sql
-- Import the production database structure
mysql -u u613260542_tcmtm -p u613260542_tcmtm < u613260542_tcmtm.sql
```

## Connection Test Script

Run `db_test_production.php` to verify the connection is working.

## Security Notes

- `.env` file should have 600 permissions (read/write owner only)
- `config.php` should have 644 permissions
- Error logging is enabled but error display is disabled in production
- Session security headers are enabled
- Database connection errors are logged to `logs/database_errors.log`

## Monitoring

- Database connection logs: `logs/database_connect.log`
- Error logs: `logs/php_errors.log`
- Database errors: `logs/database_errors.log`

## Troubleshooting

If connection fails:
1. Verify database credentials in `.env`
2. Check MySQL service is running on Hostinger
3. Ensure user has proper permissions
4. Test connection manually with database tool

## Quick Fix Commands

```bash
# Copy production config
cp config.production.php config.php

# Copy environment file
cp includes/.env.production includes/.env

# Set permissions
chmod 600 includes/.env
chmod 644 config.php

# Test connection
php db_test_production.php
```

This will immediately fix the database connection issue and restore functionality to the live server.