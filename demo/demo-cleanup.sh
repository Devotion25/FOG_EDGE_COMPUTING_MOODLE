#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO CLEANUP SCRIPT
#  Stop all services after demonstration
# ═══════════════════════════════════════════════════════════════════════════════

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "  🛑 STOPPING DEMO SERVICES"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Stop Yjs server
if pgrep -f "yjs-server" > /dev/null; then
    echo "Stopping Yjs server..."
    pkill -f "yjs-server"
    echo -e "${GREEN}✅ Yjs server stopped${NC}"
else
    echo "Yjs server not running"
fi

# Stop PHP daemons
if pgrep -f "moodle-sync" > /dev/null; then
    echo "Stopping PHP daemons..."
    pkill -f "moodle-sync"
    echo -e "${GREEN}✅ PHP daemons stopped${NC}"
else
    echo "PHP daemons not running"
fi

echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo -e "${GREEN}  ✅ ALL SERVICES STOPPED${NC}"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Show process status
echo "Verification (should be empty):"
ps aux | grep -E "(yjs-server|moodle-sync)" | grep -v grep || echo "  No sync processes running"
echo ""



