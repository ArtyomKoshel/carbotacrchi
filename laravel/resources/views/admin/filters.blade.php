@extends('admin.layout')
@section('title', 'Фильтры')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@php
  $ui = \App\Support\AdminUiLabels::class;
@endphp

@if($errors->any())
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  <p class="font-semibold mb-1">Ошибки валидации:</p>
  <ul class="list-disc list-inside">
    @foreach($errors->all() as $error)
      <li>{{ $error }}</li>
    @endforeach
  </ul>
</div>
@endif

<p class="text-sm text-gray-500 mb-6">
  Правила проверяются перед каждым сохранением лота. Парсер перезагружает правила каждые 60 секунд
  @if($recentHits > 0)
    · <span class="text-blue-400">{{ $recentHits }} лот(ов) деактивировано правилами за последние 24ч</span>
  @endif
</p>

{{-- Serialize schema for Alpine --}}
<script>
  window.__FIELD_SCHEMA__ = @json($schema['fields'] ?? []);
  window.__OPERATOR_LABELS__ = @json($operatorLabels);
  window.__PHASE_LABELS__ = @json($phaseLabels ?? ['pre' => 'До осмотра', 'post' => 'После осмотра']);
</script>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6"
     x-data="filterForm({})">

  {{-- Header with add button --}}
  <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800">
    <span class="text-sm font-semibold text-white">
      {{ count($filters) }} правил(о) добавлено
    </span>
    <button type="button"
            @click="openCreate()"
            class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
      + Новое правило
    </button>
  </div>

  {{-- Rules table --}}
  @if(count($filters) === 0)
    <div class="px-5 py-10 text-center text-gray-500 text-sm">
      Правил пока нет. Парсер будет принимать все лоты до добавления правил.
    </div>
  @else
    <table class="w-full text-sm">
      <thead class="bg-gray-900/60 text-gray-500 text-xs uppercase">
        <tr>
          <th class="px-4 py-2 text-left font-medium">Приор.</th>
          <th class="px-4 py-2 text-left font-medium">Название</th>
          <th class="px-4 py-2 text-left font-medium">Группа</th>
          <th class="px-4 py-2 text-left font-medium">Охват</th>
          <th class="px-4 py-2 text-left font-medium">Условие</th>
          <th class="px-4 py-2 text-left font-medium">Действие</th>
          <th class="px-4 py-2 text-left font-medium">Фаза</th>
          <th class="px-4 py-2 text-center font-medium">Вкл.</th>
          <th class="px-4 py-2 text-right font-medium">Действия</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-800/60">
        @foreach($filters as $filter)
          <tr class="{{ $filter->enabled ? 'text-gray-200' : 'text-gray-500' }} hover:bg-gray-800/30">
            <td class="px-4 py-3 font-mono text-xs">{{ $filter->priority }}</td>
            <td class="px-4 py-3">
              <div class="font-semibold text-white">{{ $filter->name }}</div>
              @if($filter->description)
                <div class="text-xs text-gray-500 mt-0.5">{{ $filter->description }}</div>
              @endif
            </td>
            <td class="px-4 py-3 text-xs">
              @if($filter->rule_group_id)
                <span class="px-2 py-0.5 rounded bg-cyan-900/60 text-cyan-300" title="AND-группа: должны совпасть все правила группы">
                  AND:{{ $filter->rule_group_id }}
                </span>
              @else
                <span class="text-gray-600">—</span>
              @endif
            </td>
            <td class="px-4 py-3 text-xs">
              @if($filter->source)
                <span class="px-2 py-0.5 rounded bg-purple-900/60 text-purple-300">{{ $ui::source($filter->source) }}</span>
              @else
                <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-500">глобально</span>
              @endif
            </td>
            <td class="px-4 py-3 font-mono text-xs">
              <span class="text-blue-300">{{ $ui::field($filter->field) }}</span>
              <span class="text-gray-600">({{ $filter->field }})</span>
              <span class="text-gray-500">{{ $filter->operator }}</span>
              <span class="text-yellow-300">{{ $filter->value ?? '—' }}</span>
            </td>
            <td class="px-4 py-3">
              @php
                $actionClass = match($filter->action) {
                  'skip' => 'bg-red-900/60 text-red-300',
                  'flag' => 'bg-yellow-900/60 text-yellow-300',
                  'mark_inactive' => 'bg-orange-900/60 text-orange-300',
                  'allow' => 'bg-green-900/60 text-green-300',
                  default => 'bg-gray-800 text-gray-400',
                };
              @endphp
              <span class="px-2 py-0.5 rounded text-xs {{ $actionClass }}">
                {{ $actionLabels[$filter->action] ?? $filter->action }}
              </span>
            </td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 rounded text-xs {{ ($filter->phase ?? 'pre') === 'post' ? 'bg-indigo-900/60 text-indigo-300' : 'bg-gray-800 text-gray-500' }}">
                {{ $phaseLabels[$filter->phase ?? 'pre'] ?? ($filter->phase ?? 'pre') }}
              </span>
            </td>
            <td class="px-4 py-3 text-center">
              <form method="POST" action="{{ route('admin.filters.toggle', $filter->id) }}" class="inline">
                @csrf @method('PATCH')
                <button type="submit"
                        class="px-2 py-0.5 rounded text-xs {{ $filter->enabled ? 'bg-green-900/60 text-green-300' : 'bg-gray-800 text-gray-500' }} hover:opacity-80">
                  {{ $filter->enabled ? 'вкл' : 'выкл' }}
                </button>
              </form>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button"
                      @click='openEdit(@json($filter))'
                      class="text-xs text-blue-400 hover:text-blue-300">Редакт.</button>
              <form method="POST" action="{{ route('admin.filters.delete', $filter->id) }}" class="inline"
                    onsubmit="return confirm('Удалить правило {{ $filter->name }}?');">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-red-400 hover:text-red-300">удалить</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- Modal form --}}
  <div x-show="open" x-cloak
       class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
       @click.self="open = false" @keydown.escape.window="open = false">
    <form :method="editId ? 'POST' : 'POST'"
          :action="editId ? `/admin/filters/${editId}` : '{{ route('admin.filters.create') }}'"
          @submit="beforeSubmit"
          class="w-full max-w-2xl bg-gray-900 border border-gray-700 rounded-xl shadow-xl overflow-hidden">
      @csrf
      <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>

      <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
        <h2 class="text-white font-semibold">
          <span x-text="editId ? 'Редактирование правила' : 'Новое правило'"></span>
        </h2>
        <button type="button" @click="open = false" class="text-gray-500 hover:text-white">✕</button>
      </div>

      <div class="px-5 py-5 grid grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">Название <span class="text-red-500">*</span></label>
          <input type="text" name="name" x-model="form.name" required
                 pattern="[a-z0-9_]+" placeholder="exclude_custom"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Малые буквы, цифры, подчёркивание.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Охват</label>
          <select name="source" x-model="form.source"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">Все источники (глобально)</option>
            @foreach($sources as $src)
              <option value="{{ $src }}">{{ $ui::source($src) }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Приоритет <span class="text-red-500">*</span></label>
          <input type="number" name="priority" x-model.number="form.priority" min="0" max="10000" required
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Меньше = проверяется первым (0–10000).</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">AND-группа</label>
          <input type="text" name="rule_group_id" x-model="form.rule_group_id"
                 pattern="[a-zA-Z0-9_]*" placeholder="напр. old_expensive"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Одинаковая группа = логика AND.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Фаза</label>
          <select name="phase" x-model="form.phase"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            @foreach($phaseLabels as $val => $label)
              <option value="{{ $val }}">{{ $label }}</option>
            @endforeach
          </select>
          <p class="text-xs text-gray-600 mt-1">Post = после загрузки данных осмотра.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Поле <span class="text-red-500">*</span></label>
          <select name="field" x-model="form.field" required @change="onFieldChange"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">— выберите —</option>
            @foreach($groupedFields as $category => $fields)
              <optgroup label="{{ $category }}">
                @foreach($fields as $f)
                  <option value="{{ $f['name'] }}">{{ $ui::field($f['name']) }} ({{ $f['name'] }}, {{ $f['dtype'] }})</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
          <p class="text-xs text-gray-600 mt-1" x-text="currentField?.description || ''"></p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Оператор <span class="text-red-500">*</span></label>
          <select name="operator" x-model="form.operator" required
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <template x-for="op in availableOperators" :key="op">
              <option :value="op" x-text="opLabel(op)"></option>
            </template>
          </select>
        </div>

        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">
            Значение
            <span class="text-gray-600" x-show="!needsValue">(не используется этим оператором)</span>
          </label>
          <template x-if="currentField?.dtype === 'enum' && currentField?.enum_values">
            <select name="value" x-model="form.value"
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white"
                    :disabled="!needsValue">
              <option value="">— выберите —</option>
              <template x-for="v in currentField.enum_values" :key="v">
                <option :value="`&quot;${v}&quot;`" x-text="v"></option>
              </template>
            </select>
          </template>
          <template x-if="currentField?.dtype === 'bool'">
            <select name="value" x-model="form.value"
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white"
                    :disabled="!needsValue">
              <option value="true">true</option>
              <option value="false">false</option>
              <option value="null">null</option>
            </select>
          </template>
          <template x-if="!['enum','bool'].includes(currentField?.dtype) || !currentField?.enum_values">
            <input type="text" name="value" x-model="form.value" :disabled="!needsValue"
                   placeholder='напр. 200000, "rental", ["a","b"], [0,100]'
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white disabled:opacity-40">
          </template>
          <p class="text-xs text-gray-600 mt-1">JSON. Простые значения обертываются автоматически.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Действие <span class="text-red-500">*</span></label>
          <select name="action" x-model="form.action" required
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            @foreach($actions as $a)
              <option value="{{ $a }}">{{ $actionLabels[$a] }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Включено</label>
          <label class="flex items-center gap-2 mt-2">
            <input type="checkbox" name="enabled" value="1" x-model="form.enabled"
                   class="w-4 h-4 bg-gray-800 border-gray-700 rounded">
            <span class="text-sm text-gray-300" x-text="form.enabled ? 'правило активно' : 'отключено'"></span>
          </label>
        </div>

        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">Описание</label>
          <input type="text" name="description" x-model="form.description" maxlength="255"
                 placeholder="Краткое пояснение для админа"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>

      <div class="px-5 py-4 bg-gray-900/60 border-t border-gray-800 flex items-center justify-between">
        <span class="text-xs text-gray-600">
          Изменения вступают в силу через 60 секунд (парсер перечитывает правила).
        </span>
        <div class="space-x-2">
          <button type="button" @click="open = false"
                  class="px-4 py-2 rounded-lg text-sm text-gray-400 hover:text-white">Отмена</button>
          <button type="submit"
                  class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold">
            <span x-text="editId ? 'Сохранить' : 'Создать'"></span>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
function filterForm() {
  return {
    open: false,
    editId: null,
    form: { name:'', source:'', field:'', operator:'eq', value:'', action:'skip', priority:100, rule_group_id:'', phase:'pre', enabled:true, description:'' },

    openCreate() {
      this.editId = null;
      this.form = { name:'', source:'', field:'', operator:'eq', value:'', action:'skip', priority:100, rule_group_id:'', phase:'pre', enabled:true, description:'' };
      this.open = true;
    },
    openEdit(filter) {
      this.editId = filter.id;
      this.form = {
        name: filter.name,
        source: filter.source || '',
        field: filter.field,
        operator: filter.operator,
        value: filter.value ?? '',
        action: filter.action,
        priority: filter.priority,
        rule_group_id: filter.rule_group_id || '',
        phase: filter.phase || 'pre',
        enabled: !!filter.enabled,
        description: filter.description || '',
      };
      this.open = true;
    },
    get currentField() {
      return window.__FIELD_SCHEMA__.find(f => f.name === this.form.field) || null;
    },
    get availableOperators() {
      return this.currentField?.operators || ['eq','ne','gt','gte','lt','lte','between','in','not_in','is_null','is_not_null','contains','not_contains','regex'];
    },
    get needsValue() {
      return !['is_null','is_not_null'].includes(this.form.operator);
    },
    opLabel(op) {
      return window.__OPERATOR_LABELS__[op] || op;
    },
    onFieldChange() {
      // Clamp operator to whatever the new field supports
      if (!this.availableOperators.includes(this.form.operator)) {
        this.form.operator = this.availableOperators[0] || 'eq';
      }
      this.form.value = '';
    },
    beforeSubmit(e) {
      if (!this.needsValue) {
        // ensure is_null/is_not_null sends no value
        this.form.value = '';
      }
    },
  };
}
</script>

@endsection
