<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta
            name="description"
            content="Able Pro Admin Template – Built with Vuetify, Vue, Laravel, and Vue Router for a smooth and efficient development experience."
        />

        {{-- Required for Laravel Echo's /broadcasting/auth CSRF check --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" href="{{ asset('favicon.ico') }}" />

        <title>WhatsApp Chatbot Builder</title>

        @auth
        <script>
            window.__TENANT_ID__ = {{ auth()->user()->primaryTenant()?->id
                                        ?? auth()->user()->tenants()->value('tenants.id')
                                        ?? 'null' }};
            window.__USER_ID__   = {{ auth()->id() }};
        </script>
        @else
        <script>
            window.__TENANT_ID__ = null;
            window.__USER_ID__   = null;
        </script>
        @endauth

        @vite(['resources/ts/main.ts'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>