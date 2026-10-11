<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- 検索エンジンに載せない（Google・Bing など。Chrome・Edge・Safari の検索はこれらの結果を使う）。.htaccess の X-Robots-Tag と同じ値 --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="app-base" content="{{ config('app.base_path') }}">
    {{-- HTML を返した時点でログインしているか（1 / 0）。未ログインで GET /api/me（401）を呼ばないため（QA-004）。利用者の情報は載せない --}}
    <meta name="app-auth" content="{{ auth()->check() ? '1' : '0' }}">
    <meta name="theme-color" content="#2F63DB">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="レジ">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="{{ config('app.base_path') }}/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ config('app.base_path') }}/icons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ config('app.base_path') }}/icons/apple-touch-icon.png">
    <title>レジアプリ</title>
    @vite('resources/js/main.ts')
</head>
<body>
    {{-- JS を読み込むまでの表示（PERF-002）。Vue の起動で置き換わる。見た目は base.css の .boot --}}
    <div id="app"><div class="boot" role="status">読み込み中…</div></div>
</body>
</html>
