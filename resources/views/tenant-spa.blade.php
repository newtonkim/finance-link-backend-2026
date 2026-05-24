<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ config('app.name', 'Mfuko Pro') }}</title>
</head>
<body>
    <div id="app"></div>
    @php
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
    @endphp
    <script type="module" src="{{ $frontendUrl }}/@@vite/client"></script>
    <script type="module" src="{{ $frontendUrl }}/src/main.ts"></script>
</body>
</html>
