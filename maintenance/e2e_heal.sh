#!/bin/bash
# E2E Self-Healing Script - Automated test data reset and recovery
# Usage: bash maintenance/e2e_heal.sh

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
HEAL_LOGS_DIR="$PROJECT_DIR/reports/e2e/heal_logs"
MAX_ATTEMPTS=3
TARGET_PASS_RATE=70
START_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)
HEAL_RUN_ID="heal_$(date +%Y%m%d_%H%M%S)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🩹 E2E Self-Healing System${NC}"
echo "==================================="
echo "Run ID: $HEAL_RUN_ID"
echo "Start Time: $START_TIME"
echo "Target Pass Rate: ${TARGET_PASS_RATE}%"
echo ""

# Create heal logs directory
mkdir -p "$HEAL_LOGS_DIR"

# Function to log healing attempts
log_heal() {
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $1" >> "$HEAL_LOGS_DIR/heal_$HEAL_RUN_ID.log"
}

log_heal "=== E2E Self-Healing Started ==="
log_heal "Run ID: $HEAL_RUN_ID"
log_heal "Target Pass Rate: ${TARGET_PASS_RATE}%"

# Function to clean up test data
cleanup_test_data() {
    log_heal "Starting test data cleanup..."
    
    cd "$PROJECT_DIR"
    
    # Clean up test users and MTM data
    if php -r "
        // Database configuration
        require_once 'includes/env.php';
        \$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
        
        // Clean up in correct order (foreign key constraints)
        \$tables = [
            'mtm_enrollments',
            'trades', 
            'mtm_tasks',
            'mtm_models',
            'users'
        ];
        
        foreach (\$tables as \$table) {
            \$stmt = \$pdo->prepare('DELETE FROM ' . \$table . ' WHERE email LIKE ? OR email LIKE ? OR email LIKE ?');
            \$stmt->execute(['%@local.test', '%@e2e.test', '%@test.local']);
            echo 'Cleaned ' . \$stmt->rowCount() . ' records from ' . \$table . PHP_EOL;
        }
    "; then
        log_heal "Test data cleanup completed successfully"
    else
        log_heal "WARNING: Test data cleanup encountered issues"
    fi
}

