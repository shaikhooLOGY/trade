#!/bin/bash

# PHASE 3 VALIDATION - TASK 3: RATE LIMIT STRESS TEST
# Testing rate limiting mechanisms across critical endpoints

BASE_URL="http://127.0.0.1:8082"
COOKIE_JAR="/tmp/rate_limit_cookies.txt"
RESULTS_FILE="/tmp/rate_limit_test_results.txt"

echo "========================================="
echo "RATE LIMIT STRESS TEST - PHASE 3 TASK 3"
echo "========================================="
echo "Start Time: $(date)"
echo "Base URL: $BASE_URL"
echo ""

# Clear previous results
> "$RESULTS_FILE"
> "$COOKIE_JAR"

# Function to test an endpoint with burst requests
test_endpoint() {
    local endpoint="$1"
    local url="${BASE_URL}${endpoint}"
    local name="$2"
    
    echo ""
    echo "========================================="
    echo "Testing: $name"
    echo "URL: $url"
    echo "========================================="
    echo ""
    
    # Write header to results
    echo "" >> "$RESULTS_FILE"
    echo "=========================================" >> "$RESULTS_FILE"
    echo "ENDPOINT: $name" >> "$RESULTS_FILE"
    echo "URL: $url" >> "$RESULTS_FILE"
    echo "TEST TIME: $(date)" >> "$RESULTS_FILE"
    echo "=========================================" >> "$RESULTS_FILE"
    echo "" >> "$RESULTS_FILE"
    
    # Execute 12 rapid POST requests
    for i in {1..12}; do
        echo "Request $i/12..."
        
        # Prepare appropriate POST data for each endpoint
        case "$endpoint" in
            "/login.php")
                post_data="username=testuser&password=testpass&login=Login"
                ;;
            "/api/trades/create.php")
                post_data='{"symbol":"AAPL","quantity":100,"price":150.00}'
                ;;
            "/api/mtm/enroll.php")
                post_data='{"level":"beginner","preference":"aggressive"}'
                ;;
            "/api/admin/enrollment/approve.php")
                post_data='{"user_id":1,"enrollment_id":123,"action":"approve"}'
                ;;
        esac
        
        # Execute curl request and capture full response
        response=$(curl -s -w "\n---RESPONSE_END---\n%{http_code}\n%{time_total}\n%{header_out}\n%{header_in}" \
            -X POST \
            -H "Content-Type: application/x-www-form-urlencoded" \
            -d "$post_data" \
            -b "$COOKIE_JAR" \
            -c "$COOKIE_JAR" \
            "$url")
        
        # Parse response components
        status_code=$(echo "$response" | grep -A1 "---RESPONSE_END---" | head -1)
        time_total=$(echo "$response" | grep -A2 "---RESPONSE_END---" | tail -1)
        headers=$(echo "$response" | grep -A3 "---RESPONSE_END---" | head -2)
        body=$(echo "$response" | sed '/---RESPONSE_END---/q' | sed '/---RESPONSE_END---/d')
        
        # Check for rate limiting headers
        retry_after=$(echo "$headers" | grep -i "retry-after:" | cut -d' ' -f2- | tr -d '\r')
        rate_limit_limit=$(echo "$headers" | grep -i "x-ratelimit-limit:" | cut -d' ' -f2- | tr -d '\r')
        rate_limit_remaining=$(echo "$headers" | grep -i "x-ratelimit-remaining:" | cut -d' ' -f2- | tr -d '\r')
        rate_limit_reset=$(echo "$headers" | grep -i "x-ratelimit-reset:" | cut -d' ' -f2- | tr -d '\r')
        
        # Display and log results
        echo "  Status: $status_code | Time: ${time_total}s"
        if [ -n "$retry_after" ]; then
            echo "  Retry-After: $retry_after"
        fi
        if [ -n "$rate_limit_limit" ]; then
            echo "  X-RateLimit-Limit: $rate_limit_limit"
        fi
        if [ -n "$rate_limit_remaining" ]; then
            echo "  X-RateLimit-Remaining: $rate_limit_remaining"
        fi
        if [ -n "$rate_limit_reset" ]; then
            echo "  X-RateLimit-Reset: $rate_limit_reset"
        fi
        
        # Log to results file
        echo "Request $i:" >> "$RESULTS_FILE"
        echo "  Status Code: $status_code" >> "$RESULTS_FILE"
        echo "  Response Time: ${time_total}s" >> "$RESULTS_FILE"
        if [ -n "$retry_after" ]; then
            echo "  Retry-After: $retry_after" >> "$RESULTS_FILE"
        fi
        if [ -n "$rate_limit_limit" ]; then
            echo "  X-RateLimit-Limit: $rate_limit_limit" >> "$RESULTS_FILE"
        fi
        if [ -n "$rate_limit_remaining" ]; then
            echo "  X-RateLimit-Remaining: $rate_limit_remaining" >> "$RESULTS_FILE"
        fi
        if [ -n "$rate_limit_reset" ]; then
            echo "  X-RateLimit-Reset: $rate_limit_reset" >> "$RESULTS_FILE"
        fi
        echo "  Body Preview: ${body:0:200}..." >> "$RESULTS_FILE"
        echo "" >> "$RESULTS_FILE"
        
        # Check for 429 response
        if [ "$status_code" = "429" ]; then
            echo "  ⚠️  RATE LIMITED! (429 Too Many Requests)"
        elif [ "$status_code" = "200" ]; then
            echo "  ✅ Success (200 OK)"
        else
            echo "  ℹ️  Status: $status_code"
        fi
        
        # Small delay between requests (except after last request)
        if [ $i -lt 12 ]; then
            sleep 0.1
        fi
    done
}

# Test all four endpoints
echo "Starting burst tests on all endpoints..."
echo ""

test_endpoint "/login.php" "Login Endpoint"
test_endpoint "/api/trades/create.php" "Trade Creation API"
test_endpoint "/api/mtm/enroll.php" "MTM Enrollment API"
test_endpoint "/api/admin/enrollment/approve.php" "Admin Enrollment Approval API"

# Final analysis
echo ""
echo "========================================="
echo "RATE LIMIT TESTING COMPLETE"
echo "========================================="
echo "End Time: $(date)"
echo ""

# Summary analysis
echo "Analyzing results for 429 responses..."
echo ""

# Count 429s for each endpoint
echo "Rate Limiting Summary:" >> "$RESULTS_FILE"
echo "=====================" >> "$RESULTS_FILE"

for endpoint in "/login.php" "/api/trades/create.php" "/api/mtm/enroll.php" "/api/admin/enrollment/approve.php"; do
    case "$endpoint" in
        "/login.php") name="Login Endpoint" ;;
        "/api/trades/create.php") name="Trade Creation API" ;;
        "/api/mtm/enroll.php") name="MTM Enrollment API" ;;
        "/api/admin/enrollment/approve.php") name="Admin Enrollment Approval API" ;;
    esac
    
    count=$(grep -A20 "ENDPOINT: $name" "$RESULTS_FILE" | grep -c "Status Code: 429")
    echo "$name: $count/12 requests returned 429" >> "$RESULTS_FILE"
    echo "$name: $count/12 requests returned 429"
done

echo ""
echo "Detailed results saved to: $RESULTS_FILE"
echo "Cookie jar: $COOKIE_JAR"

# Clean up
rm -f "$COOKIE_JAR"

echo ""
echo "Test execution completed!"