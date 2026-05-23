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
    ];

    /** Route name → page key */
    public const ROUTE_MAP = [
        'admin.dashboard'              => 'dashboard',
        'admin.lots-browse'            => 'lots-browse',
        'admin.changes'                => 'changes',
        'admin.stats'                  => 'changes',
        'admin.logs'                   => 'logs',
        'admin.jobs'                   => 'jobs',
        'admin.jobs.launch'            => 'jobs',
        'admin.jobs.cancel'            => 'jobs',
        'admin.jobs.progress'          => 'jobs',
        'admin.jobs.events'            => 'jobs',
        'admin.jobs.detail'            => 'jobs',
        'admin.jobs.log'               => 'jobs',
        'admin.schedules'              => 'schedules',
        'admin.schedules.update'       => 'schedules',
        'admin.proxy.balance'          => 'schedules',
        'admin.filters'                => 'filters',
        'admin.filters.create'         => 'filters',
        'admin.filters.update'         => 'filters',
        'admin.filters.delete'         => 'filters',
        'admin.filters.toggle'         => 'filters',
        'admin.bot-filters'            => 'bot-filters',
        'admin.bot-filters.update'     => 'bot-filters',
        'admin.bot-filters.reset'      => 'bot-filters',
        'admin.bot-filters.preview'    => 'bot-filters',
        'admin.filter-skip-log.index'  => 'filter-skip-log',
        'admin.filter-skip-log.cleanup'=> 'filter-skip-log',
        'admin.fields'                 => 'fields',
        'admin.fields.recompute'       => 'fields',
        'admin.fields.schema'          => 'fields',
        'admin.lots'                   => 'lots',
        'admin.lots.reparse'           => 'lots',
        'admin.reparse.status'         => 'lots',
        'admin.users'                  => null, // super-only, handled separately
        'admin.users.create'           => null,
        'admin.users.delete'           => null,
        'admin.users.password'         => null,
        'admin.users.permissions'      => null,
    ];

    /** Get enabled page keys for limited role */
    public static function allowedKeys(): array
    {
        return static::where('enabled', true)->pluck('page_key')->toArray();
    }
}
