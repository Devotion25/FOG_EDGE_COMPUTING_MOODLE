#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO VERIFY SYNC SCRIPT
#  Check if a course has been synchronized
# ═══════════════════════════════════════════════════════════════════════════════
#
#  Usage: ./demo-verify-sync.sh [course_id]
#  Example: ./demo-verify-sync.sh 2
#
# ═══════════════════════════════════════════════════════════════════════════════

# Configuration
DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"

COURSE_ID=${1:-2}

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  🔍 VERIFYING SYNCHRONIZATION${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

# Show current state
echo -e "${GREEN}📋 Current Course State:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT 
    id,
    shortname,
    fullname,
    FROM_UNIXTIME(timemodified) as last_modified
FROM mdl_course 
WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""

# Show recent sync queue entries
echo -e "${GREEN}📋 Recent Sync Queue Entries:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT 
    id,
    operation,
    record_id,
    source_vm,
    IF(synced=1,'✅ Synced','⏳ Pending') as status,
    FROM_UNIXTIME(timestamp) as change_time
FROM mdl_yjs_sync_queue 
WHERE record_id = $COURSE_ID
ORDER BY id DESC 
LIMIT 5;
" 2>/dev/null

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""



