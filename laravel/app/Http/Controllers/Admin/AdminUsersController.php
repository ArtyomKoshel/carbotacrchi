<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPagePermission;
use App\Models\AdminUser;
use Illuminate\Http\Request;

class AdminUsersController extends Controller
{
    public function index()
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $users       = AdminUser::orderBy('role')->orderBy('username')->get();
        $permissions = AdminPagePermission::orderBy('page_key')->get()->keyBy('page_key');

        return view('admin.users', compact('users', 'permissions'));
    }

    public function create(Request $request)
    {
        if (session('admin_role') !== 'super') abort(403);

        $request->validate([
            'username' => 'required|max:64|unique:admin_users,username',
            'password' => 'required|min:6|max:128',
            'role'     => 'required|in:super,limited',
        ], [
            'username.unique' => 'Пользователь с таким именем уже существует',
            'password.min'    => 'Пароль минимум 6 символов',
        ]);

        AdminUser::create([
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'role'     => $request->input('role'),
        ]);

        return back()->with('success', 'Пользователь создан');
    }

    public function delete(int $id)
    {
        if (session('admin_role') !== 'super') abort(403);
        if ($id === (int) session('admin_user_id')) {
            return back()->withErrors(['error' => 'Нельзя удалить собственный аккаунт']);
        }
        AdminUser::findOrFail($id)->delete();
        return back()->with('success', 'Пользователь удалён');
    }

    public function updatePassword(int $id, Request $request)
    {
        if (session('admin_role') !== 'super') abort(403);

        $request->validate(['password' => 'required|min:6|max:128']);

        $user = AdminUser::findOrFail($id);
        $user->password = $request->input('password');
        $user->save();

        return back()->with('success', "Пароль для «{$user->username}» обновлён");
    }

    public function updatePermissions(Request $request)
    {
        if (session('admin_role') !== 'super') abort(403);

        $enabled = $request->input('pages', []);

        foreach (AdminPagePermission::PAGES as $key => $label) {
            AdminPagePermission::where('page_key', $key)
                ->update(['enabled' => in_array($key, $enabled, true)]);
        }

        return back()->with('success', 'Права обновлены');
    }
}
