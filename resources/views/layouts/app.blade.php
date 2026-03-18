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
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
