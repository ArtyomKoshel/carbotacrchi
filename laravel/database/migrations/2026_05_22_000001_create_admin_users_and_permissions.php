<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('password');
            $table->enum('role', ['super', 'limited'])->default('limited');
            $table->timestamps();
        });

        Schema::create('admin_page_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('page_key', 64)->unique();
            $table->string('label', 128);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // Seed default super admin
        $username = env('ADMIN_USERNAME', 'admin');
        $password = env('ADMIN_PASSWORD', 'admin123');
        DB::table('admin_users')->insert([
            'username'   => $username,
            'password'   => Hash::make($password),
            'role'       => 'super',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed page permissions (all enabled by default for limited role)
        $pages = [
            'dashboard'       => 'Дашборд',
            'lots-browse'     => 'Поиск лотов',
            'changes'         => 'Изменения',
            'logs'            => 'Логи',
            'jobs'            => 'Задачи',
            'schedules'       => 'Расписания',
            'filters'         => 'Фильтры',
            'bot-filters'     => 'Бот-фильтры',
            'filter-skip-log' => 'Лог пропусков',
            'fields'          => 'Поля',
            'lots'            => 'Репарсинг',
        ];

        foreach ($pages as $key => $label) {
            DB::table('admin_page_permissions')->insert([
                'page_key'   => $key,
                'label'      => $label,
                'enabled'    => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_page_permissions');
        Schema::dropIfExists('admin_users');
    }
};
