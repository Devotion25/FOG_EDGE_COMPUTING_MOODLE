/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  YJS WEBSOCKET SERVER - DISTRIBUTED MOODLE DATABASE SYNCHRONIZATION
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  Project: Eventual Consistency in Distributed Moodle Learning Management Systems
 *  Component: Central WebSocket Message Broker
 *  Technology: Node.js + Yjs CRDT + ws WebSocket Library
 *  Port: 2345
 *  Location: VM1 (172.20.10.4) - Central Hub
 * 
 *  Purpose:
 *  - Accept WebSocket connections from all VMs (senders & receivers)
 *  - Maintain rooms for organized message routing
 *  - Broadcast database changes to all clients EXCEPT the sender
 *  - Provide CRDT infrastructure for conflict-free replication
 * 
 *  Author: Distributed Moodle Sync Project
 *  Version: 2.0.0
 *  Last Updated: November 2025
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const WebSocket = require('ws');
const Y = require('yjs');
const http = require('http');

// ═══════════════════════════════════════════════════════════════════════════════
//  CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════════

const CONFIG = {
    PORT: 2345,
    HOST: '0.0.0.0',
    HEARTBEAT_INTERVAL: 30000,      // 30 seconds
    CLIENT_TIMEOUT: 60000,          // 60 seconds
    MAX_MESSAGE_SIZE: 10 * 1024 * 1024,  // 10MB
    DEFAULT_ROOM: 'moodle-database-sync',
    LOG_LEVEL: 'info'  // 'debug', 'info', 'warn', 'error'
};

// ═══════════════════════════════════════════════════════════════════════════════
//  LOGGING UTILITIES
// ═══════════════════════════════════════════════════════════════════════════════

const LOG_LEVELS = { debug: 0, info: 1, warn: 2, error: 3 };

