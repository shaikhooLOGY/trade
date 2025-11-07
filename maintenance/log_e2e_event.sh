#!/bin/bash
# E2E Event Logger - Log E2E events to agent_logs
# Usage: bash maintenance/log_e2e_event.sh "EVENT_TYPE" "MESSAGE" "LEVEL"

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8082}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get parameters
EVENT_TYPE="${1:-E2E_SCHEDULED_RUN}"
MESSAGE="${2:-E2E scheduled run started}"
LOG_LEVEL="${3:-INFO}"

echo -e "${BLUE}📝 E2E Event Logger${NC}"
echo "Event: $EVENT_TYPE"
echo "Message: $MESSAGE"
echo "Level: $LOG_LEVEL"
echo ""

# Prepare context data
CONTEXT_DATA=$(cat << EOF
{
  "event_type": "$EVENT_TYPE",
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "base_url": "$BASE_URL",
  "git_sha": "${GITHUB_SHA:-local}",
  "workflow_run": "${GITHUB_RUN_ID:-local}",
  "trigger": "${GITHUB_EVENT_NAME:-manual}"
}
EOF
)

# Make API call to log the event
if command -v curl >/dev/null 2>&1; then
    echo -e "${YELLOW}📡 Logging event via API...${NC}"
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/api/agent/log.php" \
        -H "Content-Type: application/json" \
        -H "X-Requested-With: XMLHttpRequest" \
        -d "{
            \"agent_type\": \"e2e_ci\",
            \"agent_id\": \"e2e_ci_$(date +%s)\",
            \"log_level\": \"$LOG_LEVEL\",
            \"message\": \"$MESSAGE\",
            \"context_data\": $CONTEXT_DATA
        }" || echo '{"success": false, "error": "curl_failed"}')
    
    if echo "$RESPONSE" | grep -q '"success":true'; then
        echo -e "${GREEN}✅ Event logged successfully${NC}"
    else
        echo -e "${YELLOW}⚠️ Event logging may have failed: $RESPONSE${NC}"
    fi
else
    echo -e "${RED}❌ curl not available, skipping API logging${NC}"
fi

# Also log to file for backup
LOG_FILE="$PROJECT_DIR/logs/e2e_events_$(date +%Y%m%d).log"
mkdir -p "$(dirname "$LOG_FILE")"

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] [$LOG_LEVEL] [$EVENT_TYPE] $MESSAGE" >> "$LOG_FILE"
echo -e "${GREEN}📄 Event also logged to file: $LOG_FILE${NC}"