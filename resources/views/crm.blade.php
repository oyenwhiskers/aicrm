<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'AI CRM Nexus') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|fraunces:500,600" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app"
            data-app-name="{{ config('app.name', 'AI CRM Nexus') }}"
            data-api-base="/api"
            data-page="{{ $page ?? 'workspace' }}"
            data-current-path="{{ request()->path() }}">
        </div>
    </body>
</html>