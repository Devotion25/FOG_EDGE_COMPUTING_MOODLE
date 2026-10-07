<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  MOODLE SYNC RECEIVER DAEMON - ENHANCED VERSION
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  Project: Eventual Consistency in Distributed Moodle Learning Management Systems
 *  Component: Database Change Receiver
 *  Technology: PHP 8.4 + WebSocket (textalk/websocket)
 * 
 *  Purpose:
 *  - Listen to the central Yjs WebSocket server for database changes
 *  - Apply changes from other VMs to the local database
 *  - Implement Last-Write-Wins conflict resolution using timestamps
 *  - Skip changes that originate from this VM (prevent loops)
 *  - Support for: Courses, Quizzes, User Enrollments
 *  - Catch-up feature for offline recovery
 * 
 *  Usage:
 *  - Run as daemon: php moodle-sync-receiver.php
 *  - Or via systemd service
 * 
 *  Author: Distributed Moodle Sync Project
 *  Version: 3.0.0
 *  Last Updated: December 2025
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use WebSocket\Client;
use WebSocket\ConnectionException;

// ═══════════════════════════════════════════════════════════════════════════════
//  CONFIGURATION - MODIFY THESE FOR YOUR VM
// ═══════════════════════════════════════════════════════════════════════════════

$config = [
    // VM Identifier - CHANGE THIS FOR EACH VM!
    'VM_NAME' => 'VM1',  // Options: 'VM1', 'VM2', 'VM3'
    
    // Database Configuration
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'moodle',
    'DB_USER' => 'Fog_computing',
    'DB_PASS' => 'Live4you@',
    'DB_TABLE_PREFIX' => 'mdl_',
    
    // Yjs Server Configuration
    'YJS_SERVER' => 'ws://localhost:2345',
    
    // Sync Configuration
    'RECONNECT_DELAY_MS' => 5000,     // Wait 5s before reconnecting
    'HEARTBEAT_INTERVAL' => 30,       // Send heartbeat every 30s
    'RECEIVE_TIMEOUT' => 60,          // WebSocket receive timeout
    
    // Conflict Resolution
    'CONFLICT_STRATEGY' => 'LWW',     // Last-Write-Wins
    
    // Logging
    'LOG_LEVEL' => 'info',            // debug, info, warn, error
    'LOG_FILE' => null,               // null = stdout, or path to file
];

// ═══════════════════════════════════════════════════════════════════════════════
//  LAST SYNC TIMESTAMP TRACKING (for catch-up feature)
// ═══════════════════════════════════════════════════════════════════════════════

$lastSyncFile = '/tmp/moodle_sync_last_' . $config['VM_NAME'] . '.txt';

function getLastSyncTime() {
    global $lastSyncFile;
    if (file_exists($lastSyncFile)) {
        return (int)file_get_contents($lastSyncFile);
    }
    return 0;
}

