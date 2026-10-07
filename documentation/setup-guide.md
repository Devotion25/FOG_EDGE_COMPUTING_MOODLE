# 📚 Setup Guide - Distributed Moodle Database Synchronization

> Complete installation and configuration guide for all three VMs

---

## 📋 Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [VM1 Setup (Master)](#2-vm1-setup-master)
3. [VM2 & VM3 Setup (Replicas)](#3-vm2--vm3-setup-replicas)
4. [Database Configuration](#4-database-configuration)
5. [Starting the Services](#5-starting-the-services)
6. [Verification](#6-verification)
7. [Systemd Services](#7-systemd-services-production)
8. [Troubleshooting](#8-troubleshooting)

---

## 1. Prerequisites

### Hardware Requirements (per VM)
- **CPU:** 2+ cores
- **RAM:** 4GB minimum
- **Storage:** 20GB+
- **Network:** Bridged adapter (same subnet)

### Software Requirements
- Ubuntu 22.04 LTS (or compatible)
- MySQL 8.x
- PHP 8.x with mysqli extension
- Node.js 18+ (VM1 only)
- Apache 2.4
- Moodle 4.x installed

### Network Configuration
| VM | IP Address | Role |
|----|------------|------|
| VM1 | 172.20.10.4 | Master (Yjs Server, HAProxy) |
| VM2 | 172.20.10.5 | Replica |
| VM3 | 172.20.10.6 | Replica |

---

## 2. VM1 Setup (Master)

### 2.1 Install Node.js

```bash
# Update package list
sudo apt update

# Install Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Verify installation
node --version  # Should show v20.x.x
npm --version
```

### 2.2 Setup Yjs Server

```bash
# Create project directory
mkdir -p /home/devo2024/yjs-moodle-sync
cd /home/devo2024/yjs-moodle-sync

# Copy package.json
cp /path/to/project-submission/code/package.json .

# Install dependencies
npm install

# Copy server file
cp /path/to/project-submission/code/yjs-server.js .
```

### 2.3 Install PHP WebSocket Library

```bash
# Create PHP daemon directory
mkdir -p /home/devo2024/moodle-sync
cd /home/devo2024/moodle-sync

# Install Composer if not present
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Copy composer.json
cp /path/to/project-submission/code/composer.json .

# Install dependencies
composer install

# Copy PHP files
cp /path/to/project-submission/code/moodle-sync-sender.php .
cp /path/to/project-submission/code/moodle-sync-receiver.php .
```

### 2.4 Configure PHP Files for VM1

Edit `moodle-sync-sender.php`:
```php
$config = [
    'VM_NAME' => 'VM1',  // <-- Set to VM1
    // ... rest of config
];
```

Edit `moodle-sync-receiver.php`:
```php
$config = [
    'VM_NAME' => 'VM1',  // <-- Set to VM1
    // ... rest of config
];
```

### 2.5 Install HAProxy (Optional)

```bash
# Install HAProxy
sudo apt install -y haproxy

# Backup original config
sudo cp /etc/haproxy/haproxy.cfg /etc/haproxy/haproxy.cfg.backup

# Copy new config
sudo cp /path/to/project-submission/code/haproxy.cfg /etc/haproxy/

# Test configuration
sudo haproxy -c -f /etc/haproxy/haproxy.cfg

# Restart HAProxy
sudo systemctl restart haproxy
sudo systemctl enable haproxy
```

---

## 3. VM2 & VM3 Setup (Replicas)

Repeat for both VM2 (172.20.10.5) and VM3 (172.20.10.6):

### 3.1 Install PHP WebSocket Library

```bash
# Create directory
mkdir -p /home/devo2024/moodle-sync
cd /home/devo2024/moodle-sync

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Copy and install
cp /path/to/project-submission/code/composer.json .
composer install

# Copy PHP daemons
cp /path/to/project-submission/code/moodle-sync-sender.php .
cp /path/to/project-submission/code/moodle-sync-receiver.php .
```

### 3.2 Configure for VM2

Edit both PHP files and set:
```php
$config = [
    'VM_NAME' => 'VM2',  // <-- Set to VM2
    'YJS_SERVER' => 'ws://172.20.10.4:2345',  // Points to VM1
    // ... rest of config
];
```

### 3.3 Configure for VM3

Edit both PHP files and set:
```php
$config = [
    'VM_NAME' => 'VM3',  // <-- Set to VM3
    'YJS_SERVER' => 'ws://172.20.10.4:2345',  // Points to VM1
    // ... rest of config
];
```

---

## 4. Database Configuration

### 4.1 Run on ALL VMs

```bash
# Connect to MySQL
mysql -u root -p

# Select Moodle database
USE moodle;

# Set VM name (IMPORTANT: Change for each VM!)
SET @VM_NAME = 'VM1';  -- Use 'VM2' or 'VM3' on other VMs

# Run the trigger script
SOURCE /path/to/project-submission/code/trigger.sql;
```

### 4.2 Alternative: Run SQL Directly

**For VM1:**
```sql
USE moodle;
SET @VM_NAME = 'VM1';

-- Create sync queue table
CREATE TABLE IF NOT EXISTS mdl_yjs_sync_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(64) NOT NULL,
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    data_json LONGTEXT NOT NULL,
    timestamp BIGINT UNSIGNED NOT NULL,
    source_vm VARCHAR(20) NOT NULL,
    synced TINYINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_synced (synced),
    INDEX idx_source_vm (source_vm),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create trigger
DELIMITER //
CREATE TRIGGER sync_course_update
AFTER UPDATE ON mdl_course
FOR EACH ROW
BEGIN
    IF OLD.fullname != NEW.fullname OR OLD.shortname != NEW.shortname THEN
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_course', 'UPDATE', NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'fullname', NEW.fullname,
                'shortname', NEW.shortname,
                'timemodified', NEW.timemodified
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //
DELIMITER ;
```

### 4.3 Verify Triggers

```sql
-- Check triggers exist
SHOW TRIGGERS LIKE 'mdl_course';

-- Check sync queue table
DESCRIBE mdl_yjs_sync_queue;
```

---

## 5. Starting the Services

### 5.1 Start Yjs Server (VM1 Only)

```bash
cd /home/devo2024/yjs-moodle-sync

# Start in foreground (for testing)
node yjs-server.js

# Or start in background
nohup node yjs-server.js > yjs-server.log 2>&1 &
```

Expected output:
```
═══════════════════════════════════════════════════════════════════
  🚀 YJS MOODLE SYNC SERVER
═══════════════════════════════════════════════════════════════════
  📡 WebSocket: ws://0.0.0.0:2345
  🔍 Health:    http://0.0.0.0:2345/health
  📊 Stats:     http://0.0.0.0:2345/stats
═══════════════════════════════════════════════════════════════════
```

### 5.2 Start PHP Daemons (All VMs)

```bash
cd /home/devo2024/moodle-sync

# Start Sender daemon
nohup php moodle-sync-sender.php > sender.log 2>&1 &

# Start Receiver daemon
nohup php moodle-sync-receiver.php > receiver.log 2>&1 &
```

### 5.3 Verify Processes

```bash
# Check running processes
ps aux | grep -E "(yjs-server|moodle-sync)"

# Check logs
tail -f sender.log
tail -f receiver.log
```

---

## 6. Verification

### 6.1 Test WebSocket Connection

```bash
# On any VM, test connection to Yjs server
curl http://172.20.10.4:2345/health
```

Expected response:
```json
{"status":"healthy","uptime":"0h 5m 23s","connections":{"total":4,"active":4}}
```

### 6.2 Test Database Sync

**On VM1:**
```sql
-- Make a change
UPDATE mdl_course SET fullname = 'Test Sync Course' WHERE id = 2;

-- Check queue
SELECT * FROM mdl_yjs_sync_queue ORDER BY id DESC LIMIT 5;
```

**On VM2 (after a few seconds):**
```sql
-- Verify the change propagated
SELECT id, fullname FROM mdl_course WHERE id = 2;
-- Should show: 'Test Sync Course'
```

### 6.3 Monitor Sync Activity

Watch the daemon logs:
```bash
# VM1 Sender
tail -f /home/devo2024/moodle-sync/sender.log

# VM2 Receiver
tail -f /home/devo2024/moodle-sync/receiver.log
```

---

## 7. Systemd Services (Production)

### 7.1 Create Yjs Server Service (VM1)

Create `/etc/systemd/system/yjs-server.service`:
```ini
[Unit]
Description=Yjs Moodle Sync WebSocket Server
After=network.target

[Service]
Type=simple
User=devo2024
WorkingDirectory=/home/devo2024/yjs-moodle-sync
ExecStart=/usr/bin/node yjs-server.js
Restart=always
RestartSec=10
StandardOutput=append:/var/log/yjs-server.log
StandardError=append:/var/log/yjs-server.log

[Install]
WantedBy=multi-user.target
```

### 7.2 Create Sender Service (All VMs)

Create `/etc/systemd/system/moodle-sender.service`:
```ini
[Unit]
Description=Moodle Sync Sender Daemon
After=network.target mysql.service

[Service]
Type=simple
User=devo2024
WorkingDirectory=/home/devo2024/moodle-sync
ExecStart=/usr/bin/php moodle-sync-sender.php
Restart=always
RestartSec=10
StandardOutput=append:/var/log/moodle-sender.log
StandardError=append:/var/log/moodle-sender.log

[Install]
WantedBy=multi-user.target
```

### 7.3 Create Receiver Service (All VMs)

Create `/etc/systemd/system/moodle-receiver.service`:
```ini
[Unit]
Description=Moodle Sync Receiver Daemon
After=network.target mysql.service

[Service]
Type=simple
User=devo2024
WorkingDirectory=/home/devo2024/moodle-sync
ExecStart=/usr/bin/php moodle-sync-receiver.php
Restart=always
RestartSec=10
StandardOutput=append:/var/log/moodle-receiver.log
StandardError=append:/var/log/moodle-receiver.log

[Install]
WantedBy=multi-user.target
```

### 7.4 Enable and Start Services

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable services (auto-start on boot)
sudo systemctl enable yjs-server      # VM1 only
sudo systemctl enable moodle-sender
sudo systemctl enable moodle-receiver

# Start services
sudo systemctl start yjs-server       # VM1 only
sudo systemctl start moodle-sender
sudo systemctl start moodle-receiver

# Check status
sudo systemctl status yjs-server
sudo systemctl status moodle-sender
sudo systemctl status moodle-receiver
```

---

## 8. Troubleshooting

### Common Issues

#### Connection Refused to Yjs Server
```bash
# Check if Yjs server is running
ps aux | grep yjs-server

# Check port is open
sudo netstat -tlnp | grep 2345

# Check firewall
sudo ufw status
sudo ufw allow 2345
```

#### MySQL Connection Errors
```bash
# Test MySQL connection
mysql -u Fog_computing -p'Live4you@' moodle -e "SELECT 1"

# Check MySQL is running
sudo systemctl status mysql
```

#### WebSocket Timeout
- Increase timeout in PHP config
- Check network connectivity between VMs
- Verify HAProxy tunnel timeout settings

#### Sync Not Working
```bash
# Check queue has pending items
mysql -e "SELECT * FROM mdl_yjs_sync_queue WHERE synced=0"

# Check sender is processing
tail -f /home/devo2024/moodle-sync/sender.log

# Check receiver is connected
tail -f /home/devo2024/moodle-sync/receiver.log
```

### Log Locations

| Service | Log File |
|---------|----------|
| Yjs Server | `/var/log/yjs-server.log` |
| Sender | `/var/log/moodle-sender.log` |
| Receiver | `/var/log/moodle-receiver.log` |
| HAProxy | `/var/log/haproxy.log` |

---

## 📞 Support

For issues or questions, refer to:
- [README.md](../README.md) - Project overview
- [research-questions.md](research-questions.md) - Technical details
- Project documentation folder



