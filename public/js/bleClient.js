'use strict';

const BleClient = (() => {
    const BLE_URL = window.BLE_BRIDGE_WS_URL || 'ws://norford.local:8765';

    let socket = null;
    let currentCadence = 0;
    let currentHR = 0;
    let currentSpeed = 0;
    let currentDistance = 0;
    let connected = false;
    let cadenceCallback = null;
    let hrCallback = null;
    let speedCallback = null;
    let statusCallback = null;

    function connect() {
        socket = new WebSocket(BLE_URL);

        socket.onopen = () => {
            connected = true;
            if (statusCallback) statusCallback(true);
        };

        socket.onmessage = ({ data }) => {
            let msg;
            try { msg = JSON.parse(data); } catch { return; }

            if (msg.type === 'cadence') {
                currentCadence = msg.rpm;
                if (cadenceCallback) cadenceCallback(msg.rpm);
            } else if (msg.type === 'hr') {
                currentHR = msg.bpm;
                if (hrCallback) hrCallback(msg.bpm);
            } else if (msg.type === 'speed') {
                currentSpeed = msg.kmh;
                currentDistance = msg.distance_km;
                if (speedCallback) speedCallback(msg.kmh, msg.distance_km);
            } else if (msg.type === 'status') {
                connected = msg.connected;
                if (statusCallback) statusCallback(msg.connected);
            }
        };

        socket.onclose = () => {
            connected = false;
            currentCadence = 0;
            currentHR = 0;
            currentSpeed = 0;
            if (statusCallback) statusCallback(false);
            setTimeout(connect, 5000);
        };

        socket.onerror = () => socket.close();
    }

    function onCadence(cb) { cadenceCallback = cb; }
    function onHR(cb)      { hrCallback = cb; }
    function onSpeed(cb)   { speedCallback = cb; }
    function onStatus(cb)  { statusCallback = cb; }

    function getCadence()  { return currentCadence; }
    function getHR()       { return currentHR; }
    function getSpeed()    { return currentSpeed; }
    function getDistance()  { return currentDistance; }
    function isActive()    { return connected; }

    return { connect, onCadence, onHR, onSpeed, onStatus, getCadence, getHR, getSpeed, getDistance, isActive };
})();
