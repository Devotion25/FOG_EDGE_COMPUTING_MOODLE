#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  TEST: Concurrent Updates from Multiple VMs
# ═══════════════════════════════════════════════════════════════════════════════
#
#  This script simulates concurrent updates from multiple VMs and verifies
#  that the Last-Write-Wins conflict resolution works correctly.
#
#  Prerequisites:
#  - SSH access to all VMs
#  - MySQL credentials configured
#
#  Usage: ./test-concurrent.sh
#
# ═══════════════════════════════════════════════════════════════════════════════

# Configuration
VM1_IP="172.20.10.4"
VM2_IP="172.20.10.5"
VM3_IP="172.20.10.6"
DB_USER="Fog_computing"
DB_PASS="Live4you@"
DB_NAME="moodle"
TEST_COURSE_ID=2

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "═══════════════════════════════════════════════════════════════════"
echo "  🧪 CONCURRENT UPDATE TEST"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Function to run MySQL query on a VM
run_mysql() {
    local vm_ip=$1
    local query=$2
    ssh devo2024@$vm_ip "mysql -u$DB_USER -p'$DB_PASS' $DB_NAME -e \"$query\" 2>/dev/null"
}

# Function to get course name from a VM
get_course_name() {
    local vm_ip=$1
    run_mysql $vm_ip "SELECT fullname FROM mdl_course WHERE id=$TEST_COURSE_ID" | tail -n 1
}

# Test 1: Read initial state from all VMs
echo -e "${BLUE}📋 Test 1: Reading initial state from all VMs...${NC}"
echo ""

INITIAL_VM1=$(get_course_name $VM1_IP)
INITIAL_VM2=$(get_course_name $VM2_IP)
INITIAL_VM3=$(get_course_name $VM3_IP)

echo "   VM1 ($VM1_IP): $INITIAL_VM1"
echo "   VM2 ($VM2_IP): $INITIAL_VM2"
echo "   VM3 ($VM3_IP): $INITIAL_VM3"

if [ "$INITIAL_VM1" == "$INITIAL_VM2" ] && [ "$INITIAL_VM2" == "$INITIAL_VM3" ]; then
    echo -e "${GREEN}✅ All VMs have consistent data${NC}"
else
    echo -e "${YELLOW}⚠️ VMs have inconsistent data - waiting for sync...${NC}"
    sleep 5
fi
echo ""

# Test 2: Make concurrent updates
echo -e "${BLUE}📋 Test 2: Making concurrent updates on VM1 and VM2...${NC}"
echo ""

TIMESTAMP=$(date +%s)
VM1_NAME="VM1_Update_$TIMESTAMP"
VM2_NAME="VM2_Update_$((TIMESTAMP + 1))"  # Slightly later timestamp

echo "   VM1 will set: $VM1_NAME (timestamp: $TIMESTAMP)"
echo "   VM2 will set: $VM2_NAME (timestamp: $((TIMESTAMP + 1)))"
echo ""

# Update on VM1
echo "   Updating VM1..."
run_mysql $VM1_IP "UPDATE mdl_course SET fullname='$VM1_NAME', timemodified=$TIMESTAMP WHERE id=$TEST_COURSE_ID"

# Small delay, then update on VM2 with later timestamp
sleep 0.5
echo "   Updating VM2..."
run_mysql $VM2_IP "UPDATE mdl_course SET fullname='$VM2_NAME', timemodified=$((TIMESTAMP + 1)) WHERE id=$TEST_COURSE_ID"

echo -e "${GREEN}✅ Updates sent${NC}"
echo ""

# Test 3: Wait for synchronization
echo -e "${BLUE}📋 Test 3: Waiting for synchronization (10 seconds)...${NC}"
for i in {10..1}; do
    echo -n "   $i..."
    sleep 1
done
echo " Done!"
echo ""

# Test 4: Check final state
echo -e "${BLUE}📋 Test 4: Checking final state on all VMs...${NC}"
echo ""

FINAL_VM1=$(get_course_name $VM1_IP)
FINAL_VM2=$(get_course_name $VM2_IP)
FINAL_VM3=$(get_course_name $VM3_IP)

echo "   VM1 ($VM1_IP): $FINAL_VM1"
echo "   VM2 ($VM2_IP): $FINAL_VM2"
echo "   VM3 ($VM3_IP): $FINAL_VM3"
echo ""

# Test 5: Verify consistency
echo -e "${BLUE}📋 Test 5: Verifying consistency...${NC}"
echo ""

if [ "$FINAL_VM1" == "$FINAL_VM2" ] && [ "$FINAL_VM2" == "$FINAL_VM3" ]; then
    echo -e "${GREEN}✅ All VMs converged to the same value${NC}"
    
    # Check if the winner is correct (VM2 should win due to higher timestamp)
    if [ "$FINAL_VM1" == "$VM2_NAME" ]; then
        echo -e "${GREEN}✅ Last-Write-Wins working correctly (VM2's update won)${NC}"
        TESTS_PASSED=true
    else
        echo -e "${YELLOW}⚠️ Unexpected winner: $FINAL_VM1${NC}"
        TESTS_PASSED=false
    fi
else
    echo -e "${RED}❌ VMs have inconsistent data!${NC}"
    TESTS_PASSED=false
fi
echo ""

# Test 6: Restore original name
echo -e "${BLUE}📋 Test 6: Restoring original course name...${NC}"
run_mysql $VM1_IP "UPDATE mdl_course SET fullname='$INITIAL_VM1', timemodified=$((TIMESTAMP + 10)) WHERE id=$TEST_COURSE_ID"
echo -e "${GREEN}✅ Original name restored${NC}"
echo ""

# Print results
echo "═══════════════════════════════════════════════════════════════════"
echo "  📊 TEST RESULTS"
echo "═══════════════════════════════════════════════════════════════════"

if [ "$TESTS_PASSED" = true ]; then
    echo -e "  ${GREEN}✅ All tests PASSED${NC}"
    echo ""
    echo "  Concurrent update handling verified:"
    echo "  • Both VMs successfully made updates"
    echo "  • Changes propagated to all VMs"
    echo "  • Last-Write-Wins resolved the conflict"
    echo "  • All VMs converged to consistent state"
    EXIT_CODE=0
else
    echo -e "  ${RED}❌ Some tests FAILED${NC}"
    echo ""
    echo "  Check:"
    echo "  • Are all sync daemons running?"
    echo "  • Is the Yjs server accessible?"
    echo "  • Are the triggers installed?"
    EXIT_CODE=1
fi

echo "═══════════════════════════════════════════════════════════════════"

exit $EXIT_CODE



