<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  MOODLE SYNC SENDER DAEMON
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  Project: Eventual Consistency in Distributed Moodle Learning Management Systems
 *  Component: Database Change Sender
 *  Technology: PHP 8.4 + WebSocket (textalk/websocket)
 * 
 *  Purpose:
 *  - Monitor the sync queue table for pending changes
 *  - Send changes to the central Yjs WebSocket server
 *  - Mark changes as synced after successful transmission
 * 
 *  Usage:
 *  - Run as daemon: php moodle-sync-sender.php
 *  - Or via systemd service
 * 
 *  Author: Distributed Moodle Sync Project
 *  Version: 2.0.0
 *  Last Updated: November 2025
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
    'YJS_SERVER' => 'ws://172.20.10.4:2345',
    
    // Sync Configuration
    'POLL_INTERVAL_MS' => 200,        // Check queue every 200ms
    'BATCH_SIZE' => 50,               // Max changes to process per batch
    'RECONNECT_DELAY_MS' => 5000,     // Wait 5s before reconnecting
    'MAX_RETRIES' => 3,               // Max retries per message
    'HEARTBEAT_INTERVAL' => 30,       // Send heartbeat every 30s
    
    // Logging
    'LOG_LEVEL' => 'info',            // debug, info, warn, error
    'LOG_FILE' => null,               // null = stdout, or path to file
];

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
    'messagesProcessed' => 0,
    'messagesSent' => 0,
    'messagesFaild' => 0,
    'reconnections' => 0,
    'byTable' => [],
    'byOperation' => ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0],
];

