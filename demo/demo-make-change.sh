#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO MAKE CHANGE SCRIPT
#  Make a database change for demonstration
# ═══════════════════════════════════════════════════════════════════════════════
#
#  Usage: ./demo-make-change.sh [course_id] [new_name]
#  Example: ./demo-make-change.sh 2 "My New Course Name"
#
# ═══════════════════════════════════════════════════════════════════════════════

# Configuration
DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"

# Parameters
COURSE_ID=${1:-2}
TIMESTAMP=$(date +%H:%M:%S)
NEW_NAME=${2:-"SYNC Demo Course $TIMESTAMP"}

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  📝 MAKING DATABASE CHANGE${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

# Show before
echo -e "${YELLOW}📋 BEFORE:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT id, shortname, fullname, FROM_UNIXTIME(timemodified) as modified 
FROM mdl_course WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""

# Make change
echo -e "${GREEN}⚡ Making change...${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
UPDATE mdl_course 
SET fullname = '$NEW_NAME',
    timemodified = UNIX_TIMESTAMP()
WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""

# Show after
echo -e "${YELLOW}📋 AFTER:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT id, shortname, fullname, FROM_UNIXTIME(timemodified) as modified 
FROM mdl_course WHERE id = $COURSE_ID;
" 2>/dev/null

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✅ Change made! Check other VM for synchronization.${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""



