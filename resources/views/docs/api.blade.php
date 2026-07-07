<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Bouclay API Docs</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts
        @vite(['resources/css/app.css'])

        <style>
            redoc {
                display: block;
                min-height: 100vh;
            }
        </style>
    </head>
    <body class="bg-white text-zinc-950 antialiased">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <div>
                    <a href="/" class="text-sm font-semibold tracking-tight text-zinc-950">Bouclay</a>
                    <p class="mt-1 text-sm text-zinc-600">
                        Integrator API reference for customers, catalog, subscriptions, invoices, payments, and events.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a href="{{ $specUrl }}" class="rounded-md border border-zinc-300 px-3 py-2 font-medium text-zinc-700 hover:bg-zinc-50">
                        OpenAPI YAML
                    </a>
                    <a href="/docs/api#tag/Subscriptions" class="rounded-md bg-zinc-950 px-3 py-2 font-medium text-white hover:bg-zinc-800">
                        Subscriptions
                    </a>
                </div>
            </div>
        </header>

        <main>
            <redoc spec-url="{{ $specUrl }}"></redoc>
        </main>

        <noscript>
            <div class="mx-auto max-w-3xl px-6 py-12">
                <h1 class="text-2xl font-semibold">Bouclay API Docs</h1>
                <p class="mt-2 text-zinc-600">
                    JavaScript is required to render the interactive API reference.
                    You can still download the OpenAPI contract at
                    <a href="{{ $specUrl }}" class="font-medium underline">OpenAPI YAML</a>.
                </p>
            </div>
        </noscript>

        <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
    </body>
</html>
