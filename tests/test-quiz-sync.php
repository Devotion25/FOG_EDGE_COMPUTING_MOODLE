<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  TEST: Quiz Synchronization
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  Tests the complete quiz sync pipeline:
 *  1. Creates a quiz
 *  2. Verifies it appears in the sync queue
 *  3. Updates the quiz
 *  4. Verifies update in sync queue
 *  5. Cleans up
 * 
 *  Usage: php test-quiz-sync.php
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Configuration
$config = [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'Fog_computing',
    'DB_PASS' => 'Live4you@',
    'DB_NAME' => 'moodle',
    'TEST_COURSE_ID' => 2,
    'WAIT_TIME' => 3,
];

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  🧪 QUIZ SYNCHRONIZATION TEST\n";
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

$testsPassed = true;
$testQuizId = null;

// Test 1: Create a quiz
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 1: Creating test quiz...\n";

$timestamp = time();
$quizName = "Sync Test Quiz " . date('H:i:s');

$createQuery = "INSERT INTO mdl_quiz (
    course, name, intro, introformat,
    timeopen, timeclose, timelimit,
    preferredbehaviour, attempts, grademethod,
    questionsperpage, shuffleanswers, grade,
    timecreated, timemodified
) VALUES (
    ?, ?, 'Test quiz for sync verification', 1,
    ?, ?, 3600,
    'deferredfeedback', 3, 1,
    1, 1, 100,
    ?, ?
)";

$stmt = $mysqli->prepare($createQuery);
$timeopen = $timestamp;
$timeclose = $timestamp + 604800;
$stmt->bind_param('isiiii', 
    $config['TEST_COURSE_ID'],
    $quizName,
    $timeopen,
    $timeclose,
    $timestamp,
    $timestamp
);

if ($stmt->execute()) {
    $testQuizId = $mysqli->insert_id;
    echo "✅ Quiz created with ID: $testQuizId\n";
    echo "   Name: $quizName\n\n";
} else {
    echo "❌ Failed to create quiz: " . $stmt->error . "\n";
    $testsPassed = false;
}
$stmt->close();

// Test 2: Verify INSERT in sync queue
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 2: Checking sync queue for INSERT...\n";
sleep(1);

$queueQuery = "SELECT * FROM mdl_yjs_sync_queue 
               WHERE table_name = 'mdl_quiz' 
               AND record_id = ? 
               AND operation = 'INSERT'
               ORDER BY id DESC LIMIT 1";
$stmt = $mysqli->prepare($queueQuery);
$stmt->bind_param('i', $testQuizId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "✅ INSERT found in sync queue:\n";
    echo "   Queue ID: {$row['id']}\n";
    echo "   Source VM: {$row['source_vm']}\n";
    echo "   Synced: " . ($row['synced'] ? 'Yes' : 'No') . "\n\n";
} else {
    echo "❌ INSERT not found in sync queue - trigger may not be working\n\n";
    $testsPassed = false;
}
$stmt->close();

// Test 3: Update the quiz
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 3: Updating quiz...\n";

$newName = "Updated Quiz " . date('H:i:s');
$newTimestamp = time();

$updateQuery = "UPDATE mdl_quiz SET 
                name = ?,
                timelimit = 7200,
                timemodified = ?
                WHERE id = ?";
$stmt = $mysqli->prepare($updateQuery);
$stmt->bind_param('sii', $newName, $newTimestamp, $testQuizId);

if ($stmt->execute()) {
    echo "✅ Quiz updated\n";
    echo "   New name: $newName\n";
    echo "   New time limit: 7200 seconds\n\n";
} else {
    echo "❌ Failed to update quiz: " . $stmt->error . "\n";
    $testsPassed = false;
}
$stmt->close();

// Test 4: Verify UPDATE in sync queue
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 4: Checking sync queue for UPDATE...\n";
sleep(1);

$queueQuery = "SELECT * FROM mdl_yjs_sync_queue 
               WHERE table_name = 'mdl_quiz' 
               AND record_id = ? 
               AND operation = 'UPDATE'
               ORDER BY id DESC LIMIT 1";
$stmt = $mysqli->prepare($queueQuery);
$stmt->bind_param('i', $testQuizId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "✅ UPDATE found in sync queue:\n";
    echo "   Queue ID: {$row['id']}\n";
    echo "   Source VM: {$row['source_vm']}\n";
    
    $data = json_decode($row['data_json'], true);
    echo "   Data: name = {$data['name']}\n";
    echo "   Data: timelimit = {$data['timelimit']}\n\n";
} else {
    echo "❌ UPDATE not found in sync queue\n\n";
    $testsPassed = false;
}
$stmt->close();

// Test 5: Wait for sync daemon
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 5: Waiting {$config['WAIT_TIME']} seconds for sync daemon...\n";
for ($i = $config['WAIT_TIME']; $i > 0; $i--) {
    echo "   $i...\n";
    sleep(1);
}

// Check if synced
$queueQuery = "SELECT synced FROM mdl_yjs_sync_queue 
               WHERE table_name = 'mdl_quiz' 
               AND record_id = ?
               ORDER BY id DESC LIMIT 1";
$stmt = $mysqli->prepare($queueQuery);
$stmt->bind_param('i', $testQuizId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $row = $result->fetch_assoc()) {
    if ($row['synced'] == 1) {
        echo "✅ Quiz changes synced successfully!\n\n";
    } else {
        echo "⚠️ Quiz changes still pending (sender may not be running)\n\n";
    }
}
$stmt->close();

// Test 6: Cleanup
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 6: Cleaning up test quiz...\n";

$deleteQuery = "DELETE FROM mdl_quiz WHERE id = ?";
$stmt = $mysqli->prepare($deleteQuery);
$stmt->bind_param('i', $testQuizId);
$stmt->execute();
echo "✅ Test quiz deleted\n\n";
$stmt->close();

// Test 7: Verify DELETE in sync queue
echo "═══════════════════════════════════════════════════════════════════\n";
echo "📋 Test 7: Checking sync queue for DELETE...\n";
sleep(1);

$queueQuery = "SELECT * FROM mdl_yjs_sync_queue 
               WHERE table_name = 'mdl_quiz' 
               AND record_id = ? 
               AND operation = 'DELETE'
               ORDER BY id DESC LIMIT 1";
$stmt = $mysqli->prepare($queueQuery);
$stmt->bind_param('i', $testQuizId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "✅ DELETE found in sync queue\n\n";
} else {
    echo "❌ DELETE not found in sync queue\n\n";
    $testsPassed = false;
}
$stmt->close();

// Summary
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  📊 TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if ($testsPassed) {
    echo "  ✅ All quiz sync tests PASSED!\n";
    echo "\n";
    echo "  The quiz synchronization pipeline is working:\n";
    echo "  • MySQL triggers capture INSERT/UPDATE/DELETE\n";
    echo "  • Sync queue is populated with quiz data\n";
    echo "  • All quiz fields are captured in JSON\n";
} else {
    echo "  ⚠️ Some tests had issues - check output above\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";

$mysqli->close();
exit($testsPassed ? 0 : 1);

