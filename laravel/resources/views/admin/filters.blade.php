@extends('admin.layout')
@section('title', 'Filters')

@section('content')

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-green-900/40 border border-green-700 text-green-300 text-sm">
  {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="mb-4 px-4 py-3 rounded-lg bg-red-900/40 border border-red-700 text-red-300 text-sm">
  <p class="font-semibold mb-1">Validation errors:</p>
  <ul class="list-disc list-inside">
    @foreach($errors->all() as $error)
      <li>{{ $error }}</li>
    @endforeach
  </ul>
</div>
@endif

<p class="text-sm text-gray-500 mb-6">
  Rules are evaluated before every lot upsert. Parser reloads rules from DB every 60 seconds
  @if($recentHits > 0)
    · <span class="text-blue-400">{{ $recentHits }} lot(s) deactivated by rules in the last 24h</span>
  @endif
</p>

{{-- Serialize schema for Alpine --}}
<script>
  window.__FIELD_SCHEMA__ = @json($schema['fields'] ?? []);
  window.__OPERATOR_LABELS__ = @json($operatorLabels);
  window.__PHASE_LABELS__ = @json($phaseLabels ?? {'pre': 'Pre-filter', 'post': 'Post-filter'});
</script>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6"
     x-data="filterForm({})">

  {{-- Header with add button --}}
  <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800">
    <span class="text-sm font-semibold text-white">
      {{ count($filters) }} rule(s) defined
    </span>
    <button type="button"
            @click="openCreate()"
            class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
      + New rule
    </button>
  </div>

  {{-- Rules table --}}
  @if(count($filters) === 0)
    <div class="px-5 py-10 text-center text-gray-500 text-sm">
      No rules yet. The parser will accept all lots until you add rules.
    </div>
  @else
    <table class="w-full text-sm">
      <thead class="bg-gray-900/60 text-gray-500 text-xs uppercase">
        <tr>
          <th class="px-4 py-2 text-left font-medium">Prio</th>
          <th class="px-4 py-2 text-left font-medium">Name</th>
          <th class="px-4 py-2 text-left font-medium">Group</th>
          <th class="px-4 py-2 text-left font-medium">Scope</th>
          <th class="px-4 py-2 text-left font-medium">Condition</th>
          <th class="px-4 py-2 text-left font-medium">Action</th>
          <th class="px-4 py-2 text-left font-medium">Phase</th>
          <th class="px-4 py-2 text-center font-medium">Enabled</th>
          <th class="px-4 py-2 text-right font-medium">Manage</th>
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
                <span class="px-2 py-0.5 rounded bg-cyan-900/60 text-cyan-300" title="AND-group: all rules in this group must match">
                  AND:{{ $filter->rule_group_id }}
                </span>
              @else
                <span class="text-gray-600">—</span>
              @endif
            </td>
            <td class="px-4 py-3 text-xs">
              @if($filter->source)
                <span class="px-2 py-0.5 rounded bg-purple-900/60 text-purple-300">{{ $filter->source }}</span>
              @else
                <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-500">global</span>
              @endif
            </td>
            <td class="px-4 py-3 font-mono text-xs">
              <span class="text-blue-300">{{ $filter->field }}</span>
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
                {{ $filter->action }}
              </span>
            </td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 rounded text-xs {{ ($filter->phase ?? 'pre') === 'post' ? 'bg-indigo-900/60 text-indigo-300' : 'bg-gray-800 text-gray-500' }}">
                {{ $filter->phase ?? 'pre' }}
              </span>
            </td>
            <td class="px-4 py-3 text-center">
              <form method="POST" action="{{ route('admin.filters.toggle', $filter->id) }}" class="inline">
                @csrf @method('PATCH')
                <button type="submit"
                        class="px-2 py-0.5 rounded text-xs {{ $filter->enabled ? 'bg-green-900/60 text-green-300' : 'bg-gray-800 text-gray-500' }} hover:opacity-80">
                  {{ $filter->enabled ? 'on' : 'off' }}
                </button>
              </form>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button"
                      @click='openEdit(@json($filter))'
                      class="text-xs text-blue-400 hover:text-blue-300">edit</button>
              <form method="POST" action="{{ route('admin.filters.delete', $filter->id) }}" class="inline"
                    onsubmit="return confirm('Delete rule {{ $filter->name }}?');">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-red-400 hover:text-red-300">delete</button>
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
          <span x-text="editId ? 'Edit rule' : 'New rule'"></span>
        </h2>
        <button type="button" @click="open = false" class="text-gray-500 hover:text-white">✕</button>
      </div>

      <div class="px-5 py-5 grid grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" x-model="form.name" required
                 pattern="[a-z0-9_]+" placeholder="exclude_custom"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Lowercase letters, digits, underscore.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Scope</label>
          <select name="source" x-model="form.source"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">All sources (global)</option>
            @foreach($sources as $src)
              <option value="{{ $src }}">{{ $src }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Priority <span class="text-red-500">*</span></label>
          <input type="number" name="priority" x-model.number="form.priority" min="0" max="10000" required
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Lower = evaluated first (0–10000).</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">AND-group</label>
          <input type="text" name="rule_group_id" x-model="form.rule_group_id"
                 pattern="[a-zA-Z0-9_]*" placeholder="e.g. old_expensive"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
          <p class="text-xs text-gray-600 mt-1">Same group = AND logic.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Phase</label>
          <select name="phase" x-model="form.phase"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            @foreach($phaseLabels as $val => $label)
              <option value="{{ $val }}">{{ $label }}</option>
            @endforeach
          </select>
          <p class="text-xs text-gray-600 mt-1">Post = after inspection data loaded.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Field <span class="text-red-500">*</span></label>
          <select name="field" x-model="form.field" required @change="onFieldChange"
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">— select —</option>
            @foreach($groupedFields as $category => $fields)
              <optgroup label="{{ $category }}">
                @foreach($fields as $f)
                  <option value="{{ $f['name'] }}">{{ $f['name'] }} ({{ $f['dtype'] }})</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
          <p class="text-xs text-gray-600 mt-1" x-text="currentField?.description || ''"></p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Operator <span class="text-red-500">*</span></label>
          <select name="operator" x-model="form.operator" required
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <template x-for="op in availableOperators" :key="op">
              <option :value="op" x-text="opLabel(op)"></option>
            </template>
          </select>
        </div>

        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">
            Value
            <span class="text-gray-600" x-show="!needsValue">(not used by this operator)</span>
          </label>
          <template x-if="currentField?.dtype === 'enum' && currentField?.enum_values">
            <select name="value" x-model="form.value"
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white"
                    :disabled="!needsValue">
              <option value="">— select —</option>
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
                   placeholder='e.g. 200000, "rental", ["a","b"], [0,100]'
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white disabled:opacity-40">
          </template>
          <p class="text-xs text-gray-600 mt-1">JSON literal. Plain strings/numbers are auto-wrapped.</p>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Action <span class="text-red-500">*</span></label>
          <select name="action" x-model="form.action" required
                  class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            @foreach($actions as $a)
              <option value="{{ $a }}">{{ $actionLabels[$a] }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="text-xs text-gray-500 mb-1 block">Enabled</label>
          <label class="flex items-center gap-2 mt-2">
            <input type="checkbox" name="enabled" value="1" x-model="form.enabled"
                   class="w-4 h-4 bg-gray-800 border-gray-700 rounded">
            <span class="text-sm text-gray-300" x-text="form.enabled ? 'rule is active' : 'disabled'"></span>
          </label>
        </div>

        <div class="col-span-2">
          <label class="text-xs text-gray-500 mb-1 block">Description</label>
          <input type="text" name="description" x-model="form.description" maxlength="255"
                 placeholder="Short explanation for other admins"
                 class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </div>
      </div>

      <div class="px-5 py-4 bg-gray-900/60 border-t border-gray-800 flex items-center justify-between">
        <span class="text-xs text-gray-600">
          Changes take effect within 60 seconds (parser hot-reloads rules).
        </span>
        <div class="space-x-2">
          <button type="button" @click="open = false"
                  class="px-4 py-2 rounded-lg text-sm text-gray-400 hover:text-white">Cancel</button>
          <button type="submit"
                  class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold">
            <span x-text="editId ? 'Save changes' : 'Create rule'"></span>
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
