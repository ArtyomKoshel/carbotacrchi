@extends('admin.layout')
@section('title', 'Пользователи')

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

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

  {{-- Users list --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-800">
      <span class="text-sm font-semibold text-white">Администраторы</span>
    </div>
    <table class="w-full text-sm">
      <thead class="bg-gray-900/60 text-gray-500 text-xs uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">Логин</th>
          <th class="px-4 py-2.5 text-left">Роль</th>
          <th class="px-4 py-2.5 text-left">Создан</th>
          <th class="px-4 py-2.5 text-right">Действия</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-800">
        @foreach($users as $u)
          <tr class="hover:bg-gray-800/30">
            <td class="px-4 py-3 font-semibold text-white">
              {{ $u->username }}
              @if($u->id === session('admin_user_id'))
                <span class="ml-1 text-xs text-gray-500">(вы)</span>
              @endif
            </td>
            <td class="px-4 py-3">
              @if($u->role === 'super')
                <span class="px-2 py-0.5 rounded bg-yellow-900/60 text-yellow-400 text-xs">★ super</span>
              @else
                <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-400 text-xs">limited</span>
              @endif
            </td>
            <td class="px-4 py-3 text-gray-500 text-xs">{{ $u->created_at->format('Y-m-d') }}</td>
            <td class="px-4 py-3 text-right">
              {{-- Change password --}}
              <button type="button"
                      onclick="document.getElementById('pwd-form-{{ $u->id }}').classList.toggle('hidden')"
                      class="text-xs text-blue-400 hover:text-blue-300 mr-2">Пароль</button>

              @if($u->id !== session('admin_user_id'))
                <form method="POST" action="{{ route('admin.users.delete', $u->id) }}" class="inline"
                      onsubmit="return confirm('Удалить пользователя {{ $u->username }}?')">
                  @csrf @method('DELETE')
                  <button type="submit" class="text-xs text-red-400 hover:text-red-300">Удалить</button>
                </form>
              @endif
            </td>
          </tr>
          <tr id="pwd-form-{{ $u->id }}" class="hidden bg-gray-800/20">
            <td colspan="4" class="px-4 py-3">
              <form method="POST" action="{{ route('admin.users.password', $u->id) }}"
                    class="flex gap-2 items-center">
                @csrf @method('PATCH')
                <input type="password" name="password" placeholder="Новый пароль (мин. 6 символов)"
                       class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm text-white">
                <button type="submit"
                        class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold">
                  Сохранить
                </button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    {{-- Create new user --}}
    <div class="px-5 py-4 border-t border-gray-800">
      <div class="text-xs font-semibold text-gray-400 mb-3 uppercase tracking-wider">Добавить пользователя</div>
      <form method="POST" action="{{ route('admin.users.create') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div>
          <label class="text-xs text-gray-500 block mb-1">Логин</label>
          <input type="text" name="username" required maxlength="64"
                 class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-40">
        </div>
        <div>
          <label class="text-xs text-gray-500 block mb-1">Пароль</label>
          <input type="password" name="password" required minlength="6"
                 class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white w-40">
        </div>
        <div>
          <label class="text-xs text-gray-500 block mb-1">Роль</label>
          <select name="role" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="limited">limited</option>
            <option value="super">super</option>
          </select>
        </div>
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold">
          + Создать
        </button>
      </form>
    </div>
  </div>

  {{-- Page permissions --}}
  <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-800">
      <span class="text-sm font-semibold text-white">Доступ «limited» пользователей</span>
      <p class="text-xs text-gray-500 mt-1">Отметьте страницы, доступные пользователям с ролью limited.</p>
    </div>
    <form method="POST" action="{{ route('admin.users.permissions') }}">
      @csrf
      <div class="px-5 py-4 space-y-2">
        @foreach(\App\Models\AdminPagePermission::PAGES as $key => $label)
          @php $perm = $permissions->get($key) @endphp
          <label class="flex items-center gap-3 cursor-pointer group">
            <input type="checkbox" name="pages[]" value="{{ $key }}"
                   {{ ($perm && $perm->enabled) ? 'checked' : '' }}
                   class="w-4 h-4 rounded bg-gray-800 border-gray-700 text-blue-500">
            <span class="text-sm text-gray-300 group-hover:text-white transition">{{ $label }}</span>
            <span class="text-xs text-gray-600 font-mono">{{ $key }}</span>
          </label>
        @endforeach
      </div>
      <div class="px-5 pb-4">
        <button type="submit"
                class="px-5 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition">
          Сохранить права
        </button>
      </div>
    </form>
  </div>

</div>

@endsection
