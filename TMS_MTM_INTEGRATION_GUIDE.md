# TMS-MTM Module Integration Guide

## Overview
This guide covers the integration of the "Shaikhoology TMS–MTM" module into your existing vanilla PHP trading platform.

## Prerequisites
- PHP 7.4 or higher
- MySQL database
- Existing user authentication system
- Composer (optional, not required for this module)

## Installation

### 1. Run Database Migration

#### Option A: Using MySQL CLI
```bash
mysql -u username -p database_name < database/migrations/002_tmsmtm.sql
```

#### Option B: Using PHP Runner
```bash
php maintenance/run_migration_002_tmsmtm.php
```

### 2. Verify Installation
After running the migration, verify that the following tables were created:
- `mtm_models`
- `mtm_tasks` 
- `mtm_enrollments`
- `trades`

## API Endpoints

### Base URL
All API endpoints are located under:
- `https://yourdomain.com/api/mtm/`
- `https://yourdomain.com/api/trades/`

### Authentication
All API endpoints require:
- Valid user session
- Active user status (email verified + approved)
- CSRF token for POST requests

### Rate Limiting
- GET endpoints: 10 requests per minute
- POST endpoints: 5 requests per minute
- Rate limit errors return HTTP 429

## API Reference

### 1. MTM Enrollment
**Endpoint:** `POST /api/mtm/enroll.php`

**Description:** Enroll a trader in an MTM model

**Headers:**
```
Content-Type: application/json
```

**Request Body:**
```json
{
    "model_id": 1,
    "tier": "basic",
    "csrf_token": "your_csrf_token"
}
```

**Success Response (200):**
```json
{
    "success": true,
    "enrollment_id": 123,
    "unlocked_task_id": 456
}
```

**Error Responses:**
- 400: Validation errors
- 409: Already enrolled
- 429: Rate limit exceeded
- 500: Server error

### 2. Get Enrollments
**Endpoint:** `GET /api/mtm/enrollments.php`

**Description:** Get all enrollments for the authenticated trader

**Success Response (200):**
```json
{
    "success": true,
    "items": [
        {
            "id": 123,
            "model_id": 1,
            "model_code": "BASIC_TMS",
            "model_name": "Basic Trading Management System",
            "tier": "basic",
            "status": "active",
            "started_at": "2025-11-04 21:05:00"
        }
    ]
}
```

### 3. Create Trade
**Endpoint:** `POST /api/trades/create.php`

**Description:** Create a new trade record

**Request Body:**
```json
{
    "symbol": "AAPL",
    "side": "buy",
    "quantity": 100,
    "price": 150.25,
    "opened_at": "2025-11-04 21:00:00",
    "notes": "Long position entry",
    "csrf_token": "your_csrf_token"
}
```

**Success Response (200):**
```json
{
    "success": true,
    "id": 789
}
```

### 4. List Trades
**Endpoint:** `GET /api/trades/list.php`

**Query Parameters:**
- `symbol` (optional): Filter by symbol
- `from` (optional): Filter from date (YYYY-MM-DD)
- `to` (optional): Filter to date (YYYY-MM-DD)
- `limit` (optional): Number of results (default: 50, max: 200)
- `offset` (optional): Pagination offset (default: 0)

**Example Request:**
```
GET /api/trades/list.php?symbol=AAPL&from=2025-11-01&limit=10
```

**Success Response (200):**
```json
{
    "success": true,
    "items": [
        {
            "id": 789,
            "symbol": "AAPL",
            "side": "buy",
            "quantity": 100,
            "price": 150.25,
            "opened_at": "2025-11-04 21:00:00",
            "closed_at": null,
            "notes": "Long position entry",
            "created_at": "2025-11-04 21:01:00"
        }
    ],
    "pagination": {
        "limit": 10,
        "offset": 0,
        "count": 1
    },
    "filters": {
        "symbol": "AAPL",
        "from": "2025-11-01"
    }
}
```

## Testing with cURL

### 1. Login and Get Session
First, ensure you have a valid session by logging in through your web interface.

### 2. Test Enrollment
```bash
curl -X POST https://yourdomain.com/api/mtm/enroll.php \
  -H "Content-Type: application/json" \
  -d '{
    "model_id": 1,
    "tier": "basic",
    "csrf_token": "your_csrf_token_here"
  }' \
  -c cookies.txt \
  -b cookies.txt
```

### 3. Test Get Enrollments
```bash
curl -X GET https://yourdomain.com/api/mtm/enrollments.php \
  -c cookies.txt \
  -b cookies.txt
```

