@extends('admin.layout')
@section('title', 'Таксономия')

@section('content')
@if(session('success'))
  <div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">{{ session('error') }}</div>
@endif

<div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
  <h2 class="text-lg font-semibold text-white">Таксономия: правила + очередь аномалий</h2>
  @if(session('admin_role') === 'super')
  <form method="post" action="{{ route('admin.taxonomy.ingest') }}" class="flex items-center gap-2">
    @csrf
    <input type="hidden" name="source" value="{{ $source }}">
    <input type="number" name="max" min="100" max="500000" value="50000"
      class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
    <button class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs">Ingest anomalies</button>
  </form>
  @endif
</div>

<form method="get" class="mb-4 flex items-center gap-2 flex-wrap">
  <input type="text" name="source" value="{{ $source }}" placeholder="source"
    class="w-28 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
  <select name="status" class="bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-sm text-white">
    @foreach(['new' => 'new', 'rule_created' => 'rule_created', 'ignored' => 'ignored', '' => 'all'] as $k => $label)
      <option value="{{ $k }}" {{ $status === $k ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select>
  <button class="px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-white text-xs">Фильтр</button>
</form>

@if(session('admin_role') === 'super')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-5">
  <h3 class="text-sm text-gray-300 font-semibold mb-3">Новое правило</h3>
  <form method="post" action="{{ route('admin.taxonomy.rules.store') }}" class="grid md:grid-cols-8 gap-2">
    @csrf
    <input name="source" value="{{ $source }}" placeholder="source" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="make" placeholder="make" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="model_contains" placeholder="model contains" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input name="unknown_tail" placeholder="unknown tail" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <select name="action" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
      @foreach(['set_trim','set_generation','strip_tail','replace_model'] as $action)
        <option value="{{ $action }}">{{ $action }}</option>
      @endforeach
    </select>
    <input name="action_value" placeholder="value" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <input type="number" name="priority" value="100" min="1" max="10000" class="bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-sm text-white">
    <button class="rounded bg-green-600 hover:bg-green-500 text-white text-sm px-3">Добавить</button>
  </form>
</div>
@endif

<div class="grid lg:grid-cols-2 gap-4">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 overflow-auto">
    <h3 class="text-sm text-gray-300 font-semibold mb-3">Правила</h3>
    <table class="w-full text-xs text-gray-300">
      <thead class="text-gray-500 border-b border-gray-800">
        <tr><th class="text-left py-2">Match</th><th class="text-left py-2">Action</th><th class="text-left py-2">Meta</th></tr>
      </thead>
      <tbody>
        @foreach($rules as $rule)
          <tr class="border-b border-gray-800 align-top">
            <td class="py-2">
              <div>{{ $rule->source }} {{ $rule->make ? '· '.$rule->make : '' }}</div>
              <div class="text-gray-500">tail={{ $rule->unknown_tail ?: '—' }} · model~={{ $rule->model_contains ?: '—' }}</div>
            </td>
            <td class="py-2">
              <div>{{ $rule->action }}</div>
              <div class="text-gray-500">{{ $rule->action_value ?: '—' }}</div>
            </td>
            <td class="py-2">
              <div>prio {{ $rule->priority }} · hits {{ $rule->hit_count }}</div>
              <div class="text-gray-500">{{ $rule->is_active ? 'active' : 'inactive' }}</div>
              @if(session('admin_role') === 'super')
              <form method="post" action="{{ route('admin.taxonomy.rules.delete', $rule->id) }}" class="mt-1">
                @csrf @method('DELETE')
                <button class="text-red-400 hover:text-red-300">delete</button>
              </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="mt-3">{{ $rules->links() }}</div>
  </div>

  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 overflow-auto">
    <h3 class="text-sm text-gray-300 font-semibold mb-3">Очередь аномалий</h3>
    <table class="w-full text-xs text-gray-300">
      <thead class="text-gray-500 border-b border-gray-800">
        <tr><th class="text-left py-2">Tail</th><th class="text-left py-2">Seen</th><th class="text-left py-2">Suggestion</th></tr>
      </thead>
      <tbody>
        @foreach($queue as $row)
          <tr class="border-b border-gray-800 align-top">
            <td class="py-2">
              <div class="text-white">{{ $row->unknown_tail }}</div>
              <div class="text-gray-500">{{ $row->make ?: '—' }} · {{ $row->reason ?: '—' }}</div>
              <div class="text-gray-600">{{ $row->sample_model_raw ?: '' }}</div>
            </td>
            <td class="py-2">
              <div>{{ $row->seen_count }}</div>
              <div class="text-gray-500">{{ optional($row->last_seen_at)->diffForHumans() }}</div>
              <div class="text-gray-600">{{ $row->status }}</div>
            </td>
            <td class="py-2">
              <div>{{ $row->suggested_action ?: '—' }}</div>
              <div class="text-gray-500">{{ $row->suggested_value ?: '—' }} ({{ $row->suggestion_confidence !== null ? number_format($row->suggestion_confidence * 100, 0).'%' : '—' }})</div>
              @if(session('admin_role') === 'super')
                <form method="post" action="{{ route('admin.taxonomy.queue.create-rule', $row->id) }}" class="mt-1 inline-block">
                  @csrf
                  <button class="text-indigo-400 hover:text-indigo-300">create rule</button>
                </form>
                <form method="post" action="{{ route('admin.taxonomy.queue.update', $row->id) }}" class="mt-1 inline-block ml-2">
                  @csrf @method('PATCH')
                  <input type="hidden" name="status" value="ignored">
                  <button class="text-gray-400 hover:text-gray-200">ignore</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="mt-3">{{ $queue->links() }}</div>
  </div>
</div>
@endsection
