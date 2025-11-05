# MTM (Mental Trading Models) Implementation Plan

## Project Overview
Shaikhoology — Mental Trading Models (MTM) end-to-end system for disciplined, rule-based trading education through structured programs.

## Architecture Analysis

### Current System
- **Stack:** PHP 7+/MariaDB, procedural programming
- **Security:** Session-based auth, CSRF protection, email verification
- **Database:** Comprehensive relational schema with users, trades, admin controls
- **Key Features:** Trading journal, scoring system, admin approval workflows

### MTM System Requirements

#### Core Components
1. **MTM Models:** Structured programs with difficulty levels (easy/moderate/hard)
2. **Tasks:** Rule-based challenges with enforcement mechanisms
3. **Enrollments:** User participation with approval workflow
4. **Trade Integration:** MTM-specific trade creation and validation
5. **Progress Tracking:** Task completion and program advancement
6. **Leaderboards:** Global (non-MTM) vs MTM-specific scoring

#### Key Policies
- **One-MTM-at-a-time:** Users can only have one approved MTM enrollment
- **Trade Restrictions:** During MTM, only MTM trades allowed; normal trades read-only
- **Rule Enforcement:** Tiered blocking (nudges → soft blocks → hard blocks)

## Implementation Roadmap

### Phase 1: Database & Core Infrastructure
1. **SQL Migrations**
   - Extend existing tables (mtm_models, trades)
   - Create new tables (mtm_tasks, mtm_enrollments, mtm_task_progress)
   - Add indexes and foreign keys

2. **MTM Verifier Engine**
   - Rule resolution from task fields + JSON overrides
   - Trade evaluation against constraints
   - Progress update logic

### Phase 2: Admin Interface
1. **MTM Models Management**
   - List/create/edit models with cover images
   - Task management with rule configuration
   - Status controls (draft/active/paused/archived)

2. **Participant Management**
   - Enrollment approval/rejection workflow
   - Progress monitoring per model
   - Bulk operations

### Phase 3: User Interface
1. **MTM Program Discovery**
   - Card-based listing (Your Programs, Available, Completed)
   - Enrollment flow with disclaimer
   - Progress indicators

2. **MTM Model Views**
   - Program details and task progression
   - Enrollment status management
   - Quick actions and nudges

### Phase 4: Trade Integration
1. **Creation Enforcement**
   - MTM context injection during active enrollment
   - Rule validation with tiered blocking
   - Compliance status tracking

2. **Closure & Progress**
   - Automatic task evaluation on trade completion
   - Progress advancement logic
   - Failure handling and retry mechanisms

### Phase 5: Leaderboards & Analytics
1. **Global Leaderboard Updates**
   - Filter out MTM trades (enrollment_id IS NULL)
   - Maintain existing scoring logic

2. **MTM-Specific Leaderboards**
   - Per-model participant rankings
   - Rule adherence scoring bonuses
   - Completion tracking

## Data Model Extensions

### Existing Table Extensions
```sql
-- mtm_models (extend existing)
ALTER TABLE mtm_models ADD COLUMN IF NOT EXISTS ...;

-- trades (link to MTM)
ALTER TABLE trades ADD COLUMN IF NOT EXISTS enrollment_id INT NULL;
ALTER TABLE trades ADD COLUMN IF NOT EXISTS task_id INT NULL;
ALTER TABLE trades ADD COLUMN IF NOT EXISTS rules_snapshot JSON NULL;
ALTER TABLE trades ADD COLUMN IF NOT EXISTS compliance_status ENUM('unknown','pass','fail','override') NOT NULL DEFAULT 'unknown';
ALTER TABLE trades ADD COLUMN IF NOT EXISTS violation_json JSON NULL;
```

### New Tables
```sql
-- mtm_tasks
CREATE TABLE IF NOT EXISTS mtm_tasks (...);

-- mtm_enrollments
CREATE TABLE IF NOT EXISTS mtm_enrollments (...);

-- mtm_task_progress
CREATE TABLE IF NOT EXISTS mtm_task_progress (...);
```

## Rule Engine Specification

### Standard Fields
- min_trades, time_window_days, require_sl, max_risk_pct, max_position_pct, min_rr, require_analysis_link, weekly_min_trades, weeks_consistency

### Advanced JSON Overrides
```json
{
  "allowed_outcomes": ["TARGET HIT", "BE"],
  "min_win_rate_pct": 60,
  "max_open_days": 5,
  "require_chart_tag": "breakout",
  "min_points_total": 50,
  "market": "NSE",
  "min_capital": 50000,
  "min_gap_between_trades_h": 12,
  "forbid_avg_down": 1
}
```

### Enforcement Tiers
- **Easy/Basic:** Nudges (warnings, allow submit)
- **Moderate/Intermediate:** Soft blocks (confirmation modal, override with penalty)
- **Hard/Advanced:** Hard blocks (cannot submit until compliant)

## Page Contracts

### Admin Pages
- `/admin/mtm_models.php` - Model listing with counts and actions
- `/admin/mtm_model_view.php?id=` - Model details with tasks and participants tabs
- `/admin/mtm_model_edit.php?id=` - Model creation/editing with cover upload
- `/admin/mtm_participants.php?model_id=` - Enrollment management

### User Pages
- `/mtm.php` - Program discovery and enrollment status
- `/mtm_model_user.php?id=` - Model details and progress for enrolled users
- `/mtm_enroll.php?id=` - Enrollment disclaimer and submission

## Testing Strategy

### Critical Flows
1. **Enrollment Pipeline:** User request → Admin approval → Task initialization
2. **Trade Enforcement:** Rule validation with appropriate blocking per tier
3. **Progress Advancement:** Trade completion → Task evaluation → Next unlock
4. **Leaderboard Separation:** Global excludes MTM, MTM shows participants only

### Edge Cases
- Multiple enrollment attempts (no duplicates)
- Trade creation during MTM (block non-MTM)
- Rule violations and overrides
- Task failure limits and retry logic

## Deployment Checklist

### Pre-Deployment
- [ ] Run idempotent migrations
- [ ] Verify column existence checks
- [ ] Test admin guard on all admin pages
- [ ] Confirm CSRF on all forms

### Post-Deployment
- [ ] Create test MTM model with tasks
- [ ] Test full enrollment flow
- [ ] Verify trade blocking/enforcement
- [ ] Check leaderboard filtering
- [ ] Mobile responsiveness across all pages

## Success Metrics
- Clean enrollment workflow without errors
- Proper rule enforcement per difficulty tier
- Accurate task progress tracking
- Correct leaderboard separation
- No "Unknown column" or duplicate key errors