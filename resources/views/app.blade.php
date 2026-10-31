<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'PinBot') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='16' fill='%23e60023'/><path d='M16 7a7 7 0 0 0-2.6 13.5c-.1-.6-.1-1.5 0-2.2l.8-3.5s-.2-.4-.2-1c0-1 .6-1.7 1.3-1.7.6 0 .9.4.9 1 0 .6-.4 1.5-.6 2.4-.2.7.4 1.3 1.1 1.3 1.3 0 2.3-1.4 2.3-3.4 0-1.8-1.3-3-3.1-3-2.1 0-3.4 1.6-3.4 3.3 0 .6.2 1.3.5 1.7.1.1.1.2.1.3l-.2.8c0 .1-.1.2-.3.1-1-.5-1.6-1.9-1.6-3 0-2.5 1.8-4.7 5.1-4.7 2.7 0 4.8 1.9 4.8 4.5 0 2.7-1.7 4.8-4 4.8-.8 0-1.5-.4-1.8-.9l-.5 1.8c-.2.7-.7 1.5-1 2A7 7 0 1 0 16 7z' fill='white'/></svg>">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    <div id="app"></div>
</body>
</html>
