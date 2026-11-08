<?php
/**
 * Inicio de sesión del operador del Back-Office.
 * Variables disponibles: $error (?string), $email (string)
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso · Piquantum Back-Office</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: #0f172a; color: #f1f5f9; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">
    <main class="w-full max-w-sm">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-600 to-orange-500 flex items-center justify-center text-2xl">🌶️</div>
            <div>
                <h1 class="text-lg font-bold text-white">PIQUANTUM · Back-Office</h1>
                <p class="text-xs text-slate-400">Acceso del operador · Prototipo académico</p>
            </div>
        </div>

        <form method="POST" action="/login" class="bg-slate-800/85 border border-slate-700 rounded-xl p-5 space-y-4">
            <?php if (!empty($error)): ?>
                <div class="text-sm text-rose-200 bg-rose-500/15 border border-rose-500/40 rounded-lg px-3 py-2" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div>
                <label for="email" class="block text-xs text-slate-400 mb-1">Email</label>
                <input id="email" name="email" type="email" required autocomplete="username" autofocus
                       value="<?= htmlspecialchars($email) ?>"
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            </div>
            <div>
                <label for="password" class="block text-xs text-slate-400 mb-1">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            </div>
            <button type="submit" class="w-full px-3 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-sm font-semibold transition">
                Entrar
            </button>
        </form>
    </main>
</body>
</html>
