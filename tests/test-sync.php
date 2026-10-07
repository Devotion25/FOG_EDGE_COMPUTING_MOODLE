<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  TEST: Database Synchronization
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  This script tests the complete sync pipeline:
 *  1. Makes a database change
 *  2. Verifies it appears in the sync queue
 *  3. Checks if it gets marked as synced
 * 
 *  Usage: php test-sync.php
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Configuration
$config = [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'Fog_computing',
    'DB_PASS' => 'Live4you@',
    'DB_NAME' => 'moodle',
    'TEST_COURSE_ID' => 2,  // ID of course to test with
    'WAIT_TIME' => 5,       // Seconds to wait for sync
];

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  🧪 DATABASE SYNCHRONIZATION TEST\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Connect to database
echo "📋 Connecting to database...\n";
$mysqli = new mysqli(
    $config['DB_HOST'],
    $config['DB_USER'],
    $config['DB_PASS'],
    $config['DB_NAME']
);

if ($mysqli->connect_error) {
    echo "❌ Connection failed: " . $mysqli->connect_error . "\n";
    exit(1);
}
echo "✅ Connected to MySQL\n\n";

// Get current course name
echo "📋 Test 1: Reading current course data...\n";
$result = $mysqli->query("SELECT id, fullname, shortname FROM mdl_course WHERE id = {$config['TEST_COURSE_ID']}");
if (!$result || $result->num_rows === 0) {
    echo "❌ Course ID {$config['TEST_COURSE_ID']} not found\n";
    exit(1);
}
$originalCourse = $result->fetch_assoc();
echo "✅ Current course: {$originalCourse['fullname']} ({$originalCourse['shortname']})\n\n";

// Generate test name
$testName = "Test Course " . date('H:i:s');
$testShortname = "TEST" . rand(100, 999);

// Make a change
echo "📋 Test 2: Updating course name...\n";
echo "   New name: $testName\n";
echo "   New shortname: $testShortname\n";

$updateQuery = "UPDATE mdl_course SET fullname = ?, shortname = ?, timemodified = ? WHERE id = ?";
$stmt = $mysqli->prepare($updateQuery);
$timestamp = time();
$stmt->bind_param('ssii', $testName, $testShortname, $timestamp, $config['TEST_COURSE_ID']);

if (!$stmt->execute()) {
    echo "❌ Update failed: " . $stmt->error . "\n";
    exit(1);
}
echo "✅ Course updated successfully\n\n";

// Check sync queue
echo "📋 Test 3: Checking sync queue for new entry...\n";
sleep(1); // Give trigger time to fire

$queueQuery = "SELECT * FROM mdl_yjs_sync_queue 
               WHERE table_name = 'mdl_course' 
               AND record_id = {$config['TEST_COURSE_ID']} 
               ORDER BY id DESC LIMIT 1";
$result = $mysqli->query($queueQuery);

if (!$result || $result->num_rows === 0) {
    echo "❌ No entry found in sync queue - trigger may not be working\n";
    $testsPassed = false;
} else {
    $queueEntry = $result->fetch_assoc();
    echo "✅ Found sync queue entry:\n";
    echo "   - ID: {$queueEntry['id']}\n";
    echo "   - Operation: {$queueEntry['operation']}\n";
    echo "   - Source VM: {$queueEntry['source_vm']}\n";
    echo "   - Synced: " . ($queueEntry['synced'] ? 'Yes' : 'No') . "\n";
    
    $data = json_decode($queueEntry['data_json'], true);
    echo "   - Data: fullname = {$data['fullname']}\n\n";
    $testsPassed = true;
}

// Wait for sync
echo "📋 Test 4: Waiting {$config['WAIT_TIME']} seconds for sync daemon to process...\n";
for ($i = 0; $i < $config['WAIT_TIME']; $i++) {
    echo "   " . ($config['WAIT_TIME'] - $i) . "...\n";
    sleep(1);
}

// Check if synced
echo "\n📋 Test 5: Checking if entry was synced...\n";
$result = $mysqli->query("SELECT synced FROM mdl_yjs_sync_queue WHERE id = {$queueEntry['id']}");
if ($result && $row = $result->fetch_assoc()) {
    if ($row['synced'] == 1) {
        echo "✅ Entry marked as synced - sender daemon is working!\n\n";
    } else {
        echo "⚠️ Entry still pending - sender daemon may not be running\n";
        echo "   Run: php moodle-sync-sender.php\n\n";
        $testsPassed = false;
    }
}

// Restore original name
echo "📋 Cleanup: Restoring original course name...\n";
$restoreStmt = $mysqli->prepare("UPDATE mdl_course SET fullname = ?, shortname = ?, timemodified = ? WHERE id = ?");
$timestamp = time();
$restoreStmt->bind_param('ssii', $originalCourse['fullname'], $originalCourse['shortname'], $timestamp, $config['TEST_COURSE_ID']);
$restoreStmt->execute();
echo "✅ Course name restored\n\n";

// Print results
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  📊 TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if ($testsPassed) {
    echo "  ✅ All tests PASSED\n";
    echo "  \n";
    echo "  The synchronization pipeline is working:\n";
    echo "  • MySQL trigger is capturing changes\n";
    echo "  • Sync queue is being populated\n";
    echo "  • Sender daemon is processing queue\n";
} else {
    echo "  ⚠️ Some tests had issues - check output above\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";

$mysqli->close();
exit($testsPassed ? 0 : 1);



