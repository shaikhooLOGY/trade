#!/bin/bash
# E2E Test Suite Runner - One-line local execution
# Usage: bash maintenance/run_e2e.sh

set -e

# Configuration
BASE_URL="http://127.0.0.1:8082"
PORT=8082
REPORTS_DIR="reports/e2e"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
E2E_RUNNER="$PROJECT_DIR/reports/e2e/e2e_runner.php"
LAST_STATUS_FILE="$PROJECT_DIR/reports/e2e/last_status.json"
LAST_FAIL_FILE="$PROJECT_DIR/reports/e2e/last_fail.txt"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 E2E Test Suite Runner${NC}"
echo "=================================="

# Function to check if port is in use
check_port() {
    if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Function to start PHP server
start_php_server() {
    echo -e "${YELLOW}📡 Starting PHP built-in server on port $PORT...${NC}"
    cd "$PROJECT_DIR"
    
    # Set environment variables
    export APP_ENV=local
    export APP_FEATURE_APIS=on
    export BASE_URL=$BASE_URL
    
    # Start server in background
    php -S 127.0.0.1:$PORT > /tmp/php_server.log 2>&1 &
    PHP_PID=$!
    
    # Wait for server to be ready
    echo -e "${YELLOW}⏳ Waiting for server to start...${NC}"
    for i in {1..10}; do
        if curl -s "$BASE_URL" >/dev/null 2>&1; then
            echo -e "${GREEN}✅ Server started successfully (PID: $PHP_PID)${NC}"
            return 0
        fi
        sleep 1
    done
    
    echo -e "${RED}❌ Failed to start PHP server${NC}"
    return 1
}

# Function to stop PHP server
stop_php_server() {
    if [ ! -z "$PHP_PID" ]; then
        echo -e "${YELLOW}🛑 Stopping PHP server (PID: $PHP_PID)...${NC}"
        kill $PHP_PID 2>/dev/null || true
        wait $PHP_PID 2>/dev/null || true
    fi
}

# Function to run E2E tests
run_e2e_tests() {
    echo -e "${BLUE}🧪 Running E2E Test Suite...${NC}"
    
    # Set environment variables
    export APP_ENV=local
    export APP_FEATURE_APIS=on
    export BASE_URL=$BASE_URL
    
    # Run the E2E suite
    cd "$PROJECT_DIR"
    if php "$E2E_RUNNER" --base-url="$BASE_URL" --cleanup=on --verbose=off; then
        return 0
    else
        return 1
    fi
}

# Function to extract test results
extract_results() {
    echo -e "${BLUE}📊 Extracting test results...${NC}"
    
    # Get the most recent test result
    LATEST_DIR=$(ls -t "$PROJECT_DIR/$REPORTS_DIR"/[0-9]* 2>/dev/null | head -1)
    
    if [ -z "$LATEST_DIR" ]; then
        echo -e "${RED}❌ No test results found${NC}"
        return 1
    fi
    
    # Find JSON result file
    JSON_FILE=$(find "$LATEST_DIR" -name "E2E_FULL_*.json" | head -1)
    
    if [ -z "$JSON_FILE" ]; then
        echo -e "${RED}❌ No JSON result file found${NC}"
        return 1
    fi
    
    echo -e "${GREEN}📁 Found results in: $LATEST_DIR${NC}"
    
    # Extract key metrics
    if command -v jq >/dev/null 2>&1; then
        # Use jq if available
        TOTAL_STEPS=$(jq '.steps | length' "$JSON_FILE")
        SUCCESS_STEPS=$(jq '[.steps[] | select(.status == "SUCCESS")] | length' "$JSON_FILE")
        TIMESTAMP=$(jq -r '.timestamp' "$JSON_FILE")
        
        if [ "$TOTAL_STEPS" -gt 0 ]; then
            PASS_RATE=$((SUCCESS_STEPS * 100 / TOTAL_STEPS))
        else
            PASS_RATE=0
        fi
        
        # Create last_status.json
        cat > "$LAST_STATUS_FILE" << EOF
{
  "success": $([ "$PASS_RATE" -eq 100 ] && echo "true" || echo "false"),
  "pass_rate": $PASS_RATE,
  "last_run_at": "$TIMESTAMP",
  "total_steps": $TOTAL_STEPS,
  "successful_steps": $SUCCESS_STEPS,
  "failing_tests": $(jq '[.steps[] | select(.status != "SUCCESS") | .id]' "$JSON_FILE"),
  "base_url": "$BASE_URL"
}
EOF
        
        # Create failure file if any failures
        FAILING_TESTS=$(jq -r '[.steps[] | select(.status != "SUCCESS") | .id] | join(", ")' "$JSON_FILE")
        if [ -n "$FAILING_TESTS" ] && [ "$FAILING_TESTS" != "" ]; then
            echo "E2E Test Suite FAILED: $PASS_RATE% - $TIMESTAMP - Failing tests: $FAILING_TESTS" > "$LAST_FAIL_FILE"
        else
            rm -f "$LAST_FAIL_FILE"
        fi
        
    else
        # Fallback if jq is not available
        echo -e "${YELLOW}⚠️ jq not available, using basic parsing${NC}"
        
        # Simple grep-based parsing
        TOTAL_STEPS=$(grep -o '"id":' "$JSON_FILE" | wc -l)
        SUCCESS_STEPS=$(grep -o '"status": "SUCCESS"' "$JSON_FILE" | wc -l)
        TIMESTAMP=$(grep -o '"timestamp": "[^"]*"' "$JSON_FILE" | head -1 | cut -d'"' -f4)
        
        if [ "$TOTAL_STEPS" -gt 0 ]; then
            PASS_RATE=$((SUCCESS_STEPS * 100 / TOTAL_STEPS))
        else
            PASS_RATE=0
        fi
        
        # Create basic last_status.json
        cat > "$LAST_STATUS_FILE" << EOF
{
  "success": $([ "$PASS_RATE" -eq 100 ] && echo "true" || echo "false"),
  "pass_rate": $PASS_RATE,
  "last_run_at": "$TIMESTAMP",
  "total_steps": $TOTAL_STEPS,
  "successful_steps": $SUCCESS_STEPS,
  "base_url": "$BASE_URL"
}
EOF
    fi
    
    echo -e "${GREEN}✅ Results extracted successfully${NC}"
    return 0
}

# Function to print summary
print_summary() {
    if [ -f "$LAST_STATUS_FILE" ]; then
        SUCCESS=$(jq -r '.success' "$LAST_STATUS_FILE")
        PASS_RATE=$(jq -r '.pass_rate' "$LAST_STATUS_FILE")
        TIMESTAMP=$(jq -r '.last_run_at' "$LAST_STATUS_FILE")
        
        if [ "$SUCCESS" = "true" ]; then
            echo -e "\n${GREEN}🎉 E2E Test Suite: PASS${NC}"
            echo -e "${GREEN}✅ All tests passed ($PASS_RATE%)${NC}"
            echo -e "${BLUE}📅 Last run: $TIMESTAMP${NC}"
        else
            FAILING_TESTS=$(jq -r '.failing_tests | join(", ")' "$LAST_STATUS_FILE" 2>/dev/null || echo "Multiple failures")
            echo -e "\n${RED}❌ E2E Test Suite: FAIL${NC}"
            echo -e "${RED}💥 Pass rate: $PASS_RATE${NC}"
            echo -e "${RED}🔍 Failing tests: $FAILING_TESTS${NC}"
            echo -e "${BLUE}📅 Last run: $TIMESTAMP${NC}"
            
            if [ -f "$LAST_FAIL_FILE" ]; then
                echo -e "${YELLOW}📄 See $LAST_FAIL_FILE for details${NC}"
            fi
        fi
    else
        echo -e "\n${RED}❌ No test results available${NC}"
    fi
}

# Trap to ensure cleanup
trap stop_php_server EXIT

# Main execution
main() {
    # Check if we need to start the server
    SERVER_WAS_RUNNING=false
    if check_port; then
        echo -e "${GREEN}✅ PHP server already running on port $PORT${NC}"
        SERVER_WAS_RUNNING=true
    else
        if ! start_php_server; then
            exit 1
        fi
    fi
    
    # Run E2E tests
    if run_e2e_tests; then
        TEST_EXIT_CODE=0
    else
        TEST_EXIT_CODE=1
    fi
    
    # Extract results regardless of test outcome
    extract_results || true
    
    # Print summary
    print_summary
    
    # Stop server only if we started it
    if [ "$SERVER_WAS_RUNNING" = false ]; then
        stop_php_server
    fi
    
    # Exit with appropriate code
    if [ $TEST_EXIT_CODE -eq 0 ] && [ -f "$LAST_STATUS_FILE" ] && [ "$(jq -r '.success' "$LAST_STATUS_FILE")" = "true" ]; then
        exit 0
    else
        exit 1
    fi
}

# Run main function
main "$@"