### 4. Test Create Trade
```bash
curl -X POST https://yourdomain.com/api/trades/create.php \
  -H "Content-Type: application/json" \
  -d '{
    "symbol": "TSLA",
    "side": "sell",
    "quantity": 50,
    "price": 200.00,
    "opened_at": "2025-11-04 22:00:00",
    "notes": "Short position test",
    "csrf_token": "your_csrf_token_here"
  }' \
  -c cookies.txt \
  -b cookies.txt
```

### 5. Test List Trades
```bash
curl -X GET "https://yourdomain.com/api/trades/list.php?symbol=TSLA&limit=5" \
  -c cookies.txt \
  -b cookies.txt
```

## Web Interface

### MTM Enrollment Page
**URL:** `https://yourdomain.com/mtm_enroll.php`

A simple web interface for enrolling in MTM models is available. Access it through:
1. Login to your account
2. Navigate to the navigation menu
3. Click "🎯 MTM Enroll"

The page includes:
- Model selection dropdown
- Tier selection (basic/intermediate/advanced)
- Real-time enrollment with JavaScript
- Model information display

## Logging and Monitoring

### Log Files
All module activities are logged to `logs/app.log` using the `app_log()` function.

**Key Events Logged:**
- `mtm_enroll_attempt` - Enrollment attempt
- `mtm_enroll_success` - Successful enrollment
- `mtm_enroll_conflict` - Duplicate enrollment attempt
- `trade_create` - Trade creation attempt
- `trade_created` - Successful trade creation
- API errors and exceptions

### Example Log Entry
```json
{
    "event": "mtm_enroll_success",
    "trader_id": 123,
    "model_id": 1,
    "enrollment_id": 456,
    "unlocked_task_id": 789,
    "tier": "basic",
    "timestamp": "2025-11-04T21:05:00Z"
}
```

## Database Schema

### Core Tables

#### mtm_models
- `id` - Primary key
- `code` - Unique model code
- `name` - Model display name
- `tiering` - JSON configuration for tiers
- `is_active` - Model status

#### mtm_tasks
- `id` - Primary key
- `model_id` - Foreign key to mtm_models
- `tier` - Task tier (basic/intermediate/advanced)
- `level` - Task difficulty level
- `rule_config` - JSON rule configuration
- `sort_order` - Task ordering

#### mtm_enrollments
- `id` - Primary key
- `trader_id` - Foreign key to users
- `model_id` - Foreign key to mtm_models
- `tier` - Enrolled tier
- `status` - Enrollment status
- `started_at` - Enrollment timestamp

#### trades
- `id` - Primary key
- `trader_id` - Foreign key to users
- `symbol` - Trading symbol
- `side` - Buy/sell direction
- `quantity` - Trade quantity
- `price` - Trade price
- `opened_at` - Opening timestamp
- `closed_at` - Closing timestamp (nullable)

## Security Features

### CSRF Protection
- All POST endpoints require CSRF token validation
- Token is automatically generated in session
- Tokens expire with session

### Rate Limiting
- Per-endpoint rate limiting using session storage
- Configurable request limits per time window
- Returns HTTP 429 on exceeded limits

### Input Validation
- Comprehensive server-side validation
- SQL injection protection via prepared statements
- XSS protection via output escaping

### Session Security
- Hardened session configuration
- HTTPS enforcement
- HttpOnly and Secure flags
- SameSite cookie policy

## Troubleshooting

### Common Issues

1. **CSRF Token Errors**
   - Ensure session is properly started
   - Check that CSRF token is included in POST requests
   - Verify token hasn't expired

2. **Database Connection Errors**
   - Check database credentials in .env file
   - Verify MySQL service is running
   - Ensure database user has proper permissions

3. **Rate Limiting**
   - Check rate limits per endpoint
   - Wait for rate limit window to reset
   - Clear session if needed for testing

4. **Permission Errors**
   - Ensure user has active status
   - Verify email is confirmed
   - Check admin permissions if required

### Debug Mode
Set `APP_ENV=local` in your `.env` file for detailed error messages and stack traces.

## Integration Checklist

- [ ] Database migration completed successfully
- [ ] API endpoints respond correctly
- [ ] CSRF protection working
- [ ] Rate limiting active
- [ ] Logging functionality operational
- [ ] Web interface accessible
- [ ] Error handling tested
- [ ] Security features verified

## Support

For issues or questions regarding the TMS-MTM module integration:
1. Check the logs in `logs/app.log`
2. Verify database schema matches requirements
3. Test API endpoints with provided cURL examples
4. Review error messages and HTTP status codes

---

**Version:** 1.0  
**Last Updated:** 2025-11-04  
**Compatible with:** PHP 7.4+, MySQL 5.7+