#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO CONFLICT TEST SCRIPT
#  Simulate a conflict for Last-Write-Wins demonstration
# ═══════════════════════════════════════════════════════════════════════════════
#
#  Run this on VM1, then immediately run on VM2
#  The VM with the later timestamp wins
#
#  Usage: ./demo-conflict-test.sh [VM_NAME]
#  Example: ./demo-conflict-test.sh VM1
#
# ═══════════════════════════════════════════════════════════════════════════════

# Configuration
DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"
COURSE_ID=4

VM_NAME=${1:-VM1}
TIMESTAMP=$(date +%s)

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  ⚔️ CONFLICT TEST - $VM_NAME${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

# Show before
echo -e "${YELLOW}📋 BEFORE:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT id, fullname, timemodified FROM mdl_course WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""
echo -e "${GREEN}⚡ Making conflicting change from $VM_NAME...${NC}"
echo "   Timestamp: $TIMESTAMP"
echo ""

# Make change
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
UPDATE mdl_course 
SET fullname = 'CONFLICT: $VM_NAME wins (ts:$TIMESTAMP)',
    timemodified = $TIMESTAMP
WHERE id = $COURSE_ID;
" 2>/dev/null

echo -e "${GREEN}✅ Change made!${NC}"
echo ""
echo -e "${YELLOW}📋 AFTER (local):${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT id, fullname, timemodified FROM mdl_course WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Now run on the other VM to create a conflict!"
echo "  After 3 seconds, both VMs will converge to the winner."
echo ""
echo "  To check result: ./demo-verify-sync.sh $COURSE_ID"
echo ""



