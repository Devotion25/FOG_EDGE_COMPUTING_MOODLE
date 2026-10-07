#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  INSTALL SYSTEMD SERVICES
# ═══════════════════════════════════════════════════════════════════════════════
#
#  This script installs all systemd services for the Moodle sync daemons.
#
#  Usage: sudo ./install-services.sh [VM_NAME]
#  Example: sudo ./install-services.sh VM1
#
# ═══════════════════════════════════════════════════════════════════════════════

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Please run as root (sudo)${NC}"
    exit 1
fi

# Get VM name
VM_NAME=${1:-VM1}
echo "═══════════════════════════════════════════════════════════════════"
echo "  📦 INSTALLING SYSTEMD SERVICES FOR $VM_NAME"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

# Create log directory
echo "📁 Creating log directory..."
mkdir -p /var/log
touch /var/log/yjs-server.log
touch /var/log/moodle-sender.log
touch /var/log/moodle-receiver.log
chown devo2024:devo2024 /var/log/yjs-server.log /var/log/moodle-sender.log /var/log/moodle-receiver.log
echo -e "${GREEN}✅ Log directory ready${NC}"
echo ""

# Copy service files
echo "📋 Copying service files..."
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

cp "$SCRIPT_DIR/moodle-sender.service" /etc/systemd/system/
cp "$SCRIPT_DIR/moodle-receiver.service" /etc/systemd/system/

if [ "$VM_NAME" == "VM1" ]; then
    cp "$SCRIPT_DIR/yjs-server.service" /etc/systemd/system/
    echo -e "${GREEN}✅ Copied yjs-server.service (VM1 only)${NC}"
fi

echo -e "${GREEN}✅ Copied moodle-sender.service${NC}"
echo -e "${GREEN}✅ Copied moodle-receiver.service${NC}"
echo ""

# Reload systemd
echo "🔄 Reloading systemd..."
systemctl daemon-reload
echo -e "${GREEN}✅ Systemd reloaded${NC}"
echo ""

# Enable services
echo "⚡ Enabling services..."
if [ "$VM_NAME" == "VM1" ]; then
    systemctl enable yjs-server
    echo -e "${GREEN}✅ Enabled yjs-server${NC}"
fi
systemctl enable moodle-sender
systemctl enable moodle-receiver
echo -e "${GREEN}✅ Enabled moodle-sender${NC}"
echo -e "${GREEN}✅ Enabled moodle-receiver${NC}"
echo ""

# Start services
echo "🚀 Starting services..."
if [ "$VM_NAME" == "VM1" ]; then
    systemctl start yjs-server
    echo -e "${GREEN}✅ Started yjs-server${NC}"
fi
systemctl start moodle-sender
systemctl start moodle-receiver
echo -e "${GREEN}✅ Started moodle-sender${NC}"
echo -e "${GREEN}✅ Started moodle-receiver${NC}"
echo ""

# Show status
echo "═══════════════════════════════════════════════════════════════════"
echo "  📊 SERVICE STATUS"
echo "═══════════════════════════════════════════════════════════════════"
echo ""

if [ "$VM_NAME" == "VM1" ]; then
    echo "Yjs Server:"
    systemctl status yjs-server --no-pager | head -5
    echo ""
fi

echo "Moodle Sender:"
systemctl status moodle-sender --no-pager | head -5
echo ""

echo "Moodle Receiver:"
systemctl status moodle-receiver --no-pager | head -5
echo ""

echo "═══════════════════════════════════════════════════════════════════"
echo -e "  ${GREEN}✅ Installation complete!${NC}"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "  Useful commands:"
echo "    sudo systemctl status yjs-server"
echo "    sudo systemctl status moodle-sender"
echo "    sudo systemctl status moodle-receiver"
echo "    sudo journalctl -u moodle-sender -f"
echo "    tail -f /var/log/moodle-sender.log"
echo ""



