# ⚡ Quick Start Guide

> Get the Moodle sync system running in 5 minutes

---

## Prerequisites

- 3 VMs with Ubuntu 22.04, MySQL 8.x, PHP 8.x
- Moodle installed on each VM
- Node.js 18+ on VM1

---

## Step 1: Clone to All VMs

```bash
# On each VM
cd /home/devo2024
git clone <repo-url> moodle-sync
cd moodle-sync/project-submission
```

---

## Step 2: Install Dependencies

### VM1 (Yjs Server)
```bash
cd code
npm install
```

### All VMs (PHP)
```bash
cd code
composer install
```

---

## Step 3: Configure VM Names

Edit `code/moodle-sync-sender.php` and `code/moodle-sync-receiver.php`:

| VM | Set VM_NAME to |
|----|----------------|
| VM1 | `'VM1'` |
| VM2 | `'VM2'` |
| VM3 | `'VM3'` |

---

## Step 4: Setup Database

On each VM:
```bash
# Edit trigger.sql - set @VM_NAME to your VM
mysql -u root -p moodle < code/trigger.sql
```

---

## Step 5: Start Services

### VM1 (Start First)
```bash
cd /home/devo2024/moodle-sync/project-submission/code

# Start Yjs server
node yjs-server.js &

# Start sender and receiver
php moodle-sync-sender.php &
php moodle-sync-receiver.php &
```

### VM2 & VM3
```bash
cd /home/devo2024/moodle-sync/project-submission/code

# Start sender and receiver
php moodle-sync-sender.php &
php moodle-sync-receiver.php &
```

---

## Step 6: Test Sync

On VM1:
```sql
mysql -u Fog_computing -p'Live4you@' moodle
UPDATE mdl_course SET fullname = 'Test Sync' WHERE id = 2;
```

On VM2 (after ~1 second):
```sql
SELECT fullname FROM mdl_course WHERE id = 2;
-- Should show: 'Test Sync'
```

---

## 🎉 Done!

Your distributed Moodle sync is now running!

See [documentation/setup-guide.md](documentation/setup-guide.md) for production deployment with systemd services.



