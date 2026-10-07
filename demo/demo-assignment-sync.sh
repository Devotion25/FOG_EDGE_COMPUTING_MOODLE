#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO ASSIGNMENT SYNC SCRIPT
# ═══════════════════════════════════════════════════════════════════════════════

DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"

ACTION=${1:-list}
TIMESTAMP=$(date +%s)

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  📝 ASSIGNMENT SYNCHRONIZATION DEMO${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

case $ACTION in
    create)
        COURSE_ID=${2:-2}
        NAME="Sync Demo Assignment $(date +%H:%M:%S)"
        DUEDATE=$((TIMESTAMP + 604800))  # 1 week from now
        
        echo -e "${GREEN}📝 Creating assignment...${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        INSERT INTO mdl_assign (course, name, intro, introformat, duedate, 
            allowsubmissionsfromdate, cutoffdate, grade, timemodified)
        VALUES ($COURSE_ID, '$NAME', 'Demo assignment for sync testing', 1,
            $DUEDATE, $TIMESTAMP, $((DUEDATE + 86400)), 100, $TIMESTAMP);
        SELECT LAST_INSERT_ID() AS 'New Assignment ID';
        " 2>/dev/null
        echo -e "${GREEN}✅ Assignment created!${NC}"
        ;;
        
    update)
        ASSIGN_ID=${2:-1}
        NEW_NAME="Updated Assignment $(date +%H:%M:%S)"
        
        echo -e "${GREEN}📝 Updating assignment $ASSIGN_ID...${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        UPDATE mdl_assign SET name = '$NEW_NAME', 
            duedate = $((TIMESTAMP + 1209600)),
            timemodified = $TIMESTAMP
        WHERE id = $ASSIGN_ID;
        " 2>/dev/null
        echo -e "${GREEN}✅ Assignment updated!${NC}"
        ;;
        
    list)
        echo -e "${YELLOW}📋 All Assignments:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT a.id, a.name, c.shortname as course, 
               FROM_UNIXTIME(a.duedate) as due_date,
               a.grade
        FROM mdl_assign a
        LEFT JOIN mdl_course c ON a.course = c.id
        ORDER BY a.id;
        " 2>/dev/null
        ;;
        
    *)
        echo "Usage: ./demo-assignment-sync.sh [list|create|update] [options]"
        ;;
esac

echo ""

