<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Leads Processing System (LPS)') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|fraunces:500,600" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app"
            data-app-name="{{ config('app.name', 'Leads Processing System (LPS)') }}"
            data-app-short-name="LPS"
            data-api-base="/api"
            data-page="{{ $page ?? 'workspace' }}"
            data-ai-monitoring-display-currency="{{ config('services.ai_monitoring.display_currency', 'USD') }}"
            data-ai-monitoring-usd-to-myr-rate="{{ config('services.ai_monitoring.exchange_rates.usd_to_myr', 1) }}"
            data-current-path="{{ request()->path() }}">
        </div>
    </body>
</html>