function saveLastSyncTime($timestamp) {
    global $lastSyncFile;
    file_put_contents($lastSyncFile, $timestamp);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  LOGGING UTILITIES
// ═══════════════════════════════════════════════════════════════════════════════

$LOG_LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

function logMessage($level, $message, $data = null) {
    global $config, $LOG_LEVELS;
    
    if ($LOG_LEVELS[$level] < $LOG_LEVELS[$config['LOG_LEVEL']]) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s.') . substr(microtime(), 2, 3);
    $prefix = [
        'debug' => '🔍',
        'info' => '📘',
        'warn' => '⚠️',
        'error' => '❌'
    ][$level] ?? '📘';
    
    $vmName = $config['VM_NAME'];
    $output = "[$timestamp] [$vmName] $prefix $message";
    
    if ($data !== null) {
        $output .= " " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    
    $output .= PHP_EOL;
    
    if ($config['LOG_FILE']) {
        file_put_contents($config['LOG_FILE'], $output, FILE_APPEND);
    } else {
        echo $output;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  STATISTICS TRACKER
// ═══════════════════════════════════════════════════════════════════════════════

$stats = [
    'startTime' => time(),
    'messagesReceived' => 0,
    'changesApplied' => 0,
    'changesSkipped' => 0,
    'conflictsResolved' => 0,
    'errors' => 0,
    'reconnections' => 0,
    'catchupChanges' => 0,
    'byTable' => [],
    'byOperation' => ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0],
    'bySourceVM' => ['VM1' => 0, 'VM2' => 0, 'VM3' => 0],
];

function updateStats($type, $data = []) {
    global $stats;
    
    switch ($type) {
        case 'received':
            $stats['messagesReceived']++;
            break;
        case 'applied':
            $stats['changesApplied']++;
            if (isset($data['table'])) {
                $stats['byTable'][$data['table']] = ($stats['byTable'][$data['table']] ?? 0) + 1;
            }
            if (isset($data['operation'])) {
                $stats['byOperation'][$data['operation']]++;
            }
            if (isset($data['source_vm'])) {
                $stats['bySourceVM'][$data['source_vm']] = ($stats['bySourceVM'][$data['source_vm']] ?? 0) + 1;
            }
            break;
        case 'skipped':
            $stats['changesSkipped']++;
            break;
        case 'conflict':
            $stats['conflictsResolved']++;
            break;
        case 'error':
            $stats['errors']++;
            break;
        case 'reconnect':
            $stats['reconnections']++;
            break;
        case 'catchup':
            $stats['catchupChanges']++;
            break;
    }
}

function getFormattedStats() {
    global $stats;
    
    $uptime = time() - $stats['startTime'];
    $hours = floor($uptime / 3600);
    $minutes = floor(($uptime % 3600) / 60);
    $seconds = $uptime % 60;
    
    return [
        'uptime' => "{$hours}h {$minutes}m {$seconds}s",
        'messagesReceived' => $stats['messagesReceived'],
        'changesApplied' => $stats['changesApplied'],
        'changesSkipped' => $stats['changesSkipped'],
        'conflictsResolved' => $stats['conflictsResolved'],
        'catchupChanges' => $stats['catchupChanges'],
        'errors' => $stats['errors'],
        'reconnections' => $stats['reconnections'],
        'byTable' => $stats['byTable'],
        'byOperation' => $stats['byOperation'],
        'bySourceVM' => $stats['bySourceVM'],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
//  DATABASE CONNECTION
// ═══════════════════════════════════════════════════════════════════════════════

function connectToDatabase() {
    global $config;
    
    logMessage('info', 'Connecting to MySQL database...');
    
    $mysqli = new mysqli(
        $config['DB_HOST'],
        $config['DB_USER'],
        $config['DB_PASS'],
        $config['DB_NAME'],
        $config['DB_PORT']
    );
    
    if ($mysqli->connect_error) {
        logMessage('error', 'MySQL connection failed: ' . $mysqli->connect_error);
        return null;
    }
    
    $mysqli->set_charset('utf8mb4');
    logMessage('info', 'Successfully connected to MySQL database');
    
    return $mysqli;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  WEBSOCKET CONNECTION (with catch-up request)
// ═══════════════════════════════════════════════════════════════════════════════

function connectToYjs() {
    global $config;
    
    logMessage('info', 'Connecting to Yjs server: ' . $config['YJS_SERVER']);
    
    try {
        $client = new Client($config['YJS_SERVER'], [
            'timeout' => $config['RECEIVE_TIMEOUT'],
            'headers' => [
                'X-VM-Name' => $config['VM_NAME'],
                'X-Client-Type' => 'receiver'
            ]
        ]);
        
        // Send join message
        $joinMessage = json_encode([
            'type' => 'join',
            'room' => 'moodle-database-sync',
            'vm_name' => $config['VM_NAME'],
            'client_type' => 'receiver',
            'timestamp' => time()
        ]);
        
        $client->send($joinMessage);
        
        // REQUEST MISSED CHANGES ON CONNECT (catch-up feature)
        $lastSync = getLastSyncTime();
        $syncRequest = json_encode([
            'type' => 'sync_request',
            'vm_name' => $config['VM_NAME'],
            'since_timestamp' => $lastSync,
            'timestamp' => time()
        ]);
        $client->send($syncRequest);
        logMessage('info', "📥 Requested missed changes since: " . ($lastSync > 0 ? date('Y-m-d H:i:s', $lastSync) : 'beginning'));
        
        logMessage('info', 'WebSocket connection established');
        return $client;
        
    } catch (Exception $e) {
        logMessage('error', 'WebSocket connection failed: ' . $e->getMessage());
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  CONFLICT RESOLUTION - LAST-WRITE-WINS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Get the local timestamp for a record
 */
function getLocalTimestamp($mysqli, $table, $recordId) {
    global $config;
    
    // Map table names to their timestamp columns
    $timestampColumns = [
        'mdl_course' => 'timemodified',
        'mdl_course_modules' => 'timemodified',
        'mdl_quiz' => 'timemodified',
        'mdl_assign' => 'timemodified',
        'mdl_forum' => 'timemodified',
        'mdl_user' => 'timemodified',
        'mdl_user_enrolments' => 'timemodified',
    ];
    
    $fullTable = $config['DB_TABLE_PREFIX'] . str_replace('mdl_', '', $table);
    $timestampCol = $timestampColumns[$table] ?? 'timemodified';
    
    $query = "SELECT $timestampCol FROM $fullTable WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        logMessage('error', "Failed to prepare timestamp query: " . $mysqli->error);
        return null;
    }
    
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int)$row[$timestampCol];
    }
    
    $stmt->close();
    return null;
}

/**
 * Check if incoming change should be applied (Last-Write-Wins)
 */
function shouldApplyChange($localTimestamp, $incomingTimestamp) {
    if ($localTimestamp === null) {
        return true;
    }
    return $incomingTimestamp > $localTimestamp;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  COURSE CHANGE HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply a course update to the local database
 */
function applyCourseUpdate($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'course';
    $recordId = (int)$data['id'];
    
    $localTimestamp = getLocalTimestamp($mysqli, 'mdl_course', $recordId);
    
    if (!shouldApplyChange($localTimestamp, $timestamp)) {
        logMessage('info', "      ⏭️ Skipping - local version is newer");
        updateStats('conflict');
        return false;
    }
    
    $updates = [];
    $params = [];
    $types = '';
    
    if (isset($data['fullname'])) {
        $updates[] = 'fullname = ?';
        $params[] = $data['fullname'];
        $types .= 's';
    }
    
    if (isset($data['shortname'])) {
        $updates[] = 'shortname = ?';
        $params[] = $data['shortname'];
        $types .= 's';
    }
    
    if (isset($data['timemodified'])) {
        $updates[] = 'timemodified = ?';
        $params[] = (int)$data['timemodified'];
        $types .= 'i';
    }
    
    if (empty($updates)) {
        logMessage('warn', '      ⚠️ No fields to update');
        return false;
    }
    
    $params[] = $recordId;
    $types .= 'i';
    
    $query = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        logMessage('error', "Failed to prepare update: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    
    if ($success) {
        logMessage('info', "      ✅ Course updated (ID: $recordId)");
        if (isset($data['fullname'])) {
            logMessage('info', "         • fullname = {$data['fullname']}");
        }
        $stmt->close();
        return true;
    } else {
        logMessage('error', "      ❌ Update failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Apply a course insert
 */
function applyCourseInsert($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'course';
    
    $checkQuery = "SELECT id FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($checkQuery);
    $stmt->bind_param('i', $data['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        logMessage('info', "      ℹ️ Course exists, treating as update");
        return applyCourseUpdate($mysqli, $data, $timestamp);
    }
    $stmt->close();
    
    $query = "INSERT INTO $table (id, fullname, shortname, timemodified) VALUES (?, ?, ?, ?)";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        logMessage('error', "Failed to prepare insert: " . $mysqli->error);
        return false;
    }
    
    $id = (int)$data['id'];
    $fullname = $data['fullname'] ?? '';
    $shortname = $data['shortname'] ?? '';
    $timemodified = (int)($data['timemodified'] ?? $timestamp);
    
    $stmt->bind_param('issi', $id, $fullname, $shortname, $timemodified);
    $success = $stmt->execute();
    
    if ($success) {
        logMessage('info', "      ✅ Course inserted (ID: {$data['id']})");
        $stmt->close();
        return true;
    } else {
        logMessage('error', "      ❌ Insert failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Apply a course delete
 */
function applyCourseDelete($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'course';
    $recordId = (int)$data['id'];
    
    $localTimestamp = getLocalTimestamp($mysqli, 'mdl_course', $recordId);
    
    if (!shouldApplyChange($localTimestamp, $timestamp)) {
        logMessage('info', "      ⏭️ Skipping delete - local is newer");
        updateStats('conflict');
        return false;
    }
    
    $query = "DELETE FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $recordId);
    $success = $stmt->execute();
    
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Course deleted (ID: $recordId)");
    } else {
        logMessage('info', "      ℹ️ Course not found for delete");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  QUIZ CHANGE HANDLERS (ENHANCED - ALL FIELDS)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply quiz changes (INSERT/UPDATE) - Comprehensive version with all quiz fields
 */
function applyQuizChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'quiz';
    $recordId = (int)$data['id'];
    
    // Check if quiz exists
    $checkQuery = "SELECT id, timemodified FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($checkQuery);
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        // Quiz exists - do UPDATE with conflict resolution
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping quiz - local is newer");
            updateStats('conflict');
            return false;
        }
        
        // Comprehensive UPDATE with all quiz fields
        $query = "UPDATE $table SET 
                  name = ?, 
                  intro = ?, 
                  introformat = ?,
                  timeopen = ?, 
                  timeclose = ?, 
                  timelimit = ?,
                  overduehandling = ?,
                  graceperiod = ?,
                  preferredbehaviour = ?,
                  canredoquestions = ?,
                  attempts = ?,
                  attemptonlast = ?,
                  grademethod = ?,
                  decimalpoints = ?,
                  questiondecimalpoints = ?,
                  reviewattempt = ?,
                  reviewcorrectness = ?,
                  reviewmarks = ?,
                  reviewspecificfeedback = ?,
                  reviewgeneralfeedback = ?,
                  reviewrightanswer = ?,
                  reviewoverallfeedback = ?,
                  questionsperpage = ?,
                  navmethod = ?,
                  shuffleanswers = ?,
                  sumgrades = ?,
                  grade = ?,
                  password = ?,
                  subnet = ?,
                  browsersecurity = ?,
                  delay1 = ?,
                  delay2 = ?,
                  showuserpicture = ?,
                  showblocks = ?,
                  completionattemptsexhausted = ?,
                  completionminattempts = ?,
                  allowofflineattempts = ?,
                  timemodified = ?
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        
        if (!$stmt) {
            logMessage('error', "Failed to prepare quiz update: " . $mysqli->error);
            return false;
        }
        
        // Extract all values with defaults
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $introformat = (int)($data['introformat'] ?? 0);
        $timeopen = (int)($data['timeopen'] ?? 0);
        $timeclose = (int)($data['timeclose'] ?? 0);
        $timelimit = (int)($data['timelimit'] ?? 0);
        $overduehandling = $data['overduehandling'] ?? 'autosubmit';
        $graceperiod = (int)($data['graceperiod'] ?? 0);
        $preferredbehaviour = $data['preferredbehaviour'] ?? 'deferredfeedback';
        $canredoquestions = (int)($data['canredoquestions'] ?? 0);
        $attempts = (int)($data['attempts'] ?? 0);
        $attemptonlast = (int)($data['attemptonlast'] ?? 0);
        $grademethod = (int)($data['grademethod'] ?? 1);
        $decimalpoints = (int)($data['decimalpoints'] ?? 2);
        $questiondecimalpoints = (int)($data['questiondecimalpoints'] ?? -1);
        $reviewattempt = (int)($data['reviewattempt'] ?? 69904);
        $reviewcorrectness = (int)($data['reviewcorrectness'] ?? 4368);
        $reviewmarks = (int)($data['reviewmarks'] ?? 4368);
        $reviewspecificfeedback = (int)($data['reviewspecificfeedback'] ?? 4368);
        $reviewgeneralfeedback = (int)($data['reviewgeneralfeedback'] ?? 4368);
        $reviewrightanswer = (int)($data['reviewrightanswer'] ?? 4368);
        $reviewoverallfeedback = (int)($data['reviewoverallfeedback'] ?? 4368);
        $questionsperpage = (int)($data['questionsperpage'] ?? 1);
        $navmethod = $data['navmethod'] ?? 'free';
        $shuffleanswers = (int)($data['shuffleanswers'] ?? 1);
        $sumgrades = (float)($data['sumgrades'] ?? 0);
        $grade = (float)($data['grade'] ?? 10);
        $password = $data['password'] ?? '';
        $subnet = $data['subnet'] ?? '';
        $browsersecurity = $data['browsersecurity'] ?? '-';
        $delay1 = (int)($data['delay1'] ?? 0);
        $delay2 = (int)($data['delay2'] ?? 0);
        $showuserpicture = (int)($data['showuserpicture'] ?? 0);
        $showblocks = (int)($data['showblocks'] ?? 0);
        $completionattemptsexhausted = (int)($data['completionattemptsexhausted'] ?? 0);
        $completionminattempts = (int)($data['completionminattempts'] ?? 0);
        $allowofflineattempts = (int)($data['allowofflineattempts'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('ssiiiisisiiiiiiiiiiiiiissiddssiiiiiiii',
            $name, $intro, $introformat, $timeopen, $timeclose, $timelimit,
            $overduehandling, $graceperiod, $preferredbehaviour, $canredoquestions,
            $attempts, $attemptonlast, $grademethod, $decimalpoints, $questiondecimalpoints,
            $reviewattempt, $reviewcorrectness, $reviewmarks, $reviewspecificfeedback,
            $reviewgeneralfeedback, $reviewrightanswer, $reviewoverallfeedback,
            $questionsperpage, $navmethod, $shuffleanswers, $sumgrades, $grade,
            $password, $subnet, $browsersecurity, $delay1, $delay2,
            $showuserpicture, $showblocks, $completionattemptsexhausted,
            $completionminattempts, $allowofflineattempts, $timemodified, $recordId
        );
        
    } else {
        // Quiz doesn't exist - do INSERT with all fields
        $query = "INSERT INTO $table (
                  id, course, name, intro, introformat, timeopen, timeclose, timelimit,
                  overduehandling, graceperiod, preferredbehaviour, canredoquestions,
                  attempts, attemptonlast, grademethod, decimalpoints, questiondecimalpoints,
                  reviewattempt, reviewcorrectness, reviewmarks, reviewspecificfeedback,
                  reviewgeneralfeedback, reviewrightanswer, reviewoverallfeedback,
                  questionsperpage, navmethod, shuffleanswers, sumgrades, grade,
                  password, subnet, browsersecurity, delay1, delay2,
                  showuserpicture, showblocks, completionattemptsexhausted,
                  completionminattempts, allowofflineattempts, timecreated, timemodified
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($query);
        
        if (!$stmt) {
            logMessage('error', "Failed to prepare quiz insert: " . $mysqli->error);
            return false;
        }
        
        // Extract all values
        $id = (int)$data['id'];
        $course = (int)($data['course'] ?? 0);
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $introformat = (int)($data['introformat'] ?? 0);
        $timeopen = (int)($data['timeopen'] ?? 0);
        $timeclose = (int)($data['timeclose'] ?? 0);
        $timelimit = (int)($data['timelimit'] ?? 0);
        $overduehandling = $data['overduehandling'] ?? 'autosubmit';
        $graceperiod = (int)($data['graceperiod'] ?? 0);
        $preferredbehaviour = $data['preferredbehaviour'] ?? 'deferredfeedback';
        $canredoquestions = (int)($data['canredoquestions'] ?? 0);
        $attempts = (int)($data['attempts'] ?? 0);
        $attemptonlast = (int)($data['attemptonlast'] ?? 0);
        $grademethod = (int)($data['grademethod'] ?? 1);
        $decimalpoints = (int)($data['decimalpoints'] ?? 2);
        $questiondecimalpoints = (int)($data['questiondecimalpoints'] ?? -1);
        $reviewattempt = (int)($data['reviewattempt'] ?? 69904);
        $reviewcorrectness = (int)($data['reviewcorrectness'] ?? 4368);
        $reviewmarks = (int)($data['reviewmarks'] ?? 4368);
        $reviewspecificfeedback = (int)($data['reviewspecificfeedback'] ?? 4368);
        $reviewgeneralfeedback = (int)($data['reviewgeneralfeedback'] ?? 4368);
        $reviewrightanswer = (int)($data['reviewrightanswer'] ?? 4368);
        $reviewoverallfeedback = (int)($data['reviewoverallfeedback'] ?? 4368);
        $questionsperpage = (int)($data['questionsperpage'] ?? 1);
        $navmethod = $data['navmethod'] ?? 'free';
        $shuffleanswers = (int)($data['shuffleanswers'] ?? 1);
        $sumgrades = (float)($data['sumgrades'] ?? 0);
        $grade = (float)($data['grade'] ?? 10);
        $password = $data['password'] ?? '';
        $subnet = $data['subnet'] ?? '';
        $browsersecurity = $data['browsersecurity'] ?? '-';
        $delay1 = (int)($data['delay1'] ?? 0);
        $delay2 = (int)($data['delay2'] ?? 0);
        $showuserpicture = (int)($data['showuserpicture'] ?? 0);
        $showblocks = (int)($data['showblocks'] ?? 0);
        $completionattemptsexhausted = (int)($data['completionattemptsexhausted'] ?? 0);
        $completionminattempts = (int)($data['completionminattempts'] ?? 0);
        $allowofflineattempts = (int)($data['allowofflineattempts'] ?? 0);
        $timecreated = (int)($data['timecreated'] ?? $timestamp);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('iissiiiisisiiiiiiiiiiiiiissiddssiiiiiiii',
            $id, $course, $name, $intro, $introformat, $timeopen, $timeclose, $timelimit,
            $overduehandling, $graceperiod, $preferredbehaviour, $canredoquestions,
            $attempts, $attemptonlast, $grademethod, $decimalpoints, $questiondecimalpoints,
            $reviewattempt, $reviewcorrectness, $reviewmarks, $reviewspecificfeedback,
            $reviewgeneralfeedback, $reviewrightanswer, $reviewoverallfeedback,
            $questionsperpage, $navmethod, $shuffleanswers, $sumgrades, $grade,
            $password, $subnet, $browsersecurity, $delay1, $delay2,
            $showuserpicture, $showblocks, $completionattemptsexhausted,
            $completionminattempts, $allowofflineattempts, $timecreated, $timemodified
        );
    }
    
    $success = $stmt->execute();
    
    if ($success) {
        logMessage('info', "      ✅ Quiz synced: " . ($data['name'] ?? 'ID: ' . $recordId));
        if (isset($data['timeopen']) && $data['timeopen'] > 0) {
            logMessage('info', "         • Opens: " . date('Y-m-d H:i', $data['timeopen']));
        }
        if (isset($data['timeclose']) && $data['timeclose'] > 0) {
            logMessage('info', "         • Closes: " . date('Y-m-d H:i', $data['timeclose']));
        }
        if (isset($data['timelimit']) && $data['timelimit'] > 0) {
            logMessage('info', "         • Time limit: " . ($data['timelimit'] / 60) . " minutes");
        }
        $stmt->close();
        return true;
    } else {
        logMessage('error', "      ❌ Quiz sync failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Apply quiz delete
 */
function applyQuizDelete($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'quiz';
    $recordId = (int)$data['id'];
    
    $query = "DELETE FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $recordId);
    $success = $stmt->execute();
    
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Quiz deleted (ID: $recordId)");
    } else {
        logMessage('info', "      ℹ️ Quiz not found for delete");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  USER ENROLLMENT CHANGE HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply user enrollment changes
 */
function applyEnrollmentChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'user_enrolments';
    $recordId = (int)$data['id'];
    
    // Check if enrollment exists
    $checkQuery = "SELECT id FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($checkQuery);
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    if ($exists) {
        // Update existing enrollment
        $query = "UPDATE $table SET status = ?, timestart = ?, timeend = ?, timemodified = ? WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        if (!$stmt) {
            logMessage('error', "Failed to prepare enrollment update: " . $mysqli->error);
            return false;
        }
        
        $status = (int)($data['status'] ?? 0);
        $timestart = (int)($data['timestart'] ?? 0);
        $timeend = (int)($data['timeend'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? time());
        
        $stmt->bind_param('iiiii', $status, $timestart, $timeend, $timemodified, $recordId);
        
    } else {
        // Insert new enrollment
        $query = "INSERT INTO $table (id, enrolid, userid, timestart, timeend, status, timecreated, timemodified) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        if (!$stmt) {
            logMessage('error', "Failed to prepare enrollment insert: " . $mysqli->error);
            return false;
        }
        
        $id = (int)$data['id'];
        $enrolid = (int)($data['enrolid'] ?? 0);
        $userid = (int)($data['userid'] ?? 0);
        $timestart = (int)($data['timestart'] ?? 0);
        $timeend = (int)($data['timeend'] ?? 0);
        $status = (int)($data['status'] ?? 0);
        $timecreated = (int)($data['timecreated'] ?? time());
        $timemodified = (int)($data['timemodified'] ?? time());
        
        $stmt->bind_param('iiiiiiii', $id, $enrolid, $userid, $timestart, $timeend, $status, $timecreated, $timemodified);
    }
    
    $success = $stmt->execute();
    
    if ($success) {
        logMessage('info', "      ✅ Enrollment synced for user: " . ($data['userid'] ?? $recordId));
        $stmt->close();
        return true;
    } else {
        logMessage('error', "      ❌ Enrollment sync failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Apply enrollment delete
 */
function applyEnrollmentDelete($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'user_enrolments';
    $recordId = (int)$data['id'];
    
    $query = "DELETE FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $recordId);
    $success = $stmt->execute();
    
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Enrollment deleted (ID: $recordId)");
    } else {
        logMessage('info', "      ℹ️ Enrollment not found for delete");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ASSIGNMENT CHANGE HANDLERS (mdl_assign)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply assignment changes
 */
function applyAssignmentChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'assign';
    $recordId = (int)$data['id'];
    
    // Check if exists
    $stmt = $mysqli->prepare("SELECT id, timemodified FROM $table WHERE id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping assignment - local is newer");
            updateStats('conflict');
            return false;
        }
        
        $query = "UPDATE $table SET 
                  name = ?, intro = ?, duedate = ?, allowsubmissionsfromdate = ?,
                  cutoffdate = ?, gradingduedate = ?, grade = ?, submissiondrafts = ?,
                  sendnotifications = ?, sendlatenotifications = ?, maxattempts = ?,
                  teamsubmission = ?, blindmarking = ?, markingworkflow = ?,
                  timemodified = ? WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $duedate = (int)($data['duedate'] ?? 0);
        $allowsubmissionsfromdate = (int)($data['allowsubmissionsfromdate'] ?? 0);
        $cutoffdate = (int)($data['cutoffdate'] ?? 0);
        $gradingduedate = (int)($data['gradingduedate'] ?? 0);
        $grade = (int)($data['grade'] ?? 100);
        $submissiondrafts = (int)($data['submissiondrafts'] ?? 0);
        $sendnotifications = (int)($data['sendnotifications'] ?? 0);
        $sendlatenotifications = (int)($data['sendlatenotifications'] ?? 0);
        $maxattempts = (int)($data['maxattempts'] ?? -1);
        $teamsubmission = (int)($data['teamsubmission'] ?? 0);
        $blindmarking = (int)($data['blindmarking'] ?? 0);
        $markingworkflow = (int)($data['markingworkflow'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('ssiiiiiiiiiiiii',
            $name, $intro, $duedate, $allowsubmissionsfromdate, $cutoffdate,
            $gradingduedate, $grade, $submissiondrafts, $sendnotifications,
            $sendlatenotifications, $maxattempts, $teamsubmission, $blindmarking,
            $markingworkflow, $timemodified, $recordId
        );
    } else {
        $query = "INSERT INTO $table (id, course, name, intro, duedate, allowsubmissionsfromdate,
                  cutoffdate, grade, timemodified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        $id = (int)$data['id'];
        $course = (int)($data['course'] ?? 0);
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $duedate = (int)($data['duedate'] ?? 0);
        $allowsubmissionsfromdate = (int)($data['allowsubmissionsfromdate'] ?? 0);
        $cutoffdate = (int)($data['cutoffdate'] ?? 0);
        $grade = (int)($data['grade'] ?? 100);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('iissiiiii', $id, $course, $name, $intro, $duedate,
            $allowsubmissionsfromdate, $cutoffdate, $grade, $timemodified);
    }
    
    $success = $stmt->execute();
    if ($success) {
        logMessage('info', "      ✅ Assignment synced: " . ($data['name'] ?? $recordId));
        if (isset($data['duedate']) && $data['duedate'] > 0) {
            logMessage('info', "         • Due: " . date('Y-m-d H:i', $data['duedate']));
        }
    } else {
        logMessage('error', "      ❌ Assignment sync failed: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function applyAssignmentDelete($mysqli, $data) {
    global $config;
    $table = $config['DB_TABLE_PREFIX'] . 'assign';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    $success = $stmt->execute();
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Assignment deleted (ID: {$data['id']})");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  QUESTION BANK CHANGE HANDLERS (mdl_question)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply question changes
 */
function applyQuestionChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'question';
    $recordId = (int)$data['id'];
    
    $stmt = $mysqli->prepare("SELECT id, timemodified FROM $table WHERE id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping question - local is newer");
            updateStats('conflict');
            return false;
        }
        
        $query = "UPDATE $table SET 
                  category = ?, name = ?, questiontext = ?, questiontextformat = ?,
                  generalfeedback = ?, generalfeedbackformat = ?, defaultmark = ?,
                  penalty = ?, qtype = ?, hidden = ?, timemodified = ?, modifiedby = ?
                  WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        $category = (int)($data['category'] ?? 0);
        $name = $data['name'] ?? '';
        $questiontext = $data['questiontext'] ?? '';
        $questiontextformat = (int)($data['questiontextformat'] ?? 0);
        $generalfeedback = $data['generalfeedback'] ?? '';
        $generalfeedbackformat = (int)($data['generalfeedbackformat'] ?? 0);
        $defaultmark = (float)($data['defaultmark'] ?? 1);
        $penalty = (float)($data['penalty'] ?? 0.3333333);
        $qtype = $data['qtype'] ?? 'multichoice';
        $hidden = (int)($data['hidden'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        $modifiedby = (int)($data['modifiedby'] ?? 0);
        
        $stmt->bind_param('issisiidsiii',
            $category, $name, $questiontext, $questiontextformat,
            $generalfeedback, $generalfeedbackformat, $defaultmark,
            $penalty, $qtype, $hidden, $timemodified, $modifiedby, $recordId
        );
    } else {
        $query = "INSERT INTO $table (id, category, name, questiontext, questiontextformat,
                  generalfeedback, generalfeedbackformat, defaultmark, penalty, qtype, hidden,
                  stamp, version, timecreated, timemodified, createdby, modifiedby)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        $id = (int)$data['id'];
        $category = (int)($data['category'] ?? 0);
        $name = $data['name'] ?? '';
        $questiontext = $data['questiontext'] ?? '';
        $questiontextformat = (int)($data['questiontextformat'] ?? 0);
        $generalfeedback = $data['generalfeedback'] ?? '';
        $generalfeedbackformat = (int)($data['generalfeedbackformat'] ?? 0);
        $defaultmark = (float)($data['defaultmark'] ?? 1);
        $penalty = (float)($data['penalty'] ?? 0.3333333);
        $qtype = $data['qtype'] ?? 'multichoice';
        $hidden = (int)($data['hidden'] ?? 0);
        $stamp = $data['stamp'] ?? '';
        $version = $data['version'] ?? '';
        $timecreated = (int)($data['timecreated'] ?? $timestamp);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        $createdby = (int)($data['createdby'] ?? 0);
        $modifiedby = (int)($data['modifiedby'] ?? 0);
        
        $stmt->bind_param('iissisidsisssiii',
            $id, $category, $name, $questiontext, $questiontextformat,
            $generalfeedback, $generalfeedbackformat, $defaultmark, $penalty,
            $qtype, $hidden, $stamp, $version, $timecreated, $timemodified,
            $createdby, $modifiedby
        );
    }
    
    $success = $stmt->execute();
    if ($success) {
        logMessage('info', "      ✅ Question synced: " . ($data['name'] ?? $recordId));
        logMessage('info', "         • Type: " . ($data['qtype'] ?? 'unknown'));
    } else {
        logMessage('error', "      ❌ Question sync failed: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function applyQuestionDelete($mysqli, $data) {
    global $config;
    $table = $config['DB_TABLE_PREFIX'] . 'question';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    $success = $stmt->execute();
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Question deleted (ID: {$data['id']})");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  GRADE ITEMS CHANGE HANDLERS (mdl_grade_items)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply grade item changes
 */
function applyGradeItemChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'grade_items';
    $recordId = (int)$data['id'];
    
    $stmt = $mysqli->prepare("SELECT id, timemodified FROM $table WHERE id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping grade item - local is newer");
            updateStats('conflict');
            return false;
        }
        
        $query = "UPDATE $table SET 
                  itemname = ?, grademax = ?, grademin = ?, gradepass = ?,
                  hidden = ?, locked = ?, timemodified = ? WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        $itemname = $data['itemname'] ?? '';
        $grademax = (float)($data['grademax'] ?? 100);
        $grademin = (float)($data['grademin'] ?? 0);
        $gradepass = (float)($data['gradepass'] ?? 0);
        $hidden = (int)($data['hidden'] ?? 0);
        $locked = (int)($data['locked'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('sdddiii', $itemname, $grademax, $grademin, $gradepass,
            $hidden, $locked, $timemodified, $recordId);
    } else {
        $query = "INSERT INTO $table (id, courseid, categoryid, itemname, itemtype,
                  itemmodule, iteminstance, gradetype, grademax, grademin, gradepass,
                  hidden, locked, timecreated, timemodified)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        $id = (int)$data['id'];
        $courseid = (int)($data['courseid'] ?? 0);
        $categoryid = (int)($data['categoryid'] ?? 0);
        $itemname = $data['itemname'] ?? '';
        $itemtype = $data['itemtype'] ?? 'mod';
        $itemmodule = $data['itemmodule'] ?? '';
        $iteminstance = (int)($data['iteminstance'] ?? 0);
        $gradetype = (int)($data['gradetype'] ?? 1);
        $grademax = (float)($data['grademax'] ?? 100);
        $grademin = (float)($data['grademin'] ?? 0);
        $gradepass = (float)($data['gradepass'] ?? 0);
        $hidden = (int)($data['hidden'] ?? 0);
        $locked = (int)($data['locked'] ?? 0);
        $timecreated = (int)($data['timecreated'] ?? $timestamp);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('iiisssiiiddddii', $id, $courseid, $categoryid, $itemname,
            $itemtype, $itemmodule, $iteminstance, $gradetype, $grademax, $grademin,
            $gradepass, $hidden, $locked, $timecreated, $timemodified);
    }
    
    $success = $stmt->execute();
    if ($success) {
        logMessage('info', "      ✅ Grade item synced: " . ($data['itemname'] ?? $recordId));
    } else {
        logMessage('error', "      ❌ Grade item sync failed: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function applyGradeItemDelete($mysqli, $data) {
    global $config;
    $table = $config['DB_TABLE_PREFIX'] . 'grade_items';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    $success = $stmt->execute();
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Grade item deleted (ID: {$data['id']})");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  GRADE GRADES CHANGE HANDLERS (mdl_grade_grades) - Student Grades
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply student grade changes
 */
function applyGradeGradeChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'grade_grades';
    $recordId = (int)$data['id'];
    
    $stmt = $mysqli->prepare("SELECT id, timemodified FROM $table WHERE id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping grade - local is newer");
            updateStats('conflict');
            return false;
        }
        
        $query = "UPDATE $table SET 
                  rawgrade = ?, finalgrade = ?, feedback = ?, feedbackformat = ?,
                  hidden = ?, locked = ?, overridden = ?, excluded = ?,
                  usermodified = ?, timemodified = ? WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        $rawgrade = isset($data['rawgrade']) ? (float)$data['rawgrade'] : null;
        $finalgrade = isset($data['finalgrade']) ? (float)$data['finalgrade'] : null;
        $feedback = $data['feedback'] ?? '';
        $feedbackformat = (int)($data['feedbackformat'] ?? 0);
        $hidden = (int)($data['hidden'] ?? 0);
        $locked = (int)($data['locked'] ?? 0);
        $overridden = (int)($data['overridden'] ?? 0);
        $excluded = (int)($data['excluded'] ?? 0);
        $usermodified = (int)($data['usermodified'] ?? 0);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('ddsiiiiiii', $rawgrade, $finalgrade, $feedback, $feedbackformat,
            $hidden, $locked, $overridden, $excluded, $usermodified, $timemodified, $recordId);
    } else {
        $query = "INSERT INTO $table (id, itemid, userid, rawgrade, rawgrademax, rawgrademin,
                  finalgrade, feedback, feedbackformat, hidden, locked, overridden, excluded,
                  usermodified, timecreated, timemodified)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        $id = (int)$data['id'];
        $itemid = (int)($data['itemid'] ?? 0);
        $userid = (int)($data['userid'] ?? 0);
        $rawgrade = isset($data['rawgrade']) ? (float)$data['rawgrade'] : null;
        $rawgrademax = (float)($data['rawgrademax'] ?? 100);
        $rawgrademin = (float)($data['rawgrademin'] ?? 0);
        $finalgrade = isset($data['finalgrade']) ? (float)$data['finalgrade'] : null;
        $feedback = $data['feedback'] ?? '';
        $feedbackformat = (int)($data['feedbackformat'] ?? 0);
        $hidden = (int)($data['hidden'] ?? 0);
        $locked = (int)($data['locked'] ?? 0);
        $overridden = (int)($data['overridden'] ?? 0);
        $excluded = (int)($data['excluded'] ?? 0);
        $usermodified = (int)($data['usermodified'] ?? 0);
        $timecreated = (int)($data['timecreated'] ?? $timestamp);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('iiiddddsiiiiiii', $id, $itemid, $userid, $rawgrade,
            $rawgrademax, $rawgrademin, $finalgrade, $feedback, $feedbackformat,
            $hidden, $locked, $overridden, $excluded, $usermodified, $timecreated, $timemodified);
    }
    
    $success = $stmt->execute();
    if ($success) {
        logMessage('info', "      ✅ Grade synced for user {$data['userid']}: " . 
            ($data['finalgrade'] ?? $data['rawgrade'] ?? 'N/A'));
    } else {
        logMessage('error', "      ❌ Grade sync failed: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function applyGradeGradeDelete($mysqli, $data) {
    global $config;
    $table = $config['DB_TABLE_PREFIX'] . 'grade_grades';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    $success = $stmt->execute();
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Grade deleted (ID: {$data['id']})");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  FORUM CHANGE HANDLERS (mdl_forum)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply forum changes
 */
function applyForumChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'forum';
    $recordId = (int)$data['id'];
    
    $stmt = $mysqli->prepare("SELECT id, timemodified FROM $table WHERE id = ?");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        if (!shouldApplyChange($existing['timemodified'], $timestamp)) {
            logMessage('info', "      ⏭️ Skipping forum - local is newer");
            updateStats('conflict');
            return false;
        }
        
        $query = "UPDATE $table SET 
                  name = ?, intro = ?, introformat = ?, type = ?,
                  duedate = ?, cutoffdate = ?, forcesubscribe = ?,
                  trackingtype = ?, timemodified = ? WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $introformat = (int)($data['introformat'] ?? 0);
        $type = $data['type'] ?? 'general';
        $duedate = (int)($data['duedate'] ?? 0);
        $cutoffdate = (int)($data['cutoffdate'] ?? 0);
        $forcesubscribe = (int)($data['forcesubscribe'] ?? 0);
        $trackingtype = (int)($data['trackingtype'] ?? 1);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('ssissiiii', $name, $intro, $introformat, $type,
            $duedate, $cutoffdate, $forcesubscribe, $trackingtype, $timemodified, $recordId);
    } else {
        $query = "INSERT INTO $table (id, course, name, intro, introformat, type,
                  duedate, cutoffdate, forcesubscribe, trackingtype, timemodified)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($query);
        
        $id = (int)$data['id'];
        $course = (int)($data['course'] ?? 0);
        $name = $data['name'] ?? '';
        $intro = $data['intro'] ?? '';
        $introformat = (int)($data['introformat'] ?? 0);
        $type = $data['type'] ?? 'general';
        $duedate = (int)($data['duedate'] ?? 0);
        $cutoffdate = (int)($data['cutoffdate'] ?? 0);
        $forcesubscribe = (int)($data['forcesubscribe'] ?? 0);
        $trackingtype = (int)($data['trackingtype'] ?? 1);
        $timemodified = (int)($data['timemodified'] ?? $timestamp);
        
        $stmt->bind_param('iississiiii', $id, $course, $name, $intro, $introformat,
            $type, $duedate, $cutoffdate, $forcesubscribe, $trackingtype, $timemodified);
    }
    
    $success = $stmt->execute();
    if ($success) {
        logMessage('info', "      ✅ Forum synced: " . ($data['name'] ?? $recordId));
        logMessage('info', "         • Type: " . ($data['type'] ?? 'general'));
    } else {
        logMessage('error', "      ❌ Forum sync failed: " . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function applyForumDelete($mysqli, $data) {
    global $config;
    $table = $config['DB_TABLE_PREFIX'] . 'forum';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    $success = $stmt->execute();
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Forum deleted (ID: {$data['id']})");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  COURSE MODULES CHANGE HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply course module changes (activities like quiz, assignment, etc.)
 */
function applyCourseModuleChange($mysqli, $operation, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'course_modules';
    $recordId = (int)$data['id'];
    
    // Check if module exists
    $checkQuery = "SELECT id FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($checkQuery);
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    if ($exists) {
        // Update existing module
        $query = "UPDATE $table SET 
                  course = ?, module = ?, instance = ?, section = ?,
                  idnumber = ?, score = ?, indent = ?, visible = ?,
                  visibleoncoursepage = ?, visibleold = ?, groupmode = ?,
                  groupingid = ?, completion = ?, completiongradeitemnumber = ?,
                  completionview = ?, completionexpected = ?, showdescription = ?,
                  availability = ?, deletioninprogress = ?
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            logMessage('error', "Failed to prepare module update: " . $mysqli->error);
            return false;
        }
        
        $course = (int)($data['course'] ?? 0);
        $module = (int)($data['module'] ?? 0);
        $instance = (int)($data['instance'] ?? 0);
        $section = (int)($data['section'] ?? 0);
        $idnumber = $data['idnumber'] ?? '';
        $score = (int)($data['score'] ?? 0);
        $indent = (int)($data['indent'] ?? 0);
        $visible = (int)($data['visible'] ?? 1);
        $visibleoncoursepage = (int)($data['visibleoncoursepage'] ?? 1);
        $visibleold = (int)($data['visibleold'] ?? 1);
        $groupmode = (int)($data['groupmode'] ?? 0);
        $groupingid = (int)($data['groupingid'] ?? 0);
        $completion = (int)($data['completion'] ?? 0);
        $completiongradeitemnumber = isset($data['completiongradeitemnumber']) ? (int)$data['completiongradeitemnumber'] : null;
        $completionview = (int)($data['completionview'] ?? 0);
        $completionexpected = (int)($data['completionexpected'] ?? 0);
        $showdescription = (int)($data['showdescription'] ?? 0);
        $availability = $data['availability'] ?? '';
        $deletioninprogress = (int)($data['deletioninprogress'] ?? 0);
        
        $stmt->bind_param('iiiisiiiiiiiiiiisis',
            $course, $module, $instance, $section, $idnumber, $score, $indent,
            $visible, $visibleoncoursepage, $visibleold, $groupmode, $groupingid,
            $completion, $completiongradeitemnumber, $completionview, $completionexpected,
            $showdescription, $availability, $deletioninprogress, $recordId
        );
        
    } else {
        // Insert new module
        $query = "INSERT INTO $table (
                  id, course, module, instance, section, idnumber, added,
                  score, indent, visible, visibleoncoursepage, visibleold,
                  groupmode, groupingid, completion, completiongradeitemnumber,
                  completionview, completionexpected, showdescription, availability,
                  deletioninprogress
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            logMessage('error', "Failed to prepare module insert: " . $mysqli->error);
            return false;
        }
        
        $id = (int)$data['id'];
        $course = (int)($data['course'] ?? 0);
        $module = (int)($data['module'] ?? 0);
        $instance = (int)($data['instance'] ?? 0);
        $section = (int)($data['section'] ?? 0);
        $idnumber = $data['idnumber'] ?? '';
        $added = (int)($data['added'] ?? time());
        $score = (int)($data['score'] ?? 0);
        $indent = (int)($data['indent'] ?? 0);
        $visible = (int)($data['visible'] ?? 1);
        $visibleoncoursepage = (int)($data['visibleoncoursepage'] ?? 1);
        $visibleold = (int)($data['visibleold'] ?? 1);
        $groupmode = (int)($data['groupmode'] ?? 0);
        $groupingid = (int)($data['groupingid'] ?? 0);
        $completion = (int)($data['completion'] ?? 0);
        $completiongradeitemnumber = isset($data['completiongradeitemnumber']) ? (int)$data['completiongradeitemnumber'] : null;
        $completionview = (int)($data['completionview'] ?? 0);
        $completionexpected = (int)($data['completionexpected'] ?? 0);
        $showdescription = (int)($data['showdescription'] ?? 0);
        $availability = $data['availability'] ?? '';
        $deletioninprogress = (int)($data['deletioninprogress'] ?? 0);
        
        $stmt->bind_param('iiiisiiiiiiiiiiiiisi',
            $id, $course, $module, $instance, $section, $idnumber, $added,
            $score, $indent, $visible, $visibleoncoursepage, $visibleold,
            $groupmode, $groupingid, $completion, $completiongradeitemnumber,
            $completionview, $completionexpected, $showdescription, $availability,
            $deletioninprogress
        );
    }
    
    $success = $stmt->execute();
    
    if ($success) {
        logMessage('info', "      ✅ Course module synced (ID: $recordId)");
        logMessage('info', "         • Course: {$data['course']}, Section: {$data['section']}");
        $stmt->close();
        return true;
    } else {
        logMessage('error', "      ❌ Course module sync failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

/**
 * Apply course module delete
 */
function applyCourseModuleDelete($mysqli, $data, $timestamp) {
    global $config;
    
    $table = $config['DB_TABLE_PREFIX'] . 'course_modules';
    $recordId = (int)$data['id'];
    
    $query = "DELETE FROM $table WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $recordId);
    $success = $stmt->execute();
    
    if ($success && $stmt->affected_rows > 0) {
        logMessage('info', "      ✅ Course module deleted (ID: $recordId)");
    } else {
        logMessage('info', "      ℹ️ Course module not found for delete");
    }
    $stmt->close();
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN DATABASE CHANGE ROUTER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Route a database change to the appropriate handler
 */
function applyDatabaseChange($mysqli, $message) {
    global $config;
    
    $table = $message['table'];
    $operation = $message['operation'];
    $data = $message['data'];
    $timestamp = (int)$message['timestamp'];
    
    // Handle COURSE changes
    if ($table === 'mdl_course') {
        switch ($operation) {
            case 'UPDATE':
                return applyCourseUpdate($mysqli, $data, $timestamp);
            case 'INSERT':
                return applyCourseInsert($mysqli, $data, $timestamp);
            case 'DELETE':
                return applyCourseDelete($mysqli, $data, $timestamp);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle QUIZ changes
    if ($table === 'mdl_quiz') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyQuizChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyQuizDelete($mysqli, $data, $timestamp);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle COURSE MODULES changes (activities)
    if ($table === 'mdl_course_modules') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyCourseModuleChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyCourseModuleDelete($mysqli, $data, $timestamp);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle USER ENROLLMENT changes
    if ($table === 'mdl_user_enrolments') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyEnrollmentChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyEnrollmentDelete($mysqli, $data, $timestamp);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle ASSIGNMENT changes
    if ($table === 'mdl_assign') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyAssignmentChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyAssignmentDelete($mysqli, $data);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle QUESTION changes
    if ($table === 'mdl_question') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyQuestionChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyQuestionDelete($mysqli, $data);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle GRADE ITEMS changes
    if ($table === 'mdl_grade_items') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyGradeItemChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyGradeItemDelete($mysqli, $data);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle GRADE GRADES (student grades) changes
    if ($table === 'mdl_grade_grades') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyGradeGradeChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyGradeGradeDelete($mysqli, $data);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    // Handle FORUM changes
    if ($table === 'mdl_forum') {
        switch ($operation) {
            case 'UPDATE':
            case 'INSERT':
                return applyForumChange($mysqli, $operation, $data, $timestamp);
            case 'DELETE':
                return applyForumDelete($mysqli, $data);
            default:
                logMessage('warn', "      ⚠️ Unknown operation: $operation");
                return false;
        }
    }
    
    logMessage('warn', "      ⚠️ Unsupported table: $table");
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MESSAGE PROCESSING
// ═══════════════════════════════════════════════════════════════════════════════

function processMessage($mysqli, $rawMessage) {
    global $config;
    static $changeCounter = 0;
    
    updateStats('received');
    
    $message = json_decode($rawMessage, true);
    
    if ($message === null) {
        logMessage('warn', 'Received invalid JSON message');
        return;
    }
    
    $type = $message['type'] ?? 'unknown';
    
    switch ($type) {
        case 'welcome':
        case 'room_joined':
        case 'ack':
        case 'pong':
            logMessage('debug', "Received $type message", $message);
            break;
            
        case 'sync_response':
            // Handle catch-up response (missed changes)
            $changes = $message['data'] ?? [];
            logMessage('info', '═══════════════════════════════════════════════════════════');
            logMessage('info', "📥 CATCH-UP: Received " . count($changes) . " missed changes");
            
            foreach ($changes as $change) {
                // Skip our own changes
                if (($change['source_vm'] ?? '') === $config['VM_NAME']) {
                    continue;
                }
                
                // Apply each missed change
                if (isset($change['table']) && isset($change['operation'])) {
                    logMessage('info', "   ↪ Applying: {$change['operation']} on {$change['table']}");
                    applyDatabaseChange($mysqli, $change);
                    updateStats('catchup');
                }
            }
            
            // Save current time as last sync
            saveLastSyncTime(time());
            logMessage('info', "✅ Catch-up complete!");
            logMessage('info', '═══════════════════════════════════════════════════════════');
            break;
            
        case 'database_change':
            $changeCounter++;
            $sourceVM = $message['source_vm'] ?? 'unknown';
            
            logMessage('info', '═══════════════════════════════════════════════════════════');
            logMessage('info', "📥 [$changeCounter] Received change from $sourceVM:");
            logMessage('info', "      📋 Table: {$message['table']}");
            logMessage('info', "      ⚡ Operation: {$message['operation']}");
            logMessage('info', "      🆔 Record ID: {$message['record_id']}");
            logMessage('info', "      📅 Timestamp: {$message['timestamp']}");
            
            // Check if this is from our own VM
            if ($sourceVM === $config['VM_NAME']) {
                logMessage('info', "      ⏭️ Skipping - change originated from this VM");
                updateStats('skipped');
                logMessage('info', '═══════════════════════════════════════════════════════════');
                return;
            }
            
            // Apply the change
            $success = applyDatabaseChange($mysqli, $message);
            
            if ($success) {
                updateStats('applied', [
                    'table' => $message['table'],
                    'operation' => $message['operation'],
                    'source_vm' => $sourceVM
                ]);
                // Update last sync time
                saveLastSyncTime(time());
            } else {
                updateStats('error');
            }
            
            logMessage('info', '═══════════════════════════════════════════════════════════');
            break;
            
        case 'edit_mode_enabled':
            $sourceVM = $message['source_vm'] ?? 'unknown';
            logMessage('info', "✏️ Edit mode enabled on $sourceVM");
            break;
            
        case 'server_shutdown':
            logMessage('warn', '⚠️ Server is shutting down');
            break;
            
        default:
            logMessage('debug', "Received unknown message type: $type", $message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN DAEMON LOOP
// ═══════════════════════════════════════════════════════════════════════════════

function runDaemon() {
    global $config;
    
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    logMessage('info', '  🚀 MOODLE SYNC RECEIVER DAEMON v4.0 (FULL SYNC)');
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    logMessage('info', "  📌 VM: {$config['VM_NAME']}");
    logMessage('info', "  🎯 Yjs Server: {$config['YJS_SERVER']}");
    logMessage('info', "  ⚖️ Conflict Strategy: {$config['CONFLICT_STRATEGY']}");
    logMessage('info', '  📋 Supported Tables:');
    logMessage('info', '     • mdl_course (Courses)');
    logMessage('info', '     • mdl_quiz (Quizzes)');
    logMessage('info', '     • mdl_assign (Assignments)');
    logMessage('info', '     • mdl_question (Question Bank)');
    logMessage('info', '     • mdl_grade_items (Grade Items)');
    logMessage('info', '     • mdl_grade_grades (Student Grades)');
    logMessage('info', '     • mdl_forum (Forums)');
    logMessage('info', '     • mdl_course_modules (Activities)');
    logMessage('info', '     • mdl_user_enrolments (Enrollments)');
    logMessage('info', '  🔄 Catch-up: Enabled');
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    logMessage('info', '  📋 Listening for database changes...');
    logMessage('info', '');
    
    $mysqli = null;
    $wsClient = null;
    $lastHeartbeat = time();
    
    while (true) {
        try {
            // Ensure database connection
            if ($mysqli === null || !$mysqli->ping()) {
                $mysqli = connectToDatabase();
                if ($mysqli === null) {
                    logMessage('error', 'Database connection failed, retrying in 5 seconds...');
                    usleep($config['RECONNECT_DELAY_MS'] * 1000);
                    continue;
                }
            }
            
            // Ensure WebSocket connection
            if ($wsClient === null) {
                $wsClient = connectToYjs();
                if ($wsClient === null) {
                    logMessage('error', 'WebSocket connection failed, retrying in 5 seconds...');
                    usleep($config['RECONNECT_DELAY_MS'] * 1000);
                    updateStats('reconnect');
                    continue;
                }
            }
            
            // Send heartbeat periodically
            if (time() - $lastHeartbeat >= $config['HEARTBEAT_INTERVAL']) {
                try {
                    $wsClient->send(json_encode([
                        'type' => 'ping',
                        'vm_name' => $config['VM_NAME'],
                        'timestamp' => time()
                    ]));
                    $lastHeartbeat = time();
                    logMessage('debug', 'Heartbeat sent');
                } catch (Exception $e) {
                    logMessage('warn', 'Heartbeat failed, reconnecting...');
                    $wsClient = null;
                    continue;
                }
            }
            
            // Try to receive a message
            try {
                $message = $wsClient->receive();
                
                if ($message !== null && $message !== '') {
                    processMessage($mysqli, $message);
                }
                
            } catch (WebSocket\TimeoutException $e) {
                logMessage('debug', 'Receive timeout (normal)');
            }
            
        } catch (Exception $e) {
            logMessage('error', 'Daemon error: ' . $e->getMessage());
            $wsClient = null;
            updateStats('error');
            usleep($config['RECONNECT_DELAY_MS'] * 1000);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SIGNAL HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

declare(ticks=1);

function signalHandler($signal) {
    logMessage('info', '🛑 Received shutdown signal, stopping daemon...');
    logMessage('info', '📊 Final statistics:', getFormattedStats());
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
}

// ═══════════════════════════════════════════════════════════════════════════════
//  START DAEMON
// ═══════════════════════════════════════════════════════════════════════════════

if (php_sapi_name() === 'cli') {
    runDaemon();
} else {
    die('This script must be run from the command line.');
}
