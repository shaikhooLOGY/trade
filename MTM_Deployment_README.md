# MTM (Mental Trading Models) System Deployment Guide

## Overview
This document provides step-by-step instructions for deploying the MTM (Mental Trading Models) system to the Shaikhoology trading platform.

## Prerequisites
- PHP 7+ with MySQL/MariaDB
- Existing Shaikhoology codebase
- Admin access to database and file system
- Web server (Apache/Nginx) with PHP support

## Deployment Steps

### 1. Database Migration
Run the idempotent SQL migration script to create MTM tables and extend existing ones:

```bash
# Connect to your MySQL/MariaDB database
mysql -u [username] -p [database_name] < mtm_migration.sql
```

**What this creates:**
- `mtm_models` table (extended existing)
- `mtm_tasks` table (new)
- `mtm_enrollments` table (new)
- `mtm_task_progress` table (new)
- Extended `trades` table with MTM columns

### 2. File Deployment
Upload all MTM-related files to your web server:

#### New Files to Upload:
```
system/mtm_verifier.php              # Core MTM rule engine
admin/mtm_models.php                 # Admin MTM models listing
admin/mtm_model_view.php             # Admin MTM model details
admin/mtm_model_edit.php             # Admin MTM model/task editor
admin/mtm_participants.php           # Admin enrollment management
mtm.php                              # User MTM programs listing
mtm_model_user.php                   # User MTM program view
mtm_enroll.php                       # User enrollment flow
mtm_leaderboard.php                  # MTM-specific leaderboards
mtm_migration.sql                    # Database migration script
```

#### Modified Files:
```
trade_new.php                        # MTM trade creation with enforcement
trade_score.php                      # MTM task progress evaluation
leaderboard.php                      # Global leaderboard filtering
dashboard.php                        # MTM progress indicators
```

### 3. Permission Setup
Ensure proper file permissions:
```bash
chmod 755 system/mtm_verifier.php
chmod 755 admin/mtm_*.php
chmod 755 mtm_*.php
chmod 644 uploads/mtm/               # Directory for MTM cover images
```

### 4. Admin Access Verification
Verify admin pages are properly guarded:
- All `/admin/mtm_*.php` pages should include `require_once __DIR__ . '/auth_check.php';`
- Admin guard checks `$_SESSION['is_admin']`

## Testing Checklist

### Pre-Launch Tests
- [ ] Run migration script without errors
- [ ] Verify all new tables created
- [ ] Check column additions to existing tables
- [ ] Confirm admin pages load with proper authentication
- [ ] Test file upload functionality for MTM covers

### Functional Tests

#### Admin Workflow
1. **Create MTM Model**
   - Login as admin
   - Navigate to `/admin/mtm_models.php`
   - Click "Create New Model"
   - Fill form: Title, Description, Difficulty, Status
   - Upload cover image
   - Save and verify model appears in list

2. **Add Tasks to Model**
   - Click "Edit" on created model
   - Add multiple tasks with different levels
   - Configure basic rules (min trades, time windows, etc.)
   - Add advanced JSON rules if needed
   - Save and verify tasks appear

3. **Model Management**
   - Change model status (draft → active)
   - Verify status changes work
   - Test model archiving

#### User Enrollment Flow
1. **Browse Programs**
   - Login as regular user
   - Navigate to `/mtm.php`
   - Verify available programs display
   - Check enrollment status indicators

2. **Enrollment Process**
   - Click "Enroll Now" on available program
   - Review disclaimer page
   - Accept terms and submit request
   - Verify pending status shows in "Your Programs"

3. **Admin Approval**
   - Login as admin
   - Go to `/admin/mtm_participants.php?model_id=X`
   - Find pending request
   - Click "Approve"
   - Verify user gets approved status

4. **Task Initialization**
   - Check that first task is unlocked for approved user
   - Verify task progress table populated

#### Trade Enforcement
1. **MTM Trade Creation**
   - As enrolled user, try to create regular trade
   - Verify block with redirect to MTM page
   - Create trade via MTM task link
   - Verify MTM context shows in trade form

2. **Rule Validation**
   - Test different difficulty levels (easy/moderate/hard)
   - Verify appropriate blocking behavior
   - Test override functionality for soft blocks

3. **Trade Completion**
   - Close MTM trade
   - Verify points calculation
   - Check task progress advancement
   - Confirm next task unlocks

#### Leaderboards
1. **Global Leaderboard**
   - Verify MTM trades excluded
   - Check existing functionality preserved

2. **MTM Leaderboard**
   - Access via enrolled user
   - Verify participant rankings
   - Check compliance metrics display

## Common Issues & Solutions

### Database Issues
**Error: "Unknown column"**
- Solution: Re-run migration script
- Check: `SHOW TABLES LIKE 'mtm_%';`
- Verify: `DESCRIBE trades;` includes new MTM columns

**Migration fails**
- Check database user permissions
- Ensure no foreign key conflicts
- Verify existing table structures

### File Upload Issues
**Cover images not uploading**
- Check `uploads/mtm/` directory exists and is writable
- Verify PHP upload settings in `php.ini`
- Check file size limits

### Permission Issues
**Admin pages accessible to users**
- Verify `auth_check.php` is included
- Check session variables set correctly
- Confirm admin role in database

### MTM Logic Issues
**Tasks not unlocking**
- Check `mtm_task_progress` table population
- Verify enrollment status is 'approved'
- Review MTM verifier logic in `system/mtm_verifier.php`

**Trade enforcement not working**
- Confirm user has active enrollment
- Check trade creation redirects
- Verify MTM context in `trade_new.php`

## Performance Considerations

### Database Optimization
- Indexes created on frequently queried columns
- Consider partitioning for large `trades` table
- Monitor query performance on leaderboard pages

### File Storage
- Implement cleanup for old/unused cover images
- Consider CDN for image delivery
- Set appropriate upload size limits

## Monitoring & Maintenance

### Regular Checks
- Monitor MTM enrollment growth
- Track task completion rates
- Review admin approval backlog
- Check for failed trade validations

### Backup Strategy
- Include new MTM tables in regular backups
- Backup uploaded cover images
- Document custom configurations

## Rollback Plan

If issues arise:
1. **Database Rollback**
   ```sql
   -- Drop new tables (be careful!)
   DROP TABLE IF EXISTS mtm_task_progress;
   DROP TABLE IF EXISTS mtm_enrollments;
   DROP TABLE IF EXISTS mtm_tasks;

   -- Remove added columns (check constraints first)
   ALTER TABLE trades DROP COLUMN IF EXISTS enrollment_id;
   ALTER TABLE trades DROP COLUMN IF EXISTS task_id;
   ALTER TABLE trades DROP COLUMN IF EXISTS rules_snapshot;
   ALTER TABLE trades DROP COLUMN IF EXISTS compliance_status;
   ALTER TABLE trades DROP COLUMN IF EXISTS violation_json;

   -- Revert mtm_models changes if needed
   ```

2. **File Rollback**
   - Remove uploaded MTM files
   - Restore original versions of modified files
   - Clear MTM-related sessions/cache

## Support

For issues not covered here:
1. Check PHP error logs
2. Review database error logs
3. Test with minimal data set
4. Contact development team with specific error messages

## Success Metrics

Monitor these KPIs post-deployment:
- MTM enrollment conversion rates
- Task completion percentages
- Trade compliance rates
- User engagement with MTM features
- Admin approval workflow efficiency