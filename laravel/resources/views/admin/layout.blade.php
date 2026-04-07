<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'Admin') — Carbot</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' }</script>
  <style>
    .log-error   { color: #f87171; }
    .log-warning { color: #fbbf24; }
    .log-info    { color: #94a3b8; }
    .log-debug   { color: #475569; }
    .log-stat    { color: #22d3ee; font-weight: 600; }
  </style>
</head>
<body class="h-full text-gray-100">
<div class="flex h-full">

  {{-- Sidebar --}}
  <nav class="w-52 shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col py-6 px-4 gap-1">
    <span class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-4 px-2">Carbot Admin</span>

    @foreach([
      ['route' => 'admin.dashboard', 'icon' => '▦', 'label' => 'Dashboard'],
      ['route' => 'admin.changes',   'icon' => '↻', 'label' => 'Changes'],
      ['route' => 'admin.stats',     'icon' => '▲', 'label' => 'Stats'],
      ['route' => 'admin.logs',      'icon' => '≡', 'label' => 'Logs'],
      ['route' => 'admin.jobs',      'icon' => '▶', 'label' => 'Jobs'],
      ['route' => 'admin.schedules', 'icon' => '⏱', 'label' => 'Schedules'],
      ['route' => 'admin.lots',      'icon' => '⟳', 'label' => 'Re-parse'],
      ['route' => 'admin.accuracy',  'icon' => '◎', 'label' => 'Accuracy'],
    ] as $item)
      @php $active = request()->routeIs($item['route']) @endphp
      <a href="{{ route($item['route']) }}"
         class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                {{ $active ? 'bg-blue-600 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
        <span class="text-base w-4 text-center">{{ $item['icon'] }}</span>
        {{ $item['label'] }}
      </a>
    @endforeach

    <div class="mt-auto pt-4 border-t border-gray-800">
      <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit"
                class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-800 hover:text-white transition">
          <span class="text-base w-4 text-center">⏻</span>
          Выйти
        </button>
      </form>
    </div>
  </nav>

  {{-- Main --}}
  <main class="flex-1 overflow-auto">
    <div class="px-8 py-6">
      <h1 class="text-xl font-semibold text-white mb-6">@yield('title', 'Dashboard')</h1>
      @yield('content')
    </div>
  </main>

</div>
</body>
</html>