function log(level, ...args) {
    if (LOG_LEVELS[level] >= LOG_LEVELS[CONFIG.LOG_LEVEL]) {
        const timestamp = new Date().toISOString();
        const prefix = {
            debug: '🔍',
            info: '📘',
            warn: '⚠️',
            error: '❌'
        }[level] || '📘';
        console.log(`[${timestamp}] ${prefix}`, ...args);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  STATISTICS TRACKER
// ═══════════════════════════════════════════════════════════════════════════════

const stats = {
    startTime: Date.now(),
    totalConnections: 0,
    activeConnections: 0,
    messagesReceived: 0,
    messagesBroadcasted: 0,
    databaseChanges: {
        total: 0,
        byTable: {},
        byOperation: { INSERT: 0, UPDATE: 0, DELETE: 0 },
        byVM: { VM1: 0, VM2: 0, VM3: 0 }
    },
    errors: 0
};

function updateStats(type, data = {}) {
    switch (type) {
        case 'connection':
            stats.totalConnections++;
            stats.activeConnections++;
            break;
        case 'disconnection':
            stats.activeConnections--;
            break;
        case 'message':
            stats.messagesReceived++;
            break;
        case 'broadcast':
            stats.messagesBroadcasted += data.count || 1;
            break;
        case 'database_change':
            stats.databaseChanges.total++;
            stats.databaseChanges.byTable[data.table] = 
                (stats.databaseChanges.byTable[data.table] || 0) + 1;
            if (data.operation) {
                stats.databaseChanges.byOperation[data.operation]++;
            }
            if (data.source_vm) {
                stats.databaseChanges.byVM[data.source_vm] = 
                    (stats.databaseChanges.byVM[data.source_vm] || 0) + 1;
            }
            break;
        case 'error':
            stats.errors++;
            break;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ROOM MANAGEMENT (Yjs Documents)
// ═══════════════════════════════════════════════════════════════════════════════

// Map of room names to their Yjs documents and connections
const rooms = new Map();

/**
 * Get or create a room with Yjs document
 * @param {string} roomName - Name of the room
 * @returns {Object} Room object with ydoc and connections
 */
function getOrCreateRoom(roomName) {
    if (!rooms.has(roomName)) {
        const ydoc = new Y.Doc();
        
        // Initialize shared data structures
        const syncLog = ydoc.getArray('syncLog');
        const metadata = ydoc.getMap('metadata');
        
        metadata.set('created', Date.now());
        metadata.set('roomName', roomName);
        
        rooms.set(roomName, {
            ydoc: ydoc,
            connections: new Set(),
            syncLog: syncLog,
            metadata: metadata,
            created: Date.now(),
            lastActivity: Date.now()
        });
        
        log('info', `🏠 Created new room: ${roomName}`);
    }
    
    return rooms.get(roomName);
}

/**
 * Add a client to a room
 * @param {WebSocket} ws - WebSocket connection
 * @param {string} roomName - Room to join
 */
function joinRoom(ws, roomName) {
    const room = getOrCreateRoom(roomName);
    room.connections.add(ws);
    room.lastActivity = Date.now();
    ws.currentRoom = roomName;
    
    log('info', `👤 Client joined room "${roomName}" (${room.connections.size} total)`);
    
    // Send room info to client
    sendToClient(ws, {
        type: 'room_joined',
        room: roomName,
        clients: room.connections.size,
        timestamp: Date.now()
    });
}

/**
 * Remove a client from their current room
 * @param {WebSocket} ws - WebSocket connection
 */
function leaveRoom(ws) {
    if (ws.currentRoom && rooms.has(ws.currentRoom)) {
        const room = rooms.get(ws.currentRoom);
        room.connections.delete(ws);
        
        log('info', `👤 Client left room "${ws.currentRoom}" (${room.connections.size} remaining)`);
        
        // Clean up empty rooms (except default)
        if (room.connections.size === 0 && ws.currentRoom !== CONFIG.DEFAULT_ROOM) {
            rooms.delete(ws.currentRoom);
            log('info', `🗑️ Removed empty room: ${ws.currentRoom}`);
        }
        
        ws.currentRoom = null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MESSAGE HANDLING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Send a message to a specific client
 * @param {WebSocket} ws - Target WebSocket connection
 * @param {Object} data - Data to send
 */
function sendToClient(ws, data) {
    if (ws.readyState === WebSocket.OPEN) {
        try {
            ws.send(JSON.stringify(data));
        } catch (error) {
            log('error', 'Failed to send to client:', error.message);
        }
    }
}

/**
 * Broadcast a message to all clients in a room except the sender
 * @param {string} roomName - Target room
 * @param {WebSocket} sender - Sender to exclude
 * @param {string} message - Message to broadcast
 * @returns {number} Number of clients that received the message
 */
function broadcastToRoom(roomName, sender, message) {
    if (!rooms.has(roomName)) {
        return 0;
    }
    
    const room = rooms.get(roomName);
    let sentCount = 0;
    
    room.connections.forEach(client => {
        if (client !== sender && client.readyState === WebSocket.OPEN) {
            try {
                client.send(message);
                sentCount++;
            } catch (error) {
                log('error', 'Broadcast failed to client:', error.message);
            }
        }
    });
    
    room.lastActivity = Date.now();
    updateStats('broadcast', { count: sentCount });
    
    return sentCount;
}

/**
 * Process incoming messages
 * @param {WebSocket} ws - Source WebSocket connection
 * @param {string} message - Raw message string
 */
function handleMessage(ws, message) {
    updateStats('message');
    
    let data;
    try {
        data = JSON.parse(message);
    } catch (error) {
        log('warn', 'Invalid JSON received:', message.substring(0, 100));
        return;
    }
    
    // Handle different message types
    switch (data.type) {
        case 'join':
            handleJoin(ws, data);
            break;
            
        case 'leave':
            leaveRoom(ws);
            break;
            
        case 'database_change':
            handleDatabaseChange(ws, data, message);
            break;
            
        case 'edit_mode_enabled':
            handleEditModeEnabled(ws, data, message);
            break;
            
        case 'sync_request':
            handleSyncRequest(ws, data);
            break;
            
        case 'ping':
            sendToClient(ws, { type: 'pong', timestamp: Date.now() });
            break;
            
        case 'get_stats':
            sendToClient(ws, { type: 'stats', data: getFormattedStats() });
            break;
            
        default:
            // Forward unknown messages to room (for extensibility)
            if (ws.currentRoom) {
                broadcastToRoom(ws.currentRoom, ws, message);
            }
    }
}

/**
 * Handle room join requests
 */
function handleJoin(ws, data) {
    const roomName = data.room || CONFIG.DEFAULT_ROOM;
    
    // Leave current room if any
    if (ws.currentRoom) {
        leaveRoom(ws);
    }
    
    joinRoom(ws, roomName);
    
    // Store client metadata
    ws.clientInfo = {
        vmName: data.vm_name || 'unknown',
        clientType: data.client_type || 'unknown',
        joinedAt: Date.now()
    };
    
    log('info', `🔗 ${ws.clientInfo.vmName} (${ws.clientInfo.clientType}) connected`);
}

/**
 * Handle database change messages - THE CORE SYNC LOGIC
 */
function handleDatabaseChange(ws, data, rawMessage) {
    const roomName = ws.currentRoom || CONFIG.DEFAULT_ROOM;
    
    // Ensure client is in a room
    if (!ws.currentRoom) {
        joinRoom(ws, roomName);
    }
    
    // Update statistics
    updateStats('database_change', {
        table: data.table,
        operation: data.operation,
        source_vm: data.source_vm
    });
    
    // Log the change
    log('info', '═══════════════════════════════════════════════════════════');
    log('info', `📊 DATABASE CHANGE from ${data.source_vm || 'unknown'}:`);
    log('info', `   📋 Table: ${data.table}`);
    log('info', `   ⚡ Operation: ${data.operation}`);
    log('info', `   🆔 Record ID: ${data.record_id}`);
    log('info', `   📅 Timestamp: ${data.timestamp}`);
    
    if (data.data) {
        if (data.data.fullname) {
            log('info', `   📝 Fullname: ${data.data.fullname}`);
        }
        if (data.data.shortname) {
            log('info', `   📝 Shortname: ${data.data.shortname}`);
        }
    }
    
    // Store in Yjs document for CRDT functionality
    const room = rooms.get(roomName);
    if (room) {
        room.syncLog.push([{
            ...data,
            received_at: Date.now(),
            broadcasted: true
        }]);
    }
    
    // Broadcast to all OTHER clients in the room
    const sentCount = broadcastToRoom(roomName, ws, rawMessage);
    
    log('info', `   ✉️  Broadcasted to ${sentCount} other client(s)`);
    log('info', '═══════════════════════════════════════════════════════════');
    
    // Send acknowledgment to sender
    sendToClient(ws, {
        type: 'ack',
        message_type: 'database_change',
        record_id: data.record_id,
        broadcasted_to: sentCount,
        timestamp: Date.now()
    });
}

/**
 * Handle edit mode enabled messages (UI notifications)
 */
function handleEditModeEnabled(ws, data, rawMessage) {
    const roomName = ws.currentRoom || CONFIG.DEFAULT_ROOM;
    
    log('info', `✏️ Edit mode enabled by ${data.source_vm || 'unknown'}`);
    
    const sentCount = broadcastToRoom(roomName, ws, rawMessage);
    log('info', `   ✉️  Notified ${sentCount} client(s)`);
}

/**
 * Handle sync requests (for initial state synchronization)
 */
function handleSyncRequest(ws, data) {
    const roomName = ws.currentRoom || CONFIG.DEFAULT_ROOM;
    const room = rooms.get(roomName);
    
    if (!room) {
        sendToClient(ws, { type: 'sync_response', data: [], timestamp: Date.now() });
        return;
    }
    
    // Get recent changes from Yjs sync log
    const recentChanges = room.syncLog.toArray().slice(-100);  // Last 100 changes
    
    sendToClient(ws, {
        type: 'sync_response',
        data: recentChanges,
        timestamp: Date.now()
    });
    
    log('info', `📤 Sent ${recentChanges.length} recent changes to ${ws.clientInfo?.vmName || 'client'}`);
}

/**
 * Get formatted statistics
 */
function getFormattedStats() {
    const uptime = Date.now() - stats.startTime;
    const hours = Math.floor(uptime / 3600000);
    const minutes = Math.floor((uptime % 3600000) / 60000);
    const seconds = Math.floor((uptime % 60000) / 1000);
    
    return {
        uptime: `${hours}h ${minutes}m ${seconds}s`,
        uptimeMs: uptime,
        connections: {
            total: stats.totalConnections,
            active: stats.activeConnections
        },
        messages: {
            received: stats.messagesReceived,
            broadcasted: stats.messagesBroadcasted
        },
        databaseChanges: stats.databaseChanges,
        errors: stats.errors,
        rooms: Array.from(rooms.keys()).map(name => ({
            name,
            clients: rooms.get(name).connections.size,
            lastActivity: rooms.get(name).lastActivity
        }))
    };
}

// ═══════════════════════════════════════════════════════════════════════════════
//  WEBSOCKET SERVER SETUP
// ═══════════════════════════════════════════════════════════════════════════════

// Create HTTP server for health checks
const httpServer = http.createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'healthy', ...getFormattedStats() }));
    } else if (req.url === '/stats') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(getFormattedStats()));
    } else {
        res.writeHead(404);
        res.end('Not Found');
    }
});

