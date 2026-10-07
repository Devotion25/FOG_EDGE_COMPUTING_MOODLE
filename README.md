# 🎓 Eventual Consistency in Distributed Moodle Learning Management Systems

> **A Real-Time Database Synchronization System Using CRDT and WebSocket Communication**

[![Status](https://img.shields.io/badge/Status-Working%20PoC-success)](/)
[![Node.js](https://img.shields.io/badge/Node.js-20.x-green)](https://nodejs.org/)
[![PHP](https://img.shields.io/badge/PHP-8.4-blue)](https://php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.4-orange)](https://mysql.com/)

---

## 📋 Table of Contents

- [Project Overview](#-project-overview)
- [Architecture](#-architecture)
- [Key Features](#-key-features)
- [Technology Stack](#-technology-stack)
- [Quick Start](#-quick-start)
- [Data Flow](#-data-flow)
- [CAP Theorem Analysis](#-cap-theorem-analysis)
- [Project Structure](#-project-structure)
- [Performance Metrics](#-performance-metrics)
- [Testing Results](#-testing-results)
- [Future Enhancements](#-future-enhancements)
- [Academic Contributions](#-academic-contributions)

---

## 🎯 Project Overview

This project implements **real-time database synchronization** across multiple Moodle LMS instances using:

- **CRDT (Conflict-free Replicated Data Types)** via the Yjs library
- **WebSocket** for real-time bidirectional communication
- **Last-Write-Wins (LWW)** conflict resolution
- **Multi-master replication** where any VM can initiate changes

### Objective

Achieve **eventual consistency** across a distributed Moodle cluster, ensuring all database changes propagate to all nodes within milliseconds while maintaining system availability during network partitions.

---

## 🏗 Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    DISTRIBUTED MOODLE CLUSTER                    │
│                   (3 VMs - Multi-Master Setup)                   │
└─────────────────────────────────────────────────────────────────┘

        ┌──────────────────────────────────────────┐
        │            HAProxy (VM1)                 │
        │   Load Balancer - Port 80 (HTTP)        │
        │   WebSocket Proxy - Port 1234            │
        └────────────┬──────────────┬──────────────┘
                     │              │
        ┌────────────┴──────┐   ┌──┴──────────────┐
        │                   │   │                  │
┌───────▼────────┐  ┌───────▼────────┐  ┌────────▼───────┐
│   VM1          │  │   VM2          │  │   VM3          │
│ 172.20.10.4    │  │ 172.20.10.5    │  │ 172.20.10.6    │
├────────────────┤  ├────────────────┤  ├────────────────┤
│ Apache:8080    │  │ Apache:8080    │  │ Apache:8080    │
│ MySQL:3306     │  │ MySQL:3306     │  │ MySQL:3306     │
│ Yjs:2345       │  │                │  │                │
│ PHP Sender     │  │ PHP Sender     │  │ PHP Sender     │
│ PHP Receiver   │  │ PHP Receiver   │  │ PHP Receiver   │
└────────────────┘  └────────────────┘  └────────────────┘
```

### Sync Flow Diagram

```
┌──────────────────────┐
│   USER MAKES CHANGE  │
│ (via Moodle Web UI)  │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│   MySQL Database     │
│   (Local Update)     │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│   MySQL TRIGGER      │
│   Detects change     │
│   INSERT → Queue     │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│   Sync Queue Table   │
│ mdl_yjs_sync_queue   │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│   PHP Sender Daemon  │
│   Polls every 200ms  │
│   Sends via WebSocket│
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│   Yjs Server (VM1)   │
│   Port 2345          │
│   Broadcasts to ALL  │
└──────────┬───────────┘
           │
     ┌─────┴─────┐
     ▼           ▼
┌─────────┐ ┌─────────┐
│VM1 Recv │ │VM2 Recv │
│ Skip    │ │ Apply   │
│(own msg)│ │ Change  │
└─────────┘ └─────────┘
```

---

## ✨ Key Features

| Feature | Description |
|---------|-------------|
| 🔄 **Multi-Master Replication** | Any VM can initiate changes, all are equal peers |
| ⚡ **Real-Time Sync** | Changes propagate in 200-500ms |
| 🤝 **Eventual Consistency** | All VMs converge to the same state |
| 🛡️ **Fault Tolerance** | Changes queue locally during network issues |
| ⚖️ **Conflict Resolution** | Last-Write-Wins using timestamps |
| 📊 **CRDT Infrastructure** | Yjs library for conflict-free replication |
| 🔌 **Auto-Reconnection** | Automatic recovery from network failures |

---

## 🛠 Technology Stack

### Backend
| Technology | Version | Purpose |
|------------|---------|---------|
| MySQL | 8.4 | Database with triggers |
| PHP | 8.4 | Sync daemons |
| Node.js | 20.x | Yjs WebSocket server |
| Apache | 2.4 | Web server |
| HAProxy | 2.x | Load balancer |

### Libraries
| Library | Purpose |
|---------|---------|
| **Yjs** | CRDT for conflict-free replication |
| **ws** | WebSocket server for Node.js |
| **textalk/websocket** | PHP WebSocket client |

### Protocols
- **WebSocket** - Real-time bidirectional communication
- **HTTP/HTTPS** - Web traffic
- **MySQL Protocol** - Database communication

---

## 🚀 Quick Start

### Prerequisites
- 3 VMs running Ubuntu 22.04+
- MySQL 8.x installed on each VM
- PHP 8.x with mysqli extension
- Node.js 18+ on VM1
- Composer for PHP dependencies

### Installation

**1. Clone the repository on each VM:**
```bash
cd /home/devo2024
git clone <repository-url> moodle-sync
cd moodle-sync
```

**2. Install dependencies (VM1 - Yjs Server):**
```bash
cd code
npm install
```

**3. Install PHP dependencies (All VMs):**
```bash
composer install
```

**4. Configure MySQL (All VMs):**
```bash
# Edit trigger.sql - Set @VM_NAME appropriately
mysql -u root -p moodle < trigger.sql
```

**5. Start the services:**
```bash
# VM1 - Start Yjs Server
node yjs-server.js &

# All VMs - Start Sender and Receiver
php moodle-sync-sender.php &
php moodle-sync-receiver.php &
```

See [setup-guide.md](documentation/setup-guide.md) for detailed instructions.

---

## 📊 Data Flow

### Example: Course Name Update on VM2

```
Step 1: User Action
─────────────────
Teacher updates course name from "Physics" to "Advanced Physics"

Step 2: MySQL Update
─────────────────
UPDATE mdl_course SET fullname = 'Advanced Physics' WHERE id = 2

Step 3: Trigger Fires
─────────────────
INSERT INTO mdl_yjs_sync_queue (
    table_name: 'mdl_course',
    operation: 'UPDATE',
    record_id: 2,
    data_json: '{"id":2,"fullname":"Advanced Physics",...}',
    source_vm: 'VM2',
    timestamp: 1701234567
)

Step 4: Sender Detection
─────────────────
PHP Sender polls queue → Finds unsynced row → Sends to Yjs

Step 5: WebSocket Broadcast
─────────────────
VM2 → Yjs Server → VM1 Receiver, VM3 Receiver

Step 6: Conflict Resolution
─────────────────
Each receiver:
- Checks source_vm (skip if own)
- Compares timestamps (Last-Write-Wins)
- Applies if incoming is newer

Step 7: Eventual Consistency ✓
─────────────────
All 3 VMs: fullname = "Advanced Physics"
```

---

## 📐 CAP Theorem Analysis

This system implements the **AP (Available + Partition-tolerant)** model:

| Property | Status | Explanation |
|----------|--------|-------------|
| **Consistency** | ❌ Eventual | Brief window (200-500ms) of inconsistency |
| **Availability** | ✅ Always | VMs continue working during network issues |
| **Partition Tolerance** | ✅ Yes | System continues despite network failures |

### Conflict Resolution: Last-Write-Wins

```
Scenario: Concurrent edits to same course
─────────────────────────────────────────

10:00:00.500 - VM1 updates: "Physics 101"
10:00:00.800 - VM2 updates: "Advanced Physics"

Resolution:
─────────────────────────────────────────
Both VMs compare timestamps
10:00:00.800 > 10:00:00.500
Winner: "Advanced Physics"

Final State (All VMs):
─────────────────────────────────────────
fullname: "Advanced Physics"
```

---

## 📁 Project Structure

```
project-submission/
├── README.md                          ← This file
├── code/
│   ├── yjs-server.js                 ← Yjs WebSocket server
│   ├── moodle-sync-sender.php        ← PHP sender daemon
│   ├── moodle-sync-receiver.php      ← PHP receiver daemon
│   ├── trigger.sql                   ← MySQL triggers
│   ├── haproxy.cfg                   ← Load balancer config
│   ├── package.json                  ← Node.js dependencies
│   └── composer.json                 ← PHP dependencies
├── documentation/
│   ├── setup-guide.md                ← Installation steps
│   ├── research-questions.md         ← Academic answers
│   └── testing-results.md            ← Test outcomes
├── services/
│   ├── yjs-server.service            ← Systemd service
│   ├── moodle-sender.service         ← Systemd service
│   └── moodle-receiver.service       ← Systemd service
├── tests/
│   ├── test-connection.js            ← Connection test
│   ├── test-sync.php                 ← Sync test
│   └── test-concurrent.sh            ← Concurrent update test
└── screenshots/
    └── (Terminal outputs, UI demos)
```

---

## 📈 Performance Metrics

| Metric | Value |
|--------|-------|
| **Trigger Execution** | < 5ms |
| **Queue Insert** | < 10ms |
| **Sender Detection** | 0-200ms (poll interval) |
| **WebSocket Transmission** | 5-20ms |
| **Receiver Processing** | 10-30ms |
| **Database Update** | 10-20ms |
| **Total End-to-End** | **200-500ms typical** |

### Resource Usage

| Component | RAM | CPU |
|-----------|-----|-----|
| PHP Sender | ~10MB | < 1% |
| PHP Receiver | ~10MB | < 1% |
| Yjs Server | ~50MB | < 2% |
| MySQL Trigger | Negligible | Negligible |

---

## 🧪 Testing Results

| Test | Action | Expected | Result |
|------|--------|----------|--------|
| VM1 → VM2 Sync | Update on VM1 | VM2 receives and applies | ✅ SUCCESS (287ms) |
| VM2 → VM1 Sync | Update on VM2 | VM1 receives and applies | ✅ SUCCESS (312ms) |
| Concurrent Updates | Both VMs update same course | LWW resolution | ✅ SUCCESS |
| Network Interruption | Disconnect, change, reconnect | Queue and sync | ✅ SUCCESS |

---

## 🚀 Future Enhancements

### Phase 2: Extended Sync
- [ ] Sync `mdl_course_modules` (activities)
- [ ] Sync `mdl_quiz` (quizzes)
- [ ] Sync `mdl_assign` (assignments)
- [ ] Implement INSERT and DELETE operations

### Phase 3: Production Ready
- [ ] Systemd services for auto-start
- [ ] Prometheus metrics
- [ ] TLS/SSL encryption (wss://)
- [ ] Authentication tokens

### Phase 4: High Availability
- [ ] Multiple Yjs servers (clustering)
- [ ] Redis for message persistence
- [ ] Automatic failover

---

## 🎓 Academic Contributions

This project demonstrates understanding of:

1. **Distributed Systems Theory** - CAP theorem, eventual consistency
2. **Replication Strategies** - Multi-master, CRDTs
3. **Network Protocols** - WebSocket, HTTP
4. **Database Design** - Triggers, CDC, versioning
5. **System Architecture** - Microservices, event-driven

---

## 📝 License

This project is developed for academic purposes as part of a Fog Computing research project.

---

## 👥 Authors

Distributed Moodle Sync Project Team

---

## 🙏 Acknowledgments

- [Yjs](https://yjs.dev/) - CRDT implementation
- [ws](https://github.com/websockets/ws) - WebSocket library
- [Moodle](https://moodle.org/) - Learning Management System



