<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — Carbot</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center text-gray-100">
<div class="w-full max-w-sm px-4">
  <div class="text-center mb-8">
    <div class="text-4xl mb-3">🚗</div>
    <h1 class="text-2xl font-bold text-white">Carbot Admin</h1>
  </div>

  @if($errors->any())
  <div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm text-center">
    {{ $errors->first() }}
  </div>
  @endif

  <form method="POST" action="{{ route('admin.login.submit') }}"
        class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
    @csrf
    <div>
      <label class="block text-xs text-gray-500 mb-1 uppercase tracking-wider">Пароль</label>
      <input type="password" name="password" autofocus autocomplete="current-password"
             class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2.5 text-white text-sm
                    focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
    </div>
    <button type="submit"
            class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-lg text-sm transition">
      Войти
    </button>
  </form>
</div>
</body>
</html>
