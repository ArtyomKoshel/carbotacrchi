<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
  <meta charset="UTF-8">
  <title>Admin Login — Carbot</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center text-gray-100">
<div class="w-full max-w-sm">
  <h1 class="text-2xl font-bold text-white text-center mb-8">Carbot Admin</h1>
  <form method="GET" action="{{ route('admin.dashboard') }}" class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
    <div>
      <label class="block text-sm text-gray-400 mb-1">Admin Token</label>
      <input type="password" name="token" autofocus
             class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
    </div>
    <button type="submit"
            class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2 rounded-lg text-sm transition">
      Enter
    </button>
  </form>
</div>
</body>
</html>
