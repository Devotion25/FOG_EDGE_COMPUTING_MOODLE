#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO GRADE SYNC SCRIPT
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
echo -e "${BLUE}  📊 GRADE SYNCHRONIZATION DEMO${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

case $ACTION in
    items)
        echo -e "${YELLOW}📋 Grade Items:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule,
               gi.grademax, gi.grademin, gi.gradepass,
               c.shortname as course
        FROM mdl_grade_items gi
        LEFT JOIN mdl_course c ON gi.courseid = c.id
        WHERE gi.itemname IS NOT NULL
        ORDER BY gi.courseid, gi.sortorder
        LIMIT 20;
        " 2>/dev/null
        ;;
        
    grades)
        ITEM_ID=${2:-1}
        echo -e "${YELLOW}📋 Student Grades for Item $ITEM_ID:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT gg.id, u.username, gg.rawgrade, gg.finalgrade,
               gi.itemname, gi.grademax
        FROM mdl_grade_grades gg
        JOIN mdl_user u ON gg.userid = u.id
        JOIN mdl_grade_items gi ON gg.itemid = gi.id
        WHERE gg.itemid = $ITEM_ID
        ORDER BY u.lastname;
        " 2>/dev/null
        ;;
        
    update)
        GRADE_ID=${2:-1}
        NEW_GRADE=${3:-85.5}
        
        echo -e "${GREEN}📝 Updating grade ID $GRADE_ID to $NEW_GRADE...${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        UPDATE mdl_grade_grades SET 
            rawgrade = $NEW_GRADE,
            finalgrade = $NEW_GRADE,
            timemodified = $TIMESTAMP
        WHERE id = $GRADE_ID;
        " 2>/dev/null
        echo -e "${GREEN}✅ Grade updated!${NC}"
        ;;
        
    list|*)
        echo -e "${YELLOW}📋 Recent Grade Changes:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT gg.id, u.username, gi.itemname, 
               gg.rawgrade, gg.finalgrade,
               FROM_UNIXTIME(gg.timemodified) as modified
        FROM mdl_grade_grades gg
        JOIN mdl_user u ON gg.userid = u.id
        JOIN mdl_grade_items gi ON gg.itemid = gi.id
        ORDER BY gg.timemodified DESC
        LIMIT 15;
        " 2>/dev/null
        
        echo ""
        echo "Usage: ./demo-grade-sync.sh [items|grades|update|list] [options]"
        echo "  items           - Show grade items"
        echo "  grades <item>   - Show grades for an item"
        echo "  update <id> <g> - Update grade"
        ;;
esac

echo ""

