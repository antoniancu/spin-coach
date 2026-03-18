<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f0f0f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="icon" href="/apple-touch-icon.png">
    <title>@yield('title', 'SpinCoach')</title>
    <link rel="stylesheet" href="/css/app.css">
    @stack('styles')
</head>
<script>window.BLE_BRIDGE_WS_URL = "{{ config('services.ble_bridge.ws_url') }}";</script>
<body class="{{ session('user_id') ? 'has-spotify-bar' : '' }}">
    @yield('content')

    @if(session('user_id'))
    <div id="spotify-bar" class="spotify-bar hidden">
        <div id="sp-eq" class="sp-eq" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span>
        </div>
        <img id="sp-bar-art" class="spotify-bar-art" src="" alt="" aria-hidden="true">
        <div class="spotify-bar-track">
            <span id="sp-bar-track" class="spotify-bar-title"></span>
            <span id="sp-bar-artist" class="spotify-bar-artist"></span>
        </div>
        <div class="spotify-bar-controls">
            <button id="sp-bar-playpause" class="sp-ctrl-btn" aria-label="Play/Pause"></button>
            <button class="sp-ctrl-btn" onclick="SpotifyUI.next().then(() => setTimeout(SpotifyUI.refreshMiniPlayer, 1000))" aria-label="Next">&#9654;&#9654;</button>
            <div class="sp-device-wrap">
                <button class="sp-ctrl-btn" id="sp-bar-device-btn" onclick="SpotifyUI.toggleDevicePopover()" aria-label="Speaker">&#128264;</button>
                <div id="sp-device-popover" class="sp-device-popover hidden">
                    <div class="sp-device-popover-title">Select device</div>
                    <div id="sp-device-popover-list"></div>
                </div>
            </div>
        </div>
    </div>
    <script src="/js/spotify.js"></script>
    <script>
        SpotifyUI.initMiniPlayer(document.querySelector('meta[name="csrf-token"]').content);
    </script>
    @endif

    @stack('scripts')
</body>
</html>
