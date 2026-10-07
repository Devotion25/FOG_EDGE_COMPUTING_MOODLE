#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO STATUS SCRIPT
#  Show current system status for demonstration
# ═══════════════════════════════════════════════════════════════════════════════

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  📊 SYSTEM STATUS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

# Check processes
echo -e "${YELLOW}🔧 Running Processes:${NC}"
echo ""

# Yjs Server
if pgrep -f "yjs-server" > /dev/null; then
    echo -e "  Yjs Server:     ${GREEN}✅ Running${NC}"
else
    echo -e "  Yjs Server:     ${RED}❌ Not running${NC}"
fi

# Sender
if pgrep -f "moodle-sync-sender" > /dev/null; then
    echo -e "  Sender Daemon:  ${GREEN}✅ Running${NC}"
else
    echo -e "  Sender Daemon:  ${RED}❌ Not running${NC}"
fi

# Receiver
if pgrep -f "moodle-sync-receiver" > /dev/null; then
    echo -e "  Receiver Daemon:${GREEN}✅ Running${NC}"
else
    echo -e "  Receiver Daemon:${RED}❌ Not running${NC}"
fi

echo ""

# Try to get Yjs stats
echo -e "${YELLOW}📡 Yjs Server Stats:${NC}"
if curl -s http://localhost:2345/stats > /dev/null 2>&1; then
    curl -s http://localhost:2345/stats | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    print(f\"  Uptime:      {data.get('uptime', 'N/A')}\")
    print(f\"  Connections: {data.get('connections', {}).get('active', 'N/A')}\")
    print(f\"  Messages:    {data.get('messages', {}).get('received', 'N/A')}\")
    changes = data.get('databaseChanges', {})
    print(f\"  DB Changes:  {changes.get('total', 'N/A')}\")
except:
    print('  Unable to parse stats')
" 2>/dev/null || echo "  Unable to connect to Yjs server"
else
    echo "  Yjs server not accessible (might be on different VM)"
fi

echo ""

# Database status
echo -e "${YELLOW}🗄️ Database Queue:${NC}"
mysql -u Fog_computing -p'Live4you@' moodle -N -e "
SELECT 
    CONCAT('  Pending: ', COUNT(*)) 
FROM mdl_yjs_sync_queue WHERE synced = 0;
SELECT 
    CONCAT('  Synced:  ', COUNT(*)) 
FROM mdl_yjs_sync_queue WHERE synced = 1;
SELECT 
    CONCAT('  Total:   ', COUNT(*)) 
FROM mdl_yjs_sync_queue;
" 2>/dev/null || echo "  Unable to query database"

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""



