'use strict';

const { WebSocketServer } = require('ws');
const { startScanning, onData } = require('./bleScanner');

const PORT = process.env.BLE_PORT || 8765;
const wss = new WebSocketServer({ port: PORT });

// Relay BLE data to all connected PWA clients
onData(payload => {
    const msg = JSON.stringify(payload);
    wss.clients.forEach(client => {
        if (client.readyState === 1) client.send(msg);
    });
});

wss.on('connection', ws => {
    console.log('PWA connected');
    ws.send(JSON.stringify({ type: 'status', connected: true }));
    ws.on('close', () => console.log('PWA disconnected'));
    ws.on('error', err => console.error('WebSocket error:', err));
});

startScanning();
console.log(`BLE bridge running on ws://localhost:${PORT}`);