# Function to seed baseline data
seed_baseline_data() {
    log_heal "Seeding baseline test data..."
    
    cd "$PROJECT_DIR"
    
    # Create admin user
    if php -r "
        require_once 'includes/env.php';
        \$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
        
        // Create admin user
        \$admin_email = 'admin@heal.test';
        \$admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        
        \$stmt = \$pdo->prepare('INSERT INTO users (email, password, name, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        \$stmt->execute([\$admin_email, \$admin_password, 'Healing Admin', 'admin', 1]);
        
        echo 'Admin user created/updated: ' . \$admin_email . PHP_EOL;
        
        // Create test users
        for (\$i = 1; \$i <= 5; \$i++) {
            \$email = 'testuser' . \$i . '@e2e.test';
            \$password = password_hash('test123', PASSWORD_DEFAULT);
            
            \$stmt = \$pdo->prepare('INSERT INTO users (email, password, name, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name)');
            \$stmt->execute([\$email, \$password, 'Test User ' . \$i, 'user', 1]);
        }
        
        echo 'Test users created/updated' . PHP_EOL;
        
        // Create baseline MTM model
        \$stmt = \$pdo->prepare('INSERT INTO mtm_models (name, description, status, created_by, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        \$stmt->execute(['Healing Test Model', 'Auto-generated test model for self-healing', 'active', 1]);
        
        echo 'Baseline MTM model created' . PHP_EOL;
    "; then
        log_heal "Baseline data seeding completed successfully"
    else
        log_heal "ERROR: Failed to seed baseline data"
        return 1
    fi
}

# Function to run E2E tests with retry logic
run_e2e_with_retry() {
    local attempt=1
    local success=false
    
    while [ $attempt -le $MAX_ATTEMPTS ]; do
        log_heal "E2E Test Attempt $attempt of $MAX_ATTEMPTS"
        echo -e "${YELLOW}🧪 E2E Test Attempt $attempt of $MAX_ATTEMPTS${NC}"
        
        cd "$PROJECT_DIR"
        
        # Run E2E tests
        if bash maintenance/run_e2e.sh --cleanup=on; then
            # Extract pass rate
            if [ -f "reports/e2e/last_status.json" ]; then
                PASS_RATE=$(jq -r '.pass_rate' reports/e2e/last_status.json)
                log_heal "E2E tests completed with pass rate: ${PASS_RATE}%"
                
                if [ "$PASS_RATE" -ge "$TARGET_PASS_RATE" ]; then
                    log_heal "SUCCESS: Pass rate ${PASS_RATE}% meets target ${TARGET_PASS_RATE}%"
                    echo -e "${GREEN}✅ E2E tests PASSED - Pass rate: ${PASS_RATE}%${NC}"
                    success=true
                    break
                else
                    log_heal "INSUFFICIENT: Pass rate ${PASS_RATE}% below target ${TARGET_PASS_RATE}%"
                    echo -e "${YELLOW}⚠️ Pass rate ${PASS_RATE}% below target ${TARGET_PASS_RATE}%${NC}"
                fi
            else
                log_heal "ERROR: No E2E status file found"
                echo -e "${RED}❌ No E2E status file found${NC}"
            fi
        else
            log_heal "E2E tests failed on attempt $attempt"
            echo -e "${RED}❌ E2E tests failed on attempt $attempt${NC}"
        fi
        
        attempt=$((attempt + 1))
        
        if [ $attempt -le $MAX_ATTEMPTS ]; then
            log_heal "Waiting 30 seconds before next attempt..."
            sleep 30
        fi
    done
    
    if [ "$success" = true ]; then
        return 0
    else
        return 1
    fi
}

# Function to update healing statistics
update_heal_stats() {
    local final_status=$1
    local total_attempts=$2
    local final_pass_rate=$3
    
    log_heal "Updating healing statistics..."
    
    # Update context file with healing info
    if [ -f "context/project_context.json" ]; then
        # Read current context and update E2E section
        jq --arg run_at "$START_TIME" \
           --arg pass_rate "$final_pass_rate" \
           --arg heal_attempts "$total_attempts" \
           --arg last_status "$final_status" \
           '.e2e.last_run_at = $run_at | 
            .e2e.pass_rate = ($pass_rate | tonumber) | 
            .e2e.heal_attempts = ($heal_attempts | tonumber) | 
            .e2e.last_ci_status = $last_status' \
           context/project_context.json > context/project_context.json.tmp
        
        mv context/project_context.json.tmp context/project_context.json
        log_heal "Context file updated with healing statistics"
    fi
    
    # Create healing summary
    cat > "$HEAL_LOGS_DIR/heal_summary_$HEAL_RUN_ID.json" << EOF
{
  "heal_run_id": "$HEAL_RUN_ID",
  "start_time": "$START_TIME",
  "end_time": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "final_status": "$final_status",
  "heal_attempts": $total_attempts,
  "final_pass_rate": $final_pass_rate,
  "target_pass_rate": $TARGET_PASS_RATE,
  "max_attempts": $MAX_ATTEMPTS,
  "success": $([ "$final_status" = "passed" ] && echo "true" || echo "false")
}
EOF
}

# Main healing execution
main() {
    log_heal "Starting main healing procedure"
    
    local attempt=1
    local final_status="failed"
    local final_pass_rate=0
    
    # Step 1: Clean up test data
    cleanup_test_data || log_heal "Cleanup had issues but continuing..."
    
    # Step 2: Seed baseline data
    if seed_baseline_data; then
        log_heal "Baseline data seeded successfully"
    else
        log_heal "ERROR: Failed to seed baseline data"
        echo -e "${RED}❌ Failed to seed baseline data${NC}"
        update_heal_stats "failed" 0 0
        exit 1
    fi
    
    # Step 3: Run E2E tests with retry
    if run_e2e_with_retry; then
        final_status="passed"
        if [ -f "reports/e2e/last_status.json" ]; then
            final_pass_rate=$(jq -r '.pass_rate' reports/e2e/last_status.json)
        fi
        echo -e "${GREEN}🎉 Self-healing SUCCESSFUL${NC}"
    else
        final_status="failed"
        log_heal "Self-healing FAILED after $MAX_ATTEMPTS attempts"
        echo -e "${RED}💥 Self-healing FAILED${NC}"
    fi
    
    # Step 4: Update statistics
    update_heal_stats "$final_status" "$attempt" "$final_pass_rate"
    
    # Final log
    log_heal "=== E2E Self-Healing Completed ==="
    log_heal "Final Status: $final_status"
    log_heal "Final Pass Rate: ${final_pass_rate}%"
    log_heal "Total Attempts: $attempt"
    
    # Exit with appropriate code
    if [ "$final_status" = "passed" ]; then
        exit 0
    else
        exit 1
    fi
}

# Run main function
main "$@"