#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO: Show All Synced Tables Summary
# ═══════════════════════════════════════════════════════════════════════════════

DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  📊 MOODLE SYNC - ALL TABLES OVERVIEW${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

echo -e "${CYAN}📚 COURSES:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_course;" 2>/dev/null | xargs -I{} echo "   Total: {} courses"

echo ""
echo -e "${CYAN}📝 QUIZZES:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_quiz;" 2>/dev/null | xargs -I{} echo "   Total: {} quizzes"

echo ""
echo -e "${CYAN}📄 ASSIGNMENTS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_assign;" 2>/dev/null | xargs -I{} echo "   Total: {} assignments"

echo ""
echo -e "${CYAN}❓ QUESTIONS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_question WHERE parent = 0;" 2>/dev/null | xargs -I{} echo "   Total: {} questions"

echo ""
echo -e "${CYAN}📊 GRADE ITEMS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_grade_items;" 2>/dev/null | xargs -I{} echo "   Total: {} grade items"

echo ""
echo -e "${CYAN}🎯 STUDENT GRADES:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_grade_grades;" 2>/dev/null | xargs -I{} echo "   Total: {} grades"

echo ""
echo -e "${CYAN}💬 FORUMS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_forum;" 2>/dev/null | xargs -I{} echo "   Total: {} forums"

echo ""
echo -e "${CYAN}🔧 COURSE MODULES:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_course_modules;" 2>/dev/null | xargs -I{} echo "   Total: {} modules"

echo ""
echo -e "${CYAN}👥 USER ENROLLMENTS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -N -e "SELECT COUNT(*) FROM mdl_user_enrolments;" 2>/dev/null | xargs -I{} echo "   Total: {} enrollments"

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

echo -e "${YELLOW}📋 SYNC QUEUE STATUS:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT 
    table_name,
    COUNT(*) as total,
    SUM(CASE WHEN synced = 0 THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN synced = 1 THEN 1 ELSE 0 END) as synced
FROM mdl_yjs_sync_queue
GROUP BY table_name
ORDER BY table_name;
" 2>/dev/null

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

echo -e "${YELLOW}🕐 RECENT SYNC ACTIVITY:${NC}"
mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
SELECT 
    table_name,
    operation,
    record_id,
    source_vm,
    IF(synced=1,'✅','⏳') as status,
    FROM_UNIXTIME(timestamp) as time
FROM mdl_yjs_sync_queue
ORDER BY id DESC
LIMIT 10;
" 2>/dev/null

echo ""

