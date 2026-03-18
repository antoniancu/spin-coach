# SpinCoach — BLE Bridge

## Overview

The BLE bridge is a standalone Node.js process that runs **directly on macOS**
(not in Docker — OrbStack cannot pass host Bluetooth hardware to containers).

It connects to the Bowflex C6 via Bluetooth Low Energy, reads cadence and
heart rate data, and broadcasts it to the PWA over a local WebSocket.

Location: `ble-bridge/` in the repo root.
Start: `node ble-bridge/server.js`
For persistence: register as a macOS launchd service (see below).

---

## How the C6 advertises

The bike broadcasts as device name **"IC Bike"** (shared with Schwinn IC4).
It does NOT appear in macOS System Settings Bluetooth — it is BLE only,
not Classic Bluetooth.

Services advertised:
| UUID   | Name | Description |
|--------|------|-------------|
| 0x180A | Device Information | Manufacturer, model |
| 0x1816 | Cycling Speed & Cadence (CSCS) | Cadence + wheel rev data |
| 0x180D | Heart Rate Service (HRS) | HR from the included armband |

Key characteristics:
| UUID   | Service | Property | Description |
|--------|---------|----------|-------------|
| 0x2A5B | CSCS    | NOTIFY   | CSC Measurement — crank revs + timestamp |
| 0x2A5C | CSCS    | READ     | CSC Feature flags |
| 0x2A37 | HRS     | NOTIFY   | Heart Rate Measurement — BPM |

The bike only broadcasts while pedaling. When idle it stops advertising.

---

## Dependencies

```json
{
  "dependencies": {
    "@abandonware/noble": "^1.9.2-15",
    "ws": "^8.16.0"
  }
}
```

`@abandonware/noble` is the maintained fork of `noble` with macOS 12+ support.

Requires Bluetooth permission granted to Terminal (or whichever shell runs it):
System Settings → Privacy & Security → Bluetooth → add Terminal.

---

## bleScanner.js

Handles BLE connection and data parsing.

### Scanning

```js
const TARGET_NAME = 'IC Bike';
const CSCS_SERVICE = '1816';
const HRS_SERVICE  = '180d';
const CSC_MEASUREMENT = '2a5b';
const HR_MEASUREMENT  = '2a37';

noble.on('stateChange', state => {
  if (state === 'poweredOn') {
    noble.startScanning([CSCS_SERVICE], false);
  }
});

noble.on('discover', peripheral => {
  if (peripheral.advertisement.localName !== TARGET_NAME) return;
  noble.stopScanning();
  connect(peripheral);
});
```

### Connection + subscription

```js
function connect(peripheral) {
  peripheral.connect(err => {
    peripheral.discoverSomeServicesAndCharacteristics(
      [CSCS_SERVICE, HRS_SERVICE],
      [CSC_MEASUREMENT, HR_MEASUREMENT],
      (err, services, characteristics) => {
        characteristics.forEach(char => {
          char.subscribe();
          if (char.uuid === CSC_MEASUREMENT) char.on('data', parseCadence);
          if (char.uuid === HR_MEASUREMENT)  char.on('data', parseHR);
        });
      }
    );
  });

  peripheral.on('disconnect', () => {
    console.log('Bike disconnected — reconnecting in 5s');
    setTimeout(() => noble.startScanning([CSCS_SERVICE], false), 5000);
  });
}
```

### CSC Measurement parsing

The `0x2A5B` characteristic sends cumulative crank revolutions + last crank
event time. Cadence is derived from the delta between successive notifications.

