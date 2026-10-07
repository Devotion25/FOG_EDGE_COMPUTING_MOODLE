/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  TEST: WebSocket Connection to Yjs Server
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 *  Usage: node test-connection.js [server-url]
 *  Example: node test-connection.js ws://172.20.10.4:2345
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const WebSocket = require('ws');

// Configuration
const SERVER_URL = process.argv[2] || 'ws://172.20.10.4:2345';
const TEST_TIMEOUT = 10000; // 10 seconds

console.log('═══════════════════════════════════════════════════════════════════');
console.log('  🧪 YJS SERVER CONNECTION TEST');
console.log('═══════════════════════════════════════════════════════════════════');
console.log(`  📡 Server: ${SERVER_URL}`);
console.log('═══════════════════════════════════════════════════════════════════');
console.log('');

const tests = {
    connection: false,
    welcome: false,
    joinRoom: false,
    ping: false,
    broadcast: false
};

let ws;
let testTimeout;

function runTests() {
    return new Promise((resolve, reject) => {
        // Set overall timeout
        testTimeout = setTimeout(() => {
            console.log('❌ Test timeout - not all tests completed');
            cleanup();
            resolve(tests);
        }, TEST_TIMEOUT);

        // Test 1: Connection
        console.log('📋 Test 1: Establishing WebSocket connection...');
        
        try {
            ws = new WebSocket(SERVER_URL);
        } catch (error) {
            console.log(`❌ Failed to create WebSocket: ${error.message}`);
            resolve(tests);
            return;
        }

        ws.on('open', () => {
            tests.connection = true;
            console.log('✅ Test 1 PASSED: Connection established');
            console.log('');
        });

        ws.on('message', (data) => {
            try {
                const message = JSON.parse(data.toString());
                
                // Test 2: Welcome message
                if (message.type === 'welcome' && !tests.welcome) {
                    tests.welcome = true;
                    console.log('✅ Test 2 PASSED: Received welcome message');
                    console.log(`   Server time: ${new Date(message.server_time).toISOString()}`);
                    console.log('');
                    
                    // Test 3: Join room
                    console.log('📋 Test 3: Joining sync room...');
                    ws.send(JSON.stringify({
                        type: 'join',
                        room: 'moodle-database-sync',
                        vm_name: 'TEST_CLIENT',
                        client_type: 'test'
                    }));
                }
                
                // Test 3: Room joined
                if (message.type === 'room_joined' && !tests.joinRoom) {
                    tests.joinRoom = true;
                    console.log('✅ Test 3 PASSED: Joined room successfully');
                    console.log(`   Room: ${message.room}`);
                    console.log(`   Clients in room: ${message.clients}`);
                    console.log('');
                    
                    // Test 4: Ping/Pong
                    console.log('📋 Test 4: Testing ping/pong...');
                    ws.send(JSON.stringify({ type: 'ping' }));
                }
                
                // Test 4: Pong response
                if (message.type === 'pong' && !tests.ping) {
                    tests.ping = true;
                    console.log('✅ Test 4 PASSED: Ping/pong working');
                    console.log('');
                    
                    // Test 5: Broadcast (simulate database change)
                    console.log('📋 Test 5: Testing message broadcast...');
                    ws.send(JSON.stringify({
                        type: 'database_change',
                        table: 'mdl_course',
                        operation: 'UPDATE',
                        record_id: 999,
                        data: {
                            id: 999,
                            fullname: 'Test Course',
                            shortname: 'TEST',
                            timemodified: Math.floor(Date.now() / 1000)
                        },
                        source_vm: 'TEST_CLIENT',
                        timestamp: Math.floor(Date.now() / 1000)
                    }));
                }
                
                // Test 5: Broadcast acknowledgment
                if (message.type === 'ack' && message.message_type === 'database_change') {
                    tests.broadcast = true;
                    console.log('✅ Test 5 PASSED: Broadcast acknowledged');
                    console.log(`   Broadcasted to: ${message.broadcasted_to} client(s)`);
                    console.log('');
                    
                    // All tests complete
                    cleanup();
                    resolve(tests);
                }
                
            } catch (error) {
                console.log(`⚠️ Failed to parse message: ${error.message}`);
            }
        });

        ws.on('error', (error) => {
            console.log(`❌ WebSocket error: ${error.message}`);
            cleanup();
            resolve(tests);
        });

        ws.on('close', (code, reason) => {
            console.log(`🔌 Connection closed (code: ${code})`);
        });
    });
}

function cleanup() {
    clearTimeout(testTimeout);
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.close();
    }
}

function printResults(results) {
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log('  📊 TEST RESULTS');
    console.log('═══════════════════════════════════════════════════════════════════');
    
    let passed = 0;
    let failed = 0;
    
    for (const [test, result] of Object.entries(results)) {
        const status = result ? '✅ PASS' : '❌ FAIL';
        console.log(`  ${status}: ${test}`);
        if (result) passed++; else failed++;
    }
    
    console.log('═══════════════════════════════════════════════════════════════════');
    console.log(`  Total: ${passed} passed, ${failed} failed`);
    console.log('═══════════════════════════════════════════════════════════════════');
    
    return failed === 0;
}

// Run tests
runTests().then(results => {
    const allPassed = printResults(results);
    process.exit(allPassed ? 0 : 1);
}).catch(error => {
    console.error('Test runner error:', error);
    process.exit(1);
});



