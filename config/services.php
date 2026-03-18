<?php

declare(strict_types=1);

return [

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'redirect_uri' => env('SPOTIFY_REDIRECT_URI'),
    ],

    'google' => [
        'maps_key' => env('GOOGLE_MAPS_KEY'),
    ],

    'ble_bridge' => [
        'ws_url' => env('BLE_BRIDGE_WS_URL', 'ws://norford.local:8765'),
    ],

];
