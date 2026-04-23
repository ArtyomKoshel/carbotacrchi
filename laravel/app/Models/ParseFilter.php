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
 * @property bool        $enabled
 * @property string|null $description
 */
class ParseFilter extends Model
{
    protected $table = 'parse_filters';

    protected $fillable = [
        'name', 'source', 'field', 'operator', 'value',
        'action', 'priority', 'rule_group_id', 'enabled', 'description',
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

    public const SOURCES = ['encar', 'kbcha'];

    /** Human-friendly labels for UI. */
    public const OPERATOR_LABELS = [
        'eq'           => '= equal',
        'ne'           => '≠ not equal',
        'gt'           => '> greater',
        'gte'          => '≥ greater or equal',
        'lt'           => '< less',
        'lte'          => '≤ less or equal',
        'in'           => '∈ in list',
        'not_in'       => '∉ not in list',
        'between'      => 'between [min,max]',
        'is_null'      => 'is null',
        'is_not_null'  => 'is not null',
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'regex'        => 'matches regex',
    ];

    public const ACTION_LABELS = [
        'allow'         => '✓ allow (whitelist)',
        'skip'          => '✗ skip (do not save)',
        'flag'          => '⚑ flag (save + tag)',
        'mark_inactive' => '⊘ mark inactive',
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
