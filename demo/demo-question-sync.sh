#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO QUESTION BANK SYNC SCRIPT
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
echo -e "${BLUE}  ❓ QUESTION BANK SYNCHRONIZATION DEMO${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

case $ACTION in
    list)
        echo -e "${YELLOW}📋 Questions in Bank:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT q.id, q.name, q.qtype, q.defaultmark,
               qc.name as category
        FROM mdl_question q
        LEFT JOIN mdl_question_categories qc ON q.category = qc.id
        WHERE q.parent = 0
        ORDER BY q.id DESC
        LIMIT 20;
        " 2>/dev/null
        ;;
        
    update)
        QUESTION_ID=${2:-1}
        NEW_NAME="Updated Question $(date +%H:%M:%S)"
        
        echo -e "${GREEN}📝 Updating question $QUESTION_ID...${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        UPDATE mdl_question SET 
            name = '$NEW_NAME',
            timemodified = $TIMESTAMP
        WHERE id = $QUESTION_ID;
        " 2>/dev/null
        echo -e "${GREEN}✅ Question updated!${NC}"
        ;;
        
    categories)
        echo -e "${YELLOW}📋 Question Categories:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT qc.id, qc.name, qc.questioncount,
               ctx.contextlevel
        FROM mdl_question_categories qc
        LEFT JOIN mdl_context ctx ON qc.contextid = ctx.id
        ORDER BY qc.id;
        " 2>/dev/null
        ;;
        
    *)
        echo "Usage: ./demo-question-sync.sh [list|update|categories]"
        echo "  list              - Show questions"
        echo "  update <id>       - Update question name"
        echo "  categories        - Show question categories"
        ;;
esac

echo ""

