# 🚀 Production Deployment File List

## ✅ PRODUCTION READY FILES

### Core Application Files (FIXED)
```
✓ dashboard.php                 - Enhanced with capital sync and P&L display
✓ trade_edit.php               - Fixed P&L calculation and capital updates
✓ dashboard_export_pdf.php     - Fixed P&L display format
✓ trade_score.php              - Unchanged (was already working)
```

### Database Migration Files
```
✓ add_pnl_column.sql           - Add P&L column and trading_capital
✓ fix_capital_display.sql      - Fix existing capital display issues
```

### Existing Files (Unchanged)
```
✓ header.php                   - No changes needed
✓ footer.php                   - No changes needed
✓ config.php                   - No changes needed
✓ functions.php               - No changes needed
✓ login.php                   - No changes needed
✓ register.php                - No changes needed
✓ trade_new.php               - No changes needed
✓ my_trades.php               - No changes needed
✓ All other existing files    - No changes needed
```

## ❌ NOT FOR PRODUCTION

### Testing/Debug Files (Remove)
```
✗ debug_capital_update.php     - Remove after testing
✗ test_trading_fixes.php       - Remove after testing
✗ fix_capital_via_web.php      - Remove after one-time fix
```

### Documentation Files (Keep for reference)
```
✓ IMPLEMENTATION_SUMMARY.md    - Keep for documentation
✓ CAPITAL_FIX_INSTRUCTIONS.md  - Keep for reference
✓ LOCAL_SERVER_SETUP.md        - Keep for reference
✓ DEPLOYMENT_READY_FILES.md    - This file
```

## 🚀 DEPLOYMENT STEPS

### Step 1: Upload Core Files
Upload these **FIXED** files to production:
```bash
dashboard.php
trade_edit.php  
dashboard_export_pdf.php
```

### Step 2: Run Database Migration
Run this **AFTER** backing up database:
```sql
mysql -u production_user -p production_database < add_pnl_column.sql
```

### Step 3: Fix Capital Display (If Needed)
```sql
mysql -u production_user -p production_database < fix_capital_display.sql
```

### Step 4: Remove Test Files
**Delete these from production:**
```
debug_capital_update.php
test_trading_fixes.php
fix_capital_via_web.php
```

## ✅ VERIFICATION CHECKLIST

- [ ] dashboard.php shows correct capital amounts
- [ ] trade_edit.php calculates P&L and updates capital
- [ ] P&L displays as "₹500 (10.5%)" format
- [ ] Performance matrix shows correct statistics
- [ ] PDF export shows correct P&L format
- [ ] No test/debug files in production
- [ ] Database migration completed successfully

## 📊 WHAT'S FIXED

1. **Capital Display:** funds_available now syncs with trading_capital
2. **P&L Updates:** Capital increases/decreases with trade profits/losses
3. **P&L Display:** Shows both amount and percentage
4. **Performance Metrics:** Accurate win rate and statistics

**Ready for production deployment!**