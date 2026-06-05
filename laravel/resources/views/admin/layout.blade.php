<!DOCTYPE html>
<html lang="ru" class="h-full bg-gray-950">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'Admin') — Carbot</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' }</script>
  <script>
    window.AdminUi = {
      statusLabels: {
        pending: 'В очереди',
        running: 'Выполняется',
        done: 'Готово',
        error: 'Ошибка',
        cancelled: 'Отменено',
        interrupted: 'Прервано',
      },
      phaseLabels: {
        prepare: 'Подготовка',
        fetch: 'Загрузка',
        parse: 'Парсинг',
        save: 'Сохранение',
        finalize: 'Завершение',
        cleanup: 'Очистка',
      },
      status(value) {
        return this.statusLabels[value] || value;
      },
      phase(value) {
        return this.phaseLabels[value] || value;
      },
    };
  </script>
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
  @php
    $isSuper      = session('admin_role') === 'super';
    $allowedPages = $isSuper ? null : \App\Models\AdminPagePermission::allowedKeys();
    $menuItems = [
      ['route' => 'admin.dashboard',             'icon' => '▦',  'label' => 'Дашборд',        'page' => 'dashboard'],
      ['route' => 'admin.lots-browse',            'icon' => '🔍', 'label' => 'Поиск лотов',     'page' => 'lots-browse'],
      ['route' => 'admin.changes',               'icon' => '↻',  'label' => 'Изменения',        'page' => 'changes'],
      ['route' => 'admin.logs',                  'icon' => '≡',  'label' => 'Логи',             'page' => 'logs'],
      ['route' => 'admin.jobs',                  'icon' => '▶',  'label' => 'Задачи',           'page' => 'jobs'],
      ['route' => 'admin.schedules',             'icon' => '⏱',  'label' => 'Расписания',       'page' => 'schedules'],
      ['route' => 'admin.filters',               'icon' => '⚑',  'label' => 'Фильтры',          'page' => 'filters'],
      ['route' => 'admin.bot-filters',           'icon' => '🤖', 'label' => 'Бот-фильтры',      'page' => 'bot-filters'],
      ['route' => 'admin.filter-skip-log.index', 'icon' => '✗',  'label' => 'Лог пропусков',   'page' => 'filter-skip-log'],
      ['route' => 'admin.fields',                'icon' => '◎',  'label' => 'Поля',             'page' => 'fields'],
      ['route' => 'admin.lots',                  'icon' => '⟳',  'label' => 'Репарсинг',        'page' => 'lots'],
    ];
  @endphp

  <nav class="w-52 shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col py-6 px-4 gap-1">
    <span class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-4 px-2">Carbot Admin</span>

    @foreach($menuItems as $item)
      @if($isSuper || in_array($item['page'], $allowedPages ?? [], true))
        @php $active = request()->routeIs($item['route']) @endphp
        <a href="{{ route($item['route']) }}"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                  {{ $active ? 'bg-blue-600 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
          <span class="text-base w-5 text-center leading-none">{{ $item['icon'] }}</span>
          {{ $item['label'] }}
        </a>
      @endif
    @endforeach

    @if($isSuper)
      @php $activeUsers = request()->routeIs('admin.users') @endphp
      <a href="{{ route('admin.users') }}"
         class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                {{ $activeUsers ? 'bg-blue-600 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
        <span class="text-base w-5 text-center leading-none">👥</span>
        Пользователи
      </a>
    @endif

    <div class="mt-auto pt-4 border-t border-gray-800">
      <div class="px-3 py-1 mb-2 text-xs text-gray-600 truncate">
        {{ session('admin_username', '') }}
        @if($isSuper)
          <span class="ml-1 text-yellow-600">★ super</span>
        @endif
      </div>
      <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit"
                class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-800 hover:text-white transition">
          <span class="text-base w-5 text-center">⏻</span>
          Выйти
        </button>
      </form>
    </div>
  </nav>

  {{-- Main --}}
  <main class="flex-1 overflow-auto">
    <div class="px-8 py-6">
      <h1 class="text-xl font-semibold text-white mb-6">@yield('title', 'Дашборд')</h1>
      @yield('content')
    </div>
  </main>

</div>
</body>
</html>
