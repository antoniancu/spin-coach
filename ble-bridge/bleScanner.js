'use strict';

const noble = require('@abandonware/noble');

const TARGET_NAME    = 'IC Bike';
const CSCS_SERVICE   = '1816';
const HRS_SERVICE    = '180d';
const CSC_MEASUREMENT = '2a5b';
const HR_MEASUREMENT  = '2a37';

let dataCallback = null;

// Crank tracking (cadence)
let lastCrankRevs = null;
let lastCrankTime = null;

// Wheel tracking (speed + distance)
let lastWheelRevs = null;
let lastWheelTime = null;
let totalWheelRevs = 0;
const WHEEL_CIRCUMFERENCE_M = 1.886; // calibrated for C6 (~10% below 700c default)

function onData(cb) {
    dataCallback = cb;
}

function broadcast(payload) {
    if (dataCallback) dataCallback(payload);
}

function parseCSC(buf) {
    const flags = buf.readUInt8(0);
    const hasWheel = (flags & 0x01) !== 0;
    const hasCrank = (flags & 0x02) !== 0;
    let offset = 1;

    // Wheel revolution data: uint32 cumulative revs + uint16 last event time (1/1024s)
    if (hasWheel) {
        const revs = buf.readUInt32LE(offset);
        const time = buf.readUInt16LE(offset + 4);
        offset += 6;

        if (lastWheelRevs !== null) {
            const deltaRevs = (revs - lastWheelRevs) & 0xFFFFFFFF;
            const deltaTime = (time - lastWheelTime + 0x10000) & 0xFFFF;

            totalWheelRevs += deltaRevs;
            const distanceKm = (totalWheelRevs * WHEEL_CIRCUMFERENCE_M) / 1000;

            if (deltaRevs > 0 && deltaTime > 0) {
                const speedMs = (deltaRevs * WHEEL_CIRCUMFERENCE_M) / (deltaTime / 1024);
                const speedKmh = Math.round(speedMs * 3.6 * 10) / 10;
                if (speedKmh <= 80) {
                    broadcast({ type: 'speed', kmh: speedKmh, distance_km: Math.round(distanceKm * 100) / 100 });
                }
            } else {
                broadcast({ type: 'speed', kmh: 0, distance_km: Math.round(distanceKm * 100) / 100 });
            }
        }

        lastWheelRevs = revs;
        lastWheelTime = time;
    }

    // Crank revolution data: uint16 cumulative revs + uint16 last event time (1/1024s)
    if (hasCrank) {
        const revs = buf.readUInt16LE(offset);
        const time = buf.readUInt16LE(offset + 2);

        if (lastCrankRevs !== null) {
            const deltaRevs = (revs - lastCrankRevs + 0x10000) & 0xFFFF;
            const deltaTime = (time - lastCrankTime + 0x10000) & 0xFFFF;
            if (deltaRevs > 0 && deltaTime > 0) {
                const rpm = Math.round((deltaRevs / deltaTime) * 1024 * 60);
                if (rpm <= 200) {
                    broadcast({ type: 'cadence', rpm });
                }
            }
        }

        lastCrankRevs = revs;
        lastCrankTime = time;
    }
}

function parseHR(buf) {
    const flags = buf.readUInt8(0);
    // Bit 0: 0 = uint8 format, 1 = uint16 format
    const bpm = (flags & 0x01)
        ? buf.readUInt16LE(1)
        : buf.readUInt8(1);
    broadcast({ type: 'hr', bpm });
}

function connect(peripheral) {
    peripheral.connect(err => {
        if (err) {
            console.error('Connection error:', err);
            return;
        }

        console.log('Connected to', TARGET_NAME);

        peripheral.discoverSomeServicesAndCharacteristics(
            [CSCS_SERVICE, HRS_SERVICE],
            [CSC_MEASUREMENT, HR_MEASUREMENT],
            (err, services, characteristics) => {
                if (err) {
                    console.error('Service discovery error:', err);
                    return;
                }

                characteristics.forEach(char => {
                    char.subscribe();
                    if (char.uuid === CSC_MEASUREMENT) char.on('data', parseCSC);
                    if (char.uuid === HR_MEASUREMENT)  char.on('data', parseHR);
                });

                console.log('Subscribed to cadence and heart rate');
            }
        );
    });

    peripheral.on('disconnect', () => {
        console.log('Bike disconnected — reconnecting in 5s');
        lastCrankRevs = null;
        lastCrankTime = null;
        lastWheelRevs = null;
        lastWheelTime = null;
        broadcast({ type: 'status', connected: false });
        setTimeout(() => noble.startScanning([CSCS_SERVICE], false), 5000);
    });
}

function startScanning() {
    noble.on('stateChange', state => {
        console.log('Bluetooth state:', state);
        if (state === 'poweredOn') {
            console.log('Scanning for', TARGET_NAME, '...');
            noble.startScanning([CSCS_SERVICE], false);
        } else {
            noble.stopScanning();
        }
    });

    noble.on('discover', peripheral => {
        const name = peripheral.advertisement.localName;
        if (name !== TARGET_NAME) return;
        console.log('Found', TARGET_NAME);
        noble.stopScanning();
        broadcast({ type: 'status', connected: true });
        connect(peripheral);
    });
}

module.exports = { startScanning, onData };
