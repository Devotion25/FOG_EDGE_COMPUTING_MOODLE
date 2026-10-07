#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  DEMO SETUP SCRIPT
#  Quickly start all services for video demonstration
# ═══════════════════════════════════════════════════════════════════════════════
#
#  Usage: ./demo-setup.sh [VM_NAME]
#  Example: ./demo-setup.sh VM1
#
# ═══════════════════════════════════════════════════════════════════════════════

VM_NAME=${1:-VM1}
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CODE_DIR="$SCRIPT_DIR/../code"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  🎬 DEMO SETUP - $VM_NAME${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""

cd "$CODE_DIR" || exit 1

# Stop any existing processes
echo -e "${YELLOW}🛑 Stopping existing processes...${NC}"
pkill -f "yjs-server" 2>/dev/null
pkill -f "moodle-sync" 2>/dev/null
sleep 1

# Start Yjs server (VM1 only)
if [ "$VM_NAME" == "VM1" ]; then
    echo -e "${GREEN}🚀 Starting Yjs WebSocket Server...${NC}"
    node yjs-server.js > /tmp/yjs-server.log 2>&1 &
    sleep 2
    
    # Verify
    if curl -s http://localhost:2345/health > /dev/null; then
        echo -e "${GREEN}   ✅ Yjs server running on port 2345${NC}"
    else
        echo -e "${YELLOW}   ⚠️ Yjs server may not be running${NC}"
    fi
fi

# Start Sender
echo -e "${GREEN}🚀 Starting Sender Daemon...${NC}"
php moodle-sync-sender.php > /tmp/moodle-sender.log 2>&1 &
sleep 1
echo -e "${GREEN}   ✅ Sender daemon started${NC}"

# Start Receiver
echo -e "${GREEN}🚀 Starting Receiver Daemon...${NC}"
php moodle-sync-receiver.php > /tmp/moodle-receiver.log 2>&1 &
sleep 1
echo -e "${GREEN}   ✅ Receiver daemon started${NC}"

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✅ ALL SERVICES STARTED!${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Log files:"
if [ "$VM_NAME" == "VM1" ]; then
    echo "    Yjs Server: tail -f /tmp/yjs-server.log"
fi
echo "    Sender:     tail -f /tmp/moodle-sender.log"
echo "    Receiver:   tail -f /tmp/moodle-receiver.log"
echo ""
echo "  To watch all logs:"
echo "    tail -f /tmp/*.log"
echo ""



