<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Filter rule evaluated by the Python parser before every lot upsert.
 * Rules are hot-reloaded by LotRepository._get_filter_engine() every 60s.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $source
 * @property string      $field
 * @property string      $operator
 * @property string|null $value
 * @property string      $action
 * @property int         $priority
 * @property string|null $rule_group_id
 * @property string      $phase
 * @property bool        $enabled
 * @property string|null $description
 */
class ParseFilter extends Model
{
    protected $table = 'parse_filters';

    protected $fillable = [
        'name', 'source', 'field', 'operator', 'value',
        'action', 'priority', 'rule_group_id', 'phase', 'enabled', 'description',
    ];

    protected $casts = [
        'enabled'  => 'boolean',
        'priority' => 'integer',
    ];

    public const OPERATORS = [
        'eq', 'ne', 'gt', 'gte', 'lt', 'lte',
        'in', 'not_in', 'between',
        'is_null', 'is_not_null',
        'contains', 'not_contains', 'regex',
    ];

    public const ACTIONS = ['allow', 'skip', 'flag', 'mark_inactive'];

    public const SOURCES = ['encar'];

    public const PHASES = ['pre', 'post'];

    public const PHASE_LABELS = [
        'pre'  => 'До осмотра',
        'post' => 'После осмотра',
    ];

    /** Human-friendly labels for UI. */
    public const OPERATOR_LABELS = [
        'eq'           => '= равно',
        'ne'           => '≠ не равно',
        'gt'           => '> больше',
        'gte'          => '≥ больше или равно',
        'lt'           => '< меньше',
        'lte'          => '≤ меньше или равно',
        'in'           => '∈ в списке',
        'not_in'       => '∉ не в списке',
        'between'      => 'между [мин,макс]',
        'is_null'      => 'пусто (null)',
        'is_not_null'  => 'не пусто (не null)',
        'contains'     => 'содержит',
        'not_contains' => 'не содержит',
        'regex'        => 'совпадает с regex',
    ];

    public const ACTION_LABELS = [
        'allow'         => '✓ пропустить (белый список)',
        'skip'          => '✗ исключить (не сохранять)',
        'flag'          => '⚑ пометить (сохранить + тег)',
        'mark_inactive' => '⊘ сделать неактивным',
    ];

    /** Decode the JSON-encoded value column. */
    public function decodedValue(): mixed
    {
        if ($this->value === null || $this->value === '') {
            return null;
        }
        $decoded = json_decode($this->value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->value;
    }
}
