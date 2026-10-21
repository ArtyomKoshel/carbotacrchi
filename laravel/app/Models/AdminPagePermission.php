<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPagePermission extends Model
{
    protected $table = 'admin_page_permissions';

    protected $fillable = ['page_key', 'label', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public const PAGES = [
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
        'taxonomy'        => 'Данные: переводы и каталог',
    ];

    /** Get enabled page keys for limited role */
    public static function allowedKeys(): array
    {
        return static::where('enabled', true)->pluck('page_key')->toArray();
    }

    /** allowedKeys(), read once per request (the panel checks it for every menu item). */
    public static function allowedKeysCached(): array
    {
        return once(fn () => static::allowedKeys());
    }
}