// Create WebSocket server
const wss = new WebSocket.Server({ 
    server: httpServer,
    maxPayload: CONFIG.MAX_MESSAGE_SIZE
});

// ═══════════════════════════════════════════════════════════════════════════════
//  CONNECTION HANDLING
// ═══════════════════════════════════════════════════════════════════════════════

wss.on('connection', (ws, req) => {
    updateStats('connection');
    
    // Extract client info from headers or query
    const clientIP = req.socket.remoteAddress;
    ws.clientIP = clientIP;
    ws.isAlive = true;
    ws.connectedAt = Date.now();
    
    log('info', `🔌 New connection from ${clientIP} (${stats.activeConnections} active)`);
    
    // Auto-join default room
    joinRoom(ws, CONFIG.DEFAULT_ROOM);
    
    // Send welcome message
    sendToClient(ws, {
        type: 'welcome',
        message: 'Connected to Moodle Sync Server',
        server_time: Date.now(),
        room: ws.currentRoom
    });
    
    // Handle pong responses for heartbeat
    ws.on('pong', () => {
        ws.isAlive = true;
    });
    
    // Handle incoming messages
    ws.on('message', (message) => {
        try {
            handleMessage(ws, message.toString());
        } catch (error) {
            log('error', 'Message handling error:', error);
            updateStats('error');
        }
    });
    
    // Handle client disconnect
    ws.on('close', (code, reason) => {
        updateStats('disconnection');
        leaveRoom(ws);
        log('info', `🔌 Connection closed from ${clientIP} (code: ${code})`);
    });
    
    // Handle errors
    ws.on('error', (error) => {
        updateStats('error');
        log('error', `WebSocket error from ${clientIP}:`, error.message);
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
//  HEARTBEAT MECHANISM
// ═══════════════════════════════════════════════════════════════════════════════

const heartbeatInterval = setInterval(() => {
    wss.clients.forEach((ws) => {
        if (ws.isAlive === false) {
            log('warn', `💔 Terminating inactive connection from ${ws.clientIP}`);
            return ws.terminate();
        }
        
        ws.isAlive = false;
        ws.ping();
    });
}, CONFIG.HEARTBEAT_INTERVAL);

wss.on('close', () => {
    clearInterval(heartbeatInterval);
});

// ═══════════════════════════════════════════════════════════════════════════════
//  SERVER STARTUP
// ═══════════════════════════════════════════════════════════════════════════════

httpServer.listen(CONFIG.PORT, CONFIG.HOST, () => {
    console.log('');
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log('  🚀 YJS MOODLE SYNC SERVER');
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log(`  📡 WebSocket: ws://${CONFIG.HOST}:${CONFIG.PORT}`);
    console.log(`  🔍 Health:    http://${CONFIG.HOST}:${CONFIG.PORT}/health`);
    console.log(`  📊 Stats:     http://${CONFIG.HOST}:${CONFIG.PORT}/stats`);
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log('  🏠 Default Room:', CONFIG.DEFAULT_ROOM);
    console.log('  💓 Heartbeat:', CONFIG.HEARTBEAT_INTERVAL / 1000, 'seconds');
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log('  📋 Waiting for connections...');
    console.log('');
});

// ═══════════════════════════════════════════════════════════════════════════════
//  GRACEFUL SHUTDOWN
// ═══════════════════════════════════════════════════════════════════════════════

process.on('SIGINT', () => {
    log('info', '🛑 Shutting down server...');
    
    // Notify all clients
    wss.clients.forEach((ws) => {
        sendToClient(ws, {
            type: 'server_shutdown',
            message: 'Server is shutting down',
            timestamp: Date.now()
        });
        ws.close(1001, 'Server shutdown');
    });
    
    wss.close(() => {
        log('info', '✅ WebSocket server closed');
        httpServer.close(() => {
            log('info', '✅ HTTP server closed');
            log('info', '📊 Final stats:', JSON.stringify(getFormattedStats(), null, 2));
            process.exit(0);
        });
    });
});

process.on('uncaughtException', (error) => {
    log('error', 'Uncaught exception:', error);
});

process.on('unhandledRejection', (reason, promise) => {
    log('error', 'Unhandled rejection:', reason);
});

module.exports = { wss, rooms, stats, CONFIG };



