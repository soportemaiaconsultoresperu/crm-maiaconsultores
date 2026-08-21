<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'CRM Maia Consultores') }} — Iniciar sesión</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
<main class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card card-primary shadow-sm mt-5">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <h1 class="h4 mb-1 app-logo">{{ config('app.name', 'CRM Maia Consultores') }}</h1>
                        <p class="text-secondary small mb-0">Ingrese sus credenciales para continuar</p>
                    </div>

                    @if (session('error'))
                        <x-alert type="error">{{ session('error') }}</x-alert>
                    @endif

                    <form method="POST" action="{{ route('login.store') }}">
                        @csrf

                        <div class="mb-3">
                            <x-text-input
                                name="email"
                                type="email"
                                label="Correo electrónico"
                                :required="true"
                                value="{{ old('email') }}"
                                autocomplete="username"
                                autofocus
                            />
                        </div>

                        <div class="mb-4">
                            <x-text-input
                                name="password"
                                type="password"
                                label="Contraseña"
                                :required="true"
                                autocomplete="current-password"
                            />
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                Iniciar sesión
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <p class="text-center text-secondary small mt-3 mb-0">
                {{ config('app.name') }} &copy; {{ now()->year }} — Maia Consultores
            </p>
        </div>
    </div>
</main>
</body>
</html>
