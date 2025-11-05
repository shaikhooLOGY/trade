#!/bin/bash
# maintenance/smoke_tests.sh
#
# Production Readiness Smoke Tests
# Purpose: Quick functional tests for critical API endpoints
# Usage: ./smoke_tests.sh [base_url]
#
# Example: ./smoke_tests.sh http://localhost:8000

set -e

# Configuration
BASE_URL="${BASE_URL:-${1:-http://localhost:8000}}"
RESULTS_FILE="smoke_test_results.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_TOTAL=0

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$RESULTS_FILE"
}

log_success() {
    echo -e "${GREEN}[PASS]${NC} $1" | tee -a "$RESULTS_FILE"
    ((TESTS_PASSED++))
}

log_error() {
    echo -e "${RED}[FAIL]${NC} $1" | tee -a "$RESULTS_FILE"
    ((TESTS_FAILED++))
}

log_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1" | tee -a "$RESULTS_FILE"
}

# Test execution function
run_test() {
    local test_name="$1"
    local method="$2"
    local endpoint="$3"
    local expected_status="$4"
    local data="$5"
    local headers="$6"
    
    ((TESTS_TOTAL++))
    
    log_info "Testing: $test_name"
    
    # Build curl command
    local curl_cmd="curl -s -w '\n%{http_code}' -X $method"
    
    # Add headers
    if [ -n "$headers" ]; then
        curl_cmd="$curl_cmd $headers"
    fi
    
    # Add data for POST requests
    if [ -n "$data" ] && [ "$method" = "POST" ]; then
        curl_cmd="$curl_cmd -H 'Content-Type: application/json' -d '$data'"
    fi
    
    # Add URL
    curl_cmd="$curl_cmd '${BASE_URL}${endpoint}'"
    
    # Execute request
    response=$(eval $curl_cmd)
    
    # Extract response body and status code
    status_code=$(echo "$response" | tail -n 1)
    response_body=$(echo "$response" | head -n -1)
    
    # Check status code
    if [ "$status_code" = "$expected_status" ]; then
        # Check for JSON response validity
        if echo "$response_body" | jq . > /dev/null 2>&1; then
            log_success "$test_name - Status: $status_code"
            return 0
        else
            log_warning "$test_name - Status: $status_code but invalid JSON"
            echo "$response_body" | head -n 10
            return 1
        fi
    else
        log_error "$test_name - Expected: $expected_status, Got: $status_code"
        echo "Response: $response_body" | head -n 5
        return 1
    fi
}

