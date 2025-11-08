# PASSWORD RESET TABLE FIX - IMPLEMENTATION GUIDE

## URGENT: Fix Missing password_resets Table

**ERROR:** `Table 'live_tc_local.password_resets' doesn't exist`

**PRIORITY:** IMMEDIATE - Users cannot reset passwords

---

## STEP 1: EXECUTE SQL SCRIPT

### Method 1: MySQL Command Line
```bash
# Navigate to project directory
cd /Users/shaikhoology/Desktop/LIVE TC

# Connect to MySQL
mysql -u username -p password live_tc_local

# Execute the SQL script
SOURCE create_password_resets_table.sql;
```

### Method 2: MySQL Workbench/phpMyAdmin
1. Open MySQL Workbench or phpMyAdmin
2. Connect to your database: `live_tc_local`
3. Open the file: `create_password_resets_table.sql`
4. Execute the script

### Method 3: PHP Script (Quick Fix)
```php
<?php
require_once 'config.php';

// Execute the CREATE TABLE statement
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Read and execute SQL file
$sql = file_get_contents('create_password_resets_table.sql');

if ($mysqli->multi_query($sql)) {
    echo "✅ Table created successfully!\n";
    do {
        if ($result = $mysqli->store_result()) {
            while ($row = $result->fetch_assoc()) {
                print_r($row);
            }
            $result->free();
        }
    } while ($mysqli->next_result());
} else {
    echo "❌ Error: " . $mysqli->error . "\n";
}
$mysqli->close();
?>
```

Save as `quick_table_create.php` and run: `php quick_table_create.php`

---

## STEP 2: VERIFY TABLE CREATION

### Check Table Exists
```sql
-- Check if table exists
SHOW TABLES LIKE 'password_resets';

-- View table structure
DESCRIBE password_resets;

-- View table details
SHOW CREATE TABLE password_resets;
```

**Expected Result:**
- Table `password_resets` should be listed
- Structure should match: id, user_id, token, token_hash, expires_at, used_at, created_at
- Indexes should be: PRIMARY, UNIQUE(token_hash), KEY(user_id), KEY(expires_at)

### Test Basic Operations
```sql
-- Test insert
INSERT INTO password_resets (user_id, token, token_hash, expires_at) 
VALUES (1, 'test_token', SHA2('test_token', 256), DATE_ADD(NOW(), INTERVAL 1 HOUR));

-- Test select
SELECT * FROM password_resets;

-- Test cleanup
DELETE FROM password_resets WHERE token = 'test_token';
```

---

## STEP 3: TEST PASSWORD RESET FUNCTIONALITY

### Test 1: Forgot Password Flow
1. Visit: `http://localhost/forgot_password.php`
2. Enter an email address that exists in the `users` table
3. Submit the form
4. **Expected:** Success message, no PHP error
5. **Check:** New record should appear in `password_resets` table

### Test 2: Reset Password Flow
1. From the forgot password test, copy the token from database
2. Visit: `http://localhost/reset_password.php?token=YOUR_TOKEN`
3. Enter a new password
4. Submit
5. **Expected:** Success message, password updated

### Test 3: Email Verification
Check your email (or mail.log) for the reset email:
```bash
tail -f logs/mail.log
```

---

## STEP 4: VERIFICATION CHECKLIST

### Database Level
- [ ] `password_resets` table exists
- [ ] Table has all required columns
- [ ] Foreign key constraint to `users` table exists
- [ ] Indexes are created
- [ ] No MySQL errors during table creation

### Application Level
- [ ] `forgot_password.php` loads without errors
- [ ] Password reset form submits successfully
- [ ] No "table doesn't exist" errors
- [ ] `reset_password.php` accepts valid tokens
- [ ] Password can be successfully reset

### Security Level
- [ ] Token hash is unique
- [ ] Expired tokens are rejected
- [ ] Used tokens cannot be reused
- [ ] Invalid tokens are rejected

---

## TROUBLESHOOTING

### Error: "Table doesn't exist"
**Solution:** Run the SQL script from Step 1

### Error: "Column doesn't exist"
**Solution:** Check if the exact column names match PHP code expectations

### Error: "Foreign key constraint"
**Solution:** Ensure `users` table exists and has `id` column
```sql
SHOW CREATE TABLE users;
```

### Error: "Permission denied"
**Solution:** Grant necessary permissions
```sql
GRANT CREATE, INSERT, SELECT, UPDATE, DELETE ON live_tc_local.password_resets TO 'username'@'localhost';
```

### Error: "Token expired"
**Solution:** Check server time and token lifetime in `forgot_password.php` (line 17: $TOKEN_LIFETIME = 60 * 60;)

### Database Connection Issues
**Test connection:**
```php
<?php
require_once 'config.php';
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
echo "✅ Database connection successful!\n";
echo "Database: " . DB_NAME . "\n";
?>
```

---

## MAINTENANCE QUERIES

### Clean Up Expired Tokens (Run Weekly)
```sql
DELETE FROM password_resets 
WHERE expires_at < NOW() 
   OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY));
```

### Check Token Statistics
```sql
SELECT 
    COUNT(*) as total_tokens,
    COUNT(CASE WHEN used_at IS NULL THEN 1 END) as unused_tokens,
    COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired_tokens
FROM password_resets;
```

---

## SECURITY NOTES

1. **Token Security:** Always use `token_hash` for validation, not plain `token`
2. **Expiration:** Tokens expire after 1 hour (configurable in `forgot_password.php`)
3. **One-time Use:** Each token can only be used once
4. **Rate Limiting:** 10-minute cooldown between reset requests
5. **Cleanup:** Expired/used tokens are automatically cleaned up

---

## FILES CREATED

- `create_password_resets_table.sql` - Complete SQL script
- This implementation guide

## NEXT STEPS

1. ✅ Execute SQL script
2. ✅ Verify table creation  
3. ✅ Test password reset flow
4. ✅ Monitor for any remaining issues
5. ✅ Set up periodic cleanup (optional)

**CRITICAL:** Test the complete flow before declaring fixed!