```js
let lastRevs = null;
let lastTime = null;

function parseCadence(buf) {
  const flags = buf.readUInt8(0);
  const hasCrank = (flags & 0x02) !== 0;
  if (!hasCrank) return;

  // Crank data starts at byte 1 if wheel rev not present, byte 5 if present
  const crankOffset = (flags & 0x01) ? 5 : 1;
  const revs = buf.readUInt32LE(crankOffset);
  const time = buf.readUInt16LE(crankOffset + 4);  // 1/1024 sec units

  if (lastRevs !== null) {
    const deltaRevs = (revs - lastRevs) & 0xFFFFFFFF;
    const deltaTime = (time - lastTime + 0x10000) & 0xFFFF; // handle rollover
    const rpm = deltaTime > 0
      ? Math.round((deltaRevs / deltaTime) * 1024 * 60)
      : 0;
    broadcast({ type: 'cadence', rpm });
  }

  lastRevs = revs;
  lastTime = time;
}
```

### Heart Rate parsing

```js
function parseHR(buf) {
  const flags = buf.readUInt8(0);
  // Bit 0: 0 = uint8 format, 1 = uint16 format
  const bpm = (flags & 0x01)
    ? buf.readUInt16LE(1)
    : buf.readUInt8(1);
  broadcast({ type: 'hr', bpm });
}
```

---

## server.js

WebSocket server. Accepts connections from the PWA and relays BLE data.

```js
const { WebSocketServer } = require('ws');
const { startScanning, onData } = require('./bleScanner');

const wss = new WebSocketServer({ port: 8765 });

// BLE data → all connected WebSocket clients
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
});

startScanning();
console.log('BLE bridge running on ws://localhost:8765');
```

---

## bleClient.js (browser side)

`public/js/bleClient.js` — connects the PWA to the bridge.

```js
const BLE_URL = window.BLE_BRIDGE_WS_URL || 'ws://norford.local:8765';

let socket = null;
let currentCadence = 0;
let currentHR = 0;
let isConnected = false;

function connect() {
  socket = new WebSocket(BLE_URL);

  socket.onopen = () => {
    isConnected = true;
    updateBLEIndicator(true);
  };

  socket.onmessage = ({ data }) => {
    const msg = JSON.parse(data);
    if (msg.type === 'cadence') currentCadence = msg.rpm;
    if (msg.type === 'hr')      currentHR = msg.bpm;
    updateOverlay(currentCadence, currentHR);
  };

  socket.onclose = () => {
    isConnected = false;
    updateBLEIndicator(false);
    // Reconnect after 5 seconds
    setTimeout(connect, 5000);
  };

  socket.onerror = () => socket.close();
}

// Public API used by workoutPlayer.js
export function getCadence() { return currentCadence; }
export function getHR()      { return currentHR; }
export function bleActive()  { return isConnected; }
```

`BLE_BRIDGE_WS_URL` is injected into Blade layout:
```blade
<script>
  window.BLE_BRIDGE_WS_URL = "{{ config('spincoach.ble_bridge_ws_url') }}";
</script>
```

---

## Cadence Zone Indicator

Shown in the ride overlay. Compares `getCadence()` against the current
phase's `rpm_low` / `rpm_high` targets:

| State | Colour | Label |
|-------|--------|-------|
| Below target (> 5 RPM under) | amber | ↑ Speed up |
| On target (within ±5 RPM) | green | ✓ On pace |
| Above target (> 5 RPM over) | blue | ↓ Ease off |
| BLE not connected | grey | — |

---

## Running as a launchd Service (optional)

To have the bridge start automatically with norford:

`~/Library/LaunchAgents/home.norford.ble-bridge.plist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>home.norford.ble-bridge</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/local/bin/node</string>
    <string>/Users/antoniancu/Development/spin-coach/ble-bridge/server.js</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>StandardOutPath</key>
  <string>/Users/antoniancu/Development/spin-coach/ble-bridge/bridge.log</string>
  <key>StandardErrorPath</key>
  <string>/Users/antoniancu/Development/spin-coach/ble-bridge/bridge.error.log</string>
</dict>
</plist>
```

Load it:
```bash
launchctl load ~/Library/LaunchAgents/home.norford.ble-bridge.plist
```

Check status:
```bash
launchctl list | grep ble-bridge
```
