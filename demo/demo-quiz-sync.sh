#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO QUIZ SYNC SCRIPT
#  Demonstrate quiz synchronization between VMs
# ═══════════════════════════════════════════════════════════════════════════════
#
#  Usage: ./demo-quiz-sync.sh [action]
#  Actions:
#    create  - Create a new quiz
#    update  - Update an existing quiz
#    list    - List all quizzes
#    verify  - Verify sync status
#
# ═══════════════════════════════════════════════════════════════════════════════

# Configuration
DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"
DEFAULT_COURSE_ID=2

ACTION=${1:-list}
TIMESTAMP=$(date +%s)

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  📝 QUIZ SYNCHRONIZATION DEMO${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

case $ACTION in
    create)
        QUIZ_NAME="Sync Demo Quiz $(date +%H:%M:%S)"
        COURSE_ID=${2:-$DEFAULT_COURSE_ID}
        
        echo -e "${CYAN}📝 Creating new quiz...${NC}"
        echo "   Name: $QUIZ_NAME"
        echo "   Course ID: $COURSE_ID"
        echo ""
        
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        INSERT INTO mdl_quiz (
            course, name, intro, introformat,
            timeopen, timeclose, timelimit,
            preferredbehaviour, attempts, grademethod,
            questionsperpage, shuffleanswers, grade,
            timecreated, timemodified
        ) VALUES (
            $COURSE_ID,
            '$QUIZ_NAME',
            'This quiz was created to demonstrate synchronization.',
            1,
            $TIMESTAMP,
            $((TIMESTAMP + 604800)),
            3600,
            'deferredfeedback',
            3,
            1,
            1,
            1,
            100,
            $TIMESTAMP,
            $TIMESTAMP
        );
        SELECT LAST_INSERT_ID() AS 'New Quiz ID';
        " 2>/dev/null
        
        echo ""
        echo -e "${GREEN}✅ Quiz created! Check other VM for synchronization.${NC}"
        ;;
        
    update)
        QUIZ_ID=${2:-1}
        NEW_NAME="Updated Quiz $(date +%H:%M:%S)"
        
        echo -e "${CYAN}📝 Updating quiz ID: $QUIZ_ID...${NC}"
        echo "   New name: $NEW_NAME"
        echo ""
        
        # Show before
        echo -e "${YELLOW}BEFORE:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT id, name, timelimit, grade, 
               FROM_UNIXTIME(timeopen) as opens,
               FROM_UNIXTIME(timeclose) as closes
        FROM mdl_quiz WHERE id = $QUIZ_ID;
        " 2>/dev/null
        
        # Make update
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        UPDATE mdl_quiz SET 
            name = '$NEW_NAME',
            timelimit = 7200,
            timemodified = $TIMESTAMP
        WHERE id = $QUIZ_ID;
        " 2>/dev/null
        
        echo ""
        echo -e "${YELLOW}AFTER:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT id, name, timelimit, grade,
               FROM_UNIXTIME(timeopen) as opens,
               FROM_UNIXTIME(timeclose) as closes
        FROM mdl_quiz WHERE id = $QUIZ_ID;
        " 2>/dev/null
        
        echo ""
        echo -e "${GREEN}✅ Quiz updated! Check other VM for synchronization.${NC}"
        ;;
        
    list)
        echo -e "${CYAN}📋 Listing all quizzes...${NC}"
        echo ""
        
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT 
            q.id,
            q.name,
            c.shortname AS course,
            q.timelimit DIV 60 AS 'time_mins',
            q.attempts,
            q.grade,
            FROM_UNIXTIME(q.timeopen) AS opens,
            FROM_UNIXTIME(q.timeclose) AS closes
        FROM mdl_quiz q
        LEFT JOIN mdl_course c ON q.course = c.id
        ORDER BY q.id;
        " 2>/dev/null
        ;;
        
    verify)
        QUIZ_ID=${2:-1}
        
        echo -e "${CYAN}🔍 Verifying sync status for quiz ID: $QUIZ_ID${NC}"
        echo ""
        
        # Show quiz
        echo -e "${YELLOW}Quiz Details:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT id, name, course, timelimit, grade, timemodified
        FROM mdl_quiz WHERE id = $QUIZ_ID;
        " 2>/dev/null
        
        echo ""
        
        # Show sync queue entries
        echo -e "${YELLOW}Recent Sync Queue Entries for Quiz:${NC}"
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        SELECT 
            id,
            operation,
            source_vm,
            IF(synced=1,'✅ Synced','⏳ Pending') as status,
            FROM_UNIXTIME(timestamp) as change_time
        FROM mdl_yjs_sync_queue 
        WHERE table_name = 'mdl_quiz' AND record_id = $QUIZ_ID
        ORDER BY id DESC 
        LIMIT 5;
        " 2>/dev/null
        ;;
        
    delete)
        QUIZ_ID=${2:-0}
        
        if [ "$QUIZ_ID" == "0" ]; then
            echo -e "${YELLOW}⚠️ Please specify quiz ID: ./demo-quiz-sync.sh delete <quiz_id>${NC}"
            exit 1
        fi
        
        echo -e "${CYAN}🗑️ Deleting quiz ID: $QUIZ_ID...${NC}"
        
        mysql -u$DB_USER -p"$DB_PASS" $DB_NAME -e "
        DELETE FROM mdl_quiz WHERE id = $QUIZ_ID;
        SELECT ROW_COUNT() AS 'Rows Deleted';
        " 2>/dev/null
        
        echo ""
        echo -e "${GREEN}✅ Quiz deleted! Check other VM for synchronization.${NC}"
        ;;
        
    *)
        echo "Usage: ./demo-quiz-sync.sh [action] [options]"
        echo ""
        echo "Actions:"
        echo "  list              - List all quizzes"
        echo "  create [course]   - Create a new quiz in course (default: $DEFAULT_COURSE_ID)"
        echo "  update <quiz_id>  - Update quiz settings"
        echo "  verify <quiz_id>  - Verify sync status"
        echo "  delete <quiz_id>  - Delete a quiz"
        echo ""
        echo "Examples:"
        echo "  ./demo-quiz-sync.sh list"
        echo "  ./demo-quiz-sync.sh create 2"
        echo "  ./demo-quiz-sync.sh update 5"
        echo "  ./demo-quiz-sync.sh verify 5"
        ;;
esac

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