# Test results summary
print_summary() {
    echo
    echo "========================================" | tee -a "$RESULTS_FILE"
    echo "SMOKE TEST RESULTS" | tee -a "$RESULTS_FILE"
    echo "Timestamp: $TIMESTAMP" | tee -a "$RESULTS_FILE"
    echo "Base URL: $BASE_URL" | tee -a "$RESULTS_FILE"
    echo "========================================" | tee -a "$RESULTS_FILE"
    echo "Total Tests: $TESTS_TOTAL" | tee -a "$RESULTS_FILE"
    echo "Passed: $TESTS_PASSED" | tee -a "$RESULTS_FILE"
    echo "Failed: $TESTS_FAILED" | tee -a "$RESULTS_FILE"
    echo "Success Rate: $(($TESTS_PASSED * 100 / $TESTS_TOTAL))%" | tee -a "$RESULTS_FILE"
    echo "========================================" | tee -a "$RESULTS_FILE"
    
    if [ $TESTS_FAILED -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${NC}" | tee -a "$RESULTS_FILE"
        exit 0
    else
        echo -e "${RED}Some tests failed. Please check the logs.${NC}" | tee -a "$RESULTS_FILE"
        exit 1
    fi
}

# Main test execution
echo "Starting Production Readiness Smoke Tests..." | tee "$RESULTS_FILE"
echo "Timestamp: $TIMESTAMP" | tee -a "$RESULTS_FILE"
echo "BASE_URL: $BASE_URL" | tee -a "$RESULTS_FILE"
echo "========================================" | tee -a "$RESULTS_FILE"

# =====================================================
# PHASE 1: BASIC CONNECTIVITY TESTS
# =====================================================

log_info "PHASE 1: Basic Connectivity Tests"

# Test 1: Health Check - Handle gracefully based on environment
log_info "Testing: Health Check Endpoint"
response=$(curl -s -w '\n%{http_code}' "${BASE_URL}/api/health.php")
status_code=$(echo "$response" | tail -n 1)

if [ "$status_code" = "200" ]; then
    log_success "Health Check Endpoint - Status: $status_code (APP_ENV=local)"
elif [ "$status_code" = "404" ]; then
    log_success "Health Check Endpoint - Status: $status_code (Health endpoint not available - APP_ENV != local?)"
    echo "Note: Health endpoint returns 200 only when APP_ENV=local; otherwise 404 JSON."
else
    log_error "Health Check Endpoint - Unexpected status: $status_code"
fi

# Test 2: Root endpoint (should be 200)
run_test "Root Endpoint" "GET" "/" "200"

# Test 3: Non-existent endpoint (should be 404)
run_test "404 Endpoint" "GET" "/nonexistent" "404"

# =====================================================
# PHASE 2: AUTHENTICATION TESTS
# =====================================================

log_info "PHASE 2: Authentication Tests"

# Test 4: Login endpoint availability
run_test "Login Endpoint" "GET" "/login.php" "200"

# Test 5: Protected endpoint without auth (should be redirected)
run_test "Protected Endpoint" "GET" "/dashboard.php" "302"

# Test 6: Logout endpoint availability
run_test "Logout Endpoint" "GET" "/logout.php" "200"

# =====================================================
# PHASE 3: SECURITY HARDENING TESTS
# =====================================================

log_info "PHASE 3: Security Hardening Tests"

# Test 7: CSRF endpoint availability
run_test "CSRF Endpoint" "GET" "/api/util/csrf.php" "200"

# Test 8: Debug endpoint (should be 404 in production)
run_test "Debug Endpoint" "GET" "/debug_all.php" "404"

# Test 9: Feature gating test (should be 404 if disabled)
run_test "Feature Gating" "GET" "/api/dashboard/metrics.php" "404"

# =====================================================
# PHASE 4: API ENDPOINT TESTS
# =====================================================

log_info "PHASE 4: API Endpoint Tests"

# Test 10: Dashboard API
run_test "Dashboard Metrics API" "GET" "/api/dashboard/metrics.php" "401"

# Test 11: Leaderboard API
run_test "Leaderboard API" "GET" "/api/leaderboard/top.php" "401"

# Test 12: Profile API (should require authentication)
run_test "Profile API" "GET" "/api/profile/me.php" "401"

# Test 13: Admin API (should require admin authentication)
run_test "Admin API - Users" "GET" "/api/admin/users/search.php" "401"

# Test 14: Admin API - Participants
run_test "Admin API - Participants" "GET" "/api/admin/participants.php" "401"

# Test 15: Admin API - Enrollment operations (POST endpoints)
run_test "Admin API - Approve" "POST" "/api/admin/enrollment/approve.php" "401"
run_test "Admin API - Reject" "POST" "/api/admin/enrollment/reject.php" "401"

# =====================================================
# PHASE 5: DATA INTEGRITY TESTS
# =====================================================

log_info "PHASE 5: Data Integrity Tests"

# Test 16: Create trade without auth (should fail)
run_test "Create Trade - No Auth" "POST" "/api/trades/create.php" "401"

# Test 17: Get trades without auth (should fail)
run_test "Get Trades - No Auth" "GET" "/api/trades/list.php" "401"

# Test 18: Update trade without auth (should fail)
run_test "Update Trade - No Auth" "POST" "/api/trades/update.php" "401"

# Test 19: Delete trade without auth (should fail)
run_test "Delete Trade - No Auth" "POST" "/api/trades/delete.php" "401"

# Test 20: Get single trade without auth (should fail)
run_test "Get Trade - No Auth" "GET" "/api/trades/get.php" "401"

# =====================================================
# PHASE 6: ADMIN FUNCTIONALITY TESTS
# =====================================================

log_info "PHASE 6: Admin Functionality Tests"

# Test 21: Admin dashboard (should require admin)
run_test "Admin Dashboard" "GET" "/admin/dashboard.php" "302"

# Test 22: Admin participants (should require admin)
run_test "Admin Participants" "GET" "/admin/mtm_participants.php" "302"

# Test 23: Admin users (should require admin)
run_test "Admin Users" "GET" "/admin/users.php" "302"

# =====================================================
# PHASE 7: ERROR HANDLING TESTS
# =====================================================

log_info "PHASE 7: Error Handling Tests"

# Test 24: Invalid JSON (POST endpoints)
run_test "Invalid JSON" "POST" "/api/trades/create.php" "401" '{"invalid": json}'

# Test 25: Wrong HTTP method
run_test "Wrong Method" "PUT" "/api/dashboard/metrics.php" "405"

# Test 26: Missing required fields
run_test "Missing Fields" "POST" "/api/trades/create.php" "401"

# =====================================================
# PHASE 8: PERFONSE TESTS (OPTIONAL)
# =====================================================

log_info "PHASE 8: Performance Tests"

# Test 27: Response time check (basic endpoint)
start_time=$(date +%s.%N)
curl -s "$BASE_URL/" > /dev/null
end_time=$(date +%s.%N)
response_time=$(echo "$end_time - $start_time" | bc)

if (( $(echo "$response_time < 1.0" | bc -l) )); then
    log_success "Response Time Test - $response_time seconds"
else
    log_warning "Response Time Test - $response_time seconds (slower than expected)"
fi

# =====================================================
# FINAL SUMMARY
# =====================================================

print_summary