function updateStats($type, $data = []) {
    global $stats;
    
    switch ($type) {
        case 'sent':
            $stats['messagesProcessed']++;
            $stats['messagesSent']++;
            if (isset($data['table'])) {
                $stats['byTable'][$data['table']] = ($stats['byTable'][$data['table']] ?? 0) + 1;
            }
            if (isset($data['operation'])) {
                $stats['byOperation'][$data['operation']] = ($stats['byOperation'][$data['operation']] ?? 0) + 1;
            }
            break;
        case 'failed':
            $stats['messagesProcessed']++;
            $stats['messagesFailed']++;
            break;
        case 'reconnect':
            $stats['reconnections']++;
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
        'messagesProcessed' => $stats['messagesProcessed'],
        'messagesSent' => $stats['messagesSent'],
        'messagesFailed' => $stats['messagesFailed'] ?? 0,
        'reconnections' => $stats['reconnections'],
        'byTable' => $stats['byTable'],
        'byOperation' => $stats['byOperation'],
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
//  WEBSOCKET CONNECTION
// ═══════════════════════════════════════════════════════════════════════════════

function connectToYjs() {
    global $config;
    
    logMessage('info', 'Connecting to Yjs server: ' . $config['YJS_SERVER']);
    
    try {
        $client = new Client($config['YJS_SERVER'], [
            'timeout' => 60,
            'headers' => [
                'X-VM-Name' => $config['VM_NAME'],
                'X-Client-Type' => 'sender'
            ]
        ]);
        
        // Send join message
        $joinMessage = json_encode([
            'type' => 'join',
            'room' => 'moodle-database-sync',
            'vm_name' => $config['VM_NAME'],
            'client_type' => 'sender',
            'timestamp' => time()
        ]);
        
        $client->send($joinMessage);
        
        // Read welcome message
        try {
            $response = $client->receive();
            $data = json_decode($response, true);
            if ($data && $data['type'] === 'welcome') {
                logMessage('info', 'Successfully connected and joined room', $data);
            }
        } catch (Exception $e) {
            // Non-blocking, continue anyway
            logMessage('debug', 'No welcome message received');
        }
        
        logMessage('info', 'WebSocket connection established');
        return $client;
        
    } catch (Exception $e) {
        logMessage('error', 'WebSocket connection failed: ' . $e->getMessage());
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  QUEUE PROCESSING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Fetch pending changes from the sync queue
 */
function fetchPendingChanges($mysqli) {
    global $config;
    
    $vmName = $config['VM_NAME'];
    $batchSize = $config['BATCH_SIZE'];
    
    $query = "SELECT * FROM {$config['DB_TABLE_PREFIX']}yjs_sync_queue 
              WHERE synced = 0 AND source_vm = ?
              ORDER BY timestamp ASC, id ASC
              LIMIT ?";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('si', $vmName, $batchSize);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $changes = [];
    
    while ($row = $result->fetch_assoc()) {
        $changes[] = $row;
    }
    
    $stmt->close();
    return $changes;
}

/**
 * Mark a change as synced in the database
 */
function markAsSynced($mysqli, $id) {
    global $config;
    
    $query = "UPDATE {$config['DB_TABLE_PREFIX']}yjs_sync_queue 
              SET synced = 1 
              WHERE id = ?";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Send a database change to the Yjs server
 */
function sendChange($client, $change) {
    global $config;
    
    // Parse the JSON data
    $data = json_decode($change['data_json'], true);
    
    $message = [
        'type' => 'database_change',
        'table' => $change['table_name'],
        'operation' => $change['operation'],
        'record_id' => (int)$change['record_id'],
        'data' => $data,
        'source_vm' => $change['source_vm'],
        'timestamp' => (int)$change['timestamp'],
        'queue_id' => (int)$change['id']
    ];
    
    $jsonMessage = json_encode($message, JSON_UNESCAPED_UNICODE);
    
    try {
        $client->send($jsonMessage);
        return true;
    } catch (Exception $e) {
        logMessage('error', 'Failed to send message: ' . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN DAEMON LOOP
// ═══════════════════════════════════════════════════════════════════════════════

function runDaemon() {
    global $config;
    
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    logMessage('info', '  🚀 MOODLE SYNC SENDER DAEMON STARTING');
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    logMessage('info', "  📌 VM: {$config['VM_NAME']}");
    logMessage('info', "  🎯 Yjs Server: {$config['YJS_SERVER']}");
    logMessage('info', "  ⏱️  Poll Interval: {$config['POLL_INTERVAL_MS']}ms");
    logMessage('info', '═══════════════════════════════════════════════════════════════');
    
    $mysqli = null;
    $wsClient = null;
    $lastHeartbeat = time();
    $changeCounter = 0;
    
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
            
            // Fetch pending changes
            $changes = fetchPendingChanges($mysqli);
            
            if (count($changes) > 0) {
                logMessage('info', '═══════════════════════════════════════════════════════════');
                logMessage('info', '📤 Processing ' . count($changes) . ' pending change(s)');
                
                foreach ($changes as $change) {
                    $changeCounter++;
                    
                    logMessage('info', "📤 [$changeCounter] Sending {$change['operation']} on {$change['table_name']} (ID: {$change['record_id']})");
                    
                    // Parse and log the data
                    $data = json_decode($change['data_json'], true);
                    if (isset($data['fullname'])) {
                        logMessage('info', "      📝 Fullname: {$data['fullname']}");
                    }
                    if (isset($data['shortname'])) {
                        logMessage('info', "      📝 Shortname: {$data['shortname']}");
                    }
                    
                    // Send the change
                    $success = sendChange($wsClient, $change);
                    
                    if ($success) {
                        // Mark as synced
                        if (markAsSynced($mysqli, $change['id'])) {
                            logMessage('info', '      ✅ Sent and marked as synced');
                            updateStats('sent', [
                                'table' => $change['table_name'],
                                'operation' => $change['operation']
                            ]);
                        } else {
                            logMessage('warn', '      ⚠️ Sent but failed to mark as synced');
                        }
                    } else {
                        logMessage('error', '      ❌ Failed to send');
                        updateStats('failed');
                        
                        // Reset WebSocket connection on failure
                        $wsClient = null;
                        break;
                    }
                }
                
                logMessage('info', '═══════════════════════════════════════════════════════════');
            }
            
            // Sleep before next poll
            usleep($config['POLL_INTERVAL_MS'] * 1000);
            
        } catch (Exception $e) {
            logMessage('error', 'Daemon error: ' . $e->getMessage());
            $wsClient = null;
            usleep($config['RECONNECT_DELAY_MS'] * 1000);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SIGNAL HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

// Handle graceful shutdown
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

// Verify this file is being run directly
if (php_sapi_name() === 'cli') {
    runDaemon();
} else {
    die('This script must be run from the command line.');
}



