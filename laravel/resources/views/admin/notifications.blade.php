@extends('admin.layout')
@section('title', 'Уведомления')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
</div>
@endif

<p class="text-sm text-gray-500 mb-6 max-w-3xl">
  Бот пишет получателям в Telegram о парсинге, недоступности БД и балансах.
  Проверка — <code class="text-gray-400">notifications:watch</code> каждые 5 минут.
  Получатель должен хотя бы раз нажать <b>/start</b> у бота, иначе Telegram не даст ему написать.
</p>

{{-- Status --}}
@php
  $db    = $lastStatus['db'] ?? null;
  $proxy = $lastStatus['proxy'] ?? null;
  $ai    = $lastStatus['ai'] ?? null;
  $fmtAt = fn ($s) => !empty($s['at']) ? \Illuminate\Support\Carbon::parse($s['at'])->timezone(config('app.timezone'))->format('d.m H:i') : '—';
@endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">База данных</div>
    @if($dbDown)
      <div class="text-red-400 font-semibold">Недоступна</div>
    @elseif($db)
      <div class="{{ $db['ok'] ? 'text-green-400' : 'text-red-400' }} font-semibold">{{ $db['ok'] ? 'OK' : 'Ошибка' }}</div>
    @else
      <div class="text-gray-500">проверки ещё не было</div>
    @endif
    <div class="text-xs text-gray-600 mt-1">проверено {{ $fmtAt($db) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Прокси FloppyData</div>
    @if(!$proxy)
      <div class="text-gray-500">проверки ещё не было</div>
    @elseif(isset($proxy['skipped']))
      <div class="text-gray-500">пропущено: {{ $proxy['skipped'] }}</div>
    @elseif(!($proxy['ok'] ?? false))
      <div class="text-yellow-400 text-sm">ошибка: {{ $proxy['error'] ?? '' }}</div>
    @else
      @php $low = $proxy['gb'] < ($thresholds['proxy_min_gb'] ?? 0) @endphp
      <div class="{{ $low ? 'text-yellow-400' : 'text-white' }} font-semibold">{{ number_format($proxy['gb'], 2) }} ГБ</div>
      @if(!empty($proxy['expires_at']))
        <div class="text-xs text-gray-400">подписка до {{ \Illuminate\Support\Carbon::parse($proxy['expires_at'])->format('d.m.Y') }}</div>
      @endif
    @endif
    <div class="text-xs text-gray-600 mt-1">проверено {{ $fmtAt($proxy) }}</div>
  </div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">DeepSeek</div>
    @if(!$ai)
      <div class="text-gray-500">проверки ещё не было</div>
    @elseif(isset($ai['skipped']))
      <div class="text-gray-500">пропущено: {{ $ai['skipped'] }}</div>
    @elseif(!($ai['ok'] ?? false))
      <div class="text-yellow-400 text-sm">ошибка: {{ $ai['error'] ?? '' }}</div>
    @else
      @php $low = $ai['amount'] < ($thresholds['ai_min_usd'] ?? 0) @endphp
      <div class="{{ $low ? 'text-yellow-400' : 'text-white' }} font-semibold">{{ number_format($ai['amount'], 2) }} {{ $ai['currency'] }}</div>
    @endif
    <div class="text-xs text-gray-600 mt-1">проверено {{ $fmtAt($ai) }}</div>
  </div>
</div>

@if($openEvents->isNotEmpty())
<div class="bg-gray-900 border border-yellow-800/60 rounded-xl p-4 mb-6">
  <div class="text-sm font-semibold text-yellow-400 mb-2">Открытые инциденты</div>
  <ul class="text-sm text-gray-300 space-y-1">
    @foreach($openEvents as $ev)
      <li>
        <span class="font-mono text-xs text-gray-500">{{ $ev->event_key }}</span>
        · {{ $types[$ev->type]['label'] ?? $ev->type }}
        · с {{ $ev->opened_at?->format('d.m H:i') }}
      </li>
    @endforeach
  </ul>
</div>
@endif

<div class="grid grid-cols-1 2xl:grid-cols-3 gap-6">

  {{-- Recipients --}}
  <div class="2xl:col-span-2 space-y-4">
    <div class="text-sm font-semibold text-white">Получатели</div>

    @forelse($recipients as $r)
      <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden {{ $r->enabled ? '' : 'opacity-60' }}">
        <form method="POST" action="{{ route('admin.notifications.update', $r->id) }}" class="p-4">
          @csrf @method('PUT')
          <div class="flex flex-wrap items-center gap-3 mb-3">
            <span class="font-mono text-sm text-white">{{ $r->chat_id }}</span>
            <input type="text" name="label" value="{{ $r->label }}" placeholder="подпись" maxlength="100"
                   class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white w-48">
            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
              <input type="checkbox" name="enabled" value="1" {{ $r->enabled ? 'checked' : '' }}
                     class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-500">
              включён
            </label>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-1.5 mb-3">
            @foreach($types as $type => $def)
              <label class="flex items-center gap-2 cursor-pointer group" title="{{ $type }}">
                <input type="checkbox" name="types[]" value="{{ $type }}"
                       {{ in_array($type, $r->types ?? [], true) ? 'checked' : '' }}
                       class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-500">
                <span class="text-sm text-gray-300 group-hover:text-white">{{ $def['label'] }}</span>
              </label>
            @endforeach
          </div>
          <button type="submit"
                  class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold">
            Сохранить
          </button>
        </form>
        <div class="px-4 py-2 border-t border-gray-800 flex gap-4 bg-gray-900/60">
          <form method="POST" action="{{ route('admin.notifications.test', $r->id) }}">
            @csrf
            <button type="submit" class="text-xs text-blue-400 hover:text-blue-300">✉ Отправить тест</button>
          </form>
          <form method="POST" action="{{ route('admin.notifications.delete', $r->id) }}"
                onsubmit="return confirm('Удалить получателя {{ $r->chat_id }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="text-xs text-red-400 hover:text-red-300">Удалить</button>
          </form>
        </div>
      </div>
    @empty
      <div class="text-sm text-gray-500">Получателей нет.</div>
    @endforelse

    {{-- Add --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
      <div class="text-xs font-semibold text-gray-400 mb-3 uppercase tracking-wider">Добавить получателя</div>
      <form method="POST" action="{{ route('admin.notifications.store') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div>
          <label class="text-xs text-gray-500 block mb-1">Пользователь бота</label>
          <select name="user_id" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-64">
            <option value="">—</option>
            @foreach($users as $u)
              <option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>{{ '@'.$u->username }}{{ $u->first_name ? ' · '.$u->first_name : '' }}</option>
            @endforeach
          </select>
        </div>
        <div class="text-xs text-gray-600 pb-2.5">или</div>
        <div>
          <label class="text-xs text-gray-500 block mb-1">Chat id</label>
          <input type="text" name="chat_id" value="{{ old('chat_id') }}" maxlength="40" placeholder="799255534"
                 class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-40 font-mono">
        </div>
        <div>
          <label class="text-xs text-gray-500 block mb-1">Подпись</label>
          <input type="text" name="label" value="{{ old('label') }}" maxlength="100" placeholder="@username"
                 class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-40">
        </div>
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold">
          + Добавить
        </button>
      </form>
    </div>
  </div>

  {{-- Thresholds --}}
  <div>
    <div class="text-sm font-semibold text-white mb-4">Пороги</div>
    <form method="POST" action="{{ route('admin.notifications.settings') }}"
          class="bg-gray-900 border border-gray-800 rounded-xl p-4 space-y-3">
      @csrf
      @foreach($thresholdDefs as $key => $def)
        <label class="flex items-center justify-between gap-3">
          <span class="text-sm text-gray-300">{{ $def['label'] }}</span>
          <span class="flex items-center gap-2 shrink-0">
            <input type="number" name="thresholds[{{ $key }}]" step="any" min="0"
                   value="{{ old("thresholds.$key", rtrim(rtrim(number_format($thresholds[$key], 2, '.', ''), '0'), '.')) }}"
                   class="bg-gray-800 border border-gray-700 rounded-lg px-2 py-1 text-sm text-white w-20 text-right">
            <span class="text-xs text-gray-500 w-6">{{ $def['unit'] }}</span>
          </span>
        </label>
      @endforeach
      <button type="submit"
              class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold">
        Сохранить пороги
      </button>
    </form>
  </div>
</div>

{{-- Log --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mt-6">
  <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">Последние отправки</div>
  <table class="w-full text-sm">
    <thead class="text-gray-500 text-xs uppercase">
      <tr>
        <th class="px-4 py-2 text-left">Время</th>
        <th class="px-4 py-2 text-left">Тип</th>
        <th class="px-4 py-2 text-left">Кому</th>
        <th class="px-4 py-2 text-left">Сообщение</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
      @forelse($log as $row)
        <tr class="align-top">
          <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $row->created_at?->format('d.m H:i') }}</td>
          <td class="px-4 py-2 text-xs font-mono text-gray-400 whitespace-nowrap">{{ $row->type }}</td>
          <td class="px-4 py-2 text-xs font-mono whitespace-nowrap {{ $row->ok ? 'text-gray-300' : 'text-red-400' }}">
            {{ $row->ok ? '✓' : '✗' }} {{ $row->chat_id }}
          </td>
          <td class="px-4 py-2 text-xs text-gray-300">
            <div class="whitespace-pre-line">{{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($row->text)), 300) }}</div>
            @if($row->error)<div class="text-red-400 mt-1">{{ $row->error }}</div>@endif
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="px-4 py-4 text-gray-500 text-sm">Пока ничего не отправлялось.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

@endsection
