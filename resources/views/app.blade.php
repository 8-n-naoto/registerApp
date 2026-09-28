<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="app-base" content="{{ config('app.base_path') }}">
    <meta name="theme-color" content="#2F63DB">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="レジ">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="{{ config('app.base_path') }}/manifest.webmanifest">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ config('app.base_path') }}/icons/apple-touch-icon.png">
    <title>レジアプリ</title>
    @vite('resources/js/main.ts')
</head>
<body>
    <div id="app"></div>
</body>
</html>
