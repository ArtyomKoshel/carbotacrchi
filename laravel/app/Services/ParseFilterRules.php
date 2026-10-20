<?php

namespace App\Services;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\ParseFilter;
use App\Support\AdminUiLabels;
use App\Support\Taxonomy\TaxonomyCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Selection rules (parse_filters) in human terms: a sentence per rule, typed
 * values, generated names, how many current lots a rule would hit and how
 * often the parser has applied it. Semantics follow parser/filters: rules
 * without a group are OR, rules sharing rule_group_id must all match (AND),
 * `allow` stops the chain.
 */
class ParseFilterRules
{
    public const OPERATORS = [
        'eq'           => 'равно',
        'ne'           => 'не равно',
        'gt'           => 'больше',
        'gte'          => 'не меньше',
        'lt'           => 'меньше',
        'lte'          => 'не больше',
        'between'      => 'от … до',
        'in'           => 'одно из',
        'not_in'       => 'ни одно из',
        'is_null'      => 'не указано',
        'is_not_null'  => 'указано',
        'contains'     => 'содержит',
        'not_contains' => 'не содержит',
        'regex'        => 'совпадает с шаблоном',
    ];

    private const SYMBOLS = ['eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤'];

    public const ACTIONS = [
        'skip'          => 'Не сохранять',
        'mark_inactive' => 'Сохранить снятым',
        'flag'          => 'Сохранить с пометкой',
        'allow'         => 'Всегда пропускать',
    ];

    public const ACTION_HINTS = [
        'skip'          => 'лот не попадает в базу',
        'mark_inactive' => 'лот сохраняется, но не виден в поиске',
        'flag'          => 'лот сохраняется, имя правила пишется в raw_data._filter_flags',
        'allow'         => 'белый список: остальные правила для лота не проверяются',
    ];

    public const PHASES = ['pre' => 'До осмотра', 'post' => 'После осмотра'];

    /** Labels of enum values TaxonomyCatalog does not cover */
    private const ENUM_LABELS = [
        'sell_type' => [
            'sale' => 'обычная продажа', 'auction' => 'аукцион', 'lease' => 'лизинг', 'rental' => 'прокатная',
            'business' => 'юрлицо', 'under_contract' => 'под договором', 'insurance_hide' => 'история страховки скрыта',
        ],
        'lien_status'    => ['clean' => 'нет залога', 'lien' => 'залог', 'has_loan' => 'кредит'],
        'seizure_status' => ['clean' => 'нет ареста', 'seizure' => 'арест', 'seized' => 'арестован'],
        'source'         => ['encar' => 'Encar', 'kbcha' => 'KBChacha'],
    ];

    private const NO_VALUE = ['is_null', 'is_not_null'];

    public function __construct(private readonly FieldRegistryService $registry) {}

    /** @return array<string, array<string, string>> category => [field => label] */
    public function fieldOptions(): array
    {
        $groups = [];
        foreach ($this->registry->groupedFilterable() as $category => $fields) {
            foreach ($fields as $f) {
                $groups[self::categoryLabel($category)][$f['name']] = AdminUiLabels::field($f['name']) . " ({$f['name']})";
            }
        }

        return $groups;
    }

    public function field(?string $name): ?array
    {
        return $name ? $this->registry->get($name) : null;
    }

    /** @return array<string, string> operators the field supports, labelled */
    public function operatorOptions(?string $field): array
    {
        $allowed = $this->field($field)['operators'] ?? array_keys(self::OPERATORS);

        return array_intersect_key(self::OPERATORS, array_flip($allowed));
    }

    /** @return array<string, string>|null enum values of a field, labelled */
    public function enumOptions(?string $field): ?array
    {
        $values = $this->field($field)['enum_values'] ?? null;
        if (!$values) {
            return null;
        }
        $labels = self::ENUM_LABELS[$field] ?? TaxonomyCatalog::labelMap($field);

        return collect($values)->mapWithKeys(fn ($v) => [$v => $labels[$v] ?? $v])->all();
    }

    public function dtype(?string $field): string
    {
        return $this->field($field)['dtype'] ?? 'string';
    }

    public static function needsValue(?string $operator): bool
    {
        return !in_array($operator, self::NO_VALUE, true);
    }

    public static function isList(?string $operator): bool
    {
        return in_array($operator, ['in', 'not_in'], true);
    }

    /** "Не сохранять, если пробег > 200 000 км" */
    public function sentence(ParseFilter $rule): string
    {
        return (self::ACTIONS[$rule->action] ?? $rule->action) . ', если ' . $this->condition($rule->field, $rule->operator, $rule->decodedValue());
    }

    public function condition(string $field, string $operator, mixed $value): string
    {
        $label = mb_strtolower(AdminUiLabels::field($field));
        if (!self::needsValue($operator)) {
            return "{$label} " . self::OPERATORS[$operator];
        }
        if ($operator === 'between' && is_array($value) && count($value) === 2) {
            return "{$label} от " . $this->formatValue($field, $value[0]) . ' до ' . $this->formatValue($field, $value[1]);
        }
        if (is_array($value)) {
            $shown = implode(', ', array_map(fn ($v) => $this->formatValue($field, $v), $value));
            return "{$label} " . (self::OPERATORS[$operator] ?? $operator) . ": {$shown}";
        }
        $op = self::SYMBOLS[$operator] ?? (self::OPERATORS[$operator] ?? $operator);

        return "{$label} {$op} " . $this->formatValue($field, $value);
    }

    public function formatValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        if (in_array($field, ['price', 'retail_value', 'repair_cost', 'accident_max_cost', 'total_accident_cost'], true) && is_numeric($value)) {
            return LotFormat::won($value);
        }
        if ($field === 'mileage' && is_numeric($value)) {
            return LotFormat::km($value);
        }
        if (is_numeric($value) && $this->dtype($field) === 'int' && !in_array($field, ['year', 'registration_year_month'], true)) {
            return LotFormat::number((int) $value);
        }
        $enum = $this->enumOptions($field);

        return $enum[$value] ?? (string) $value;
    }

    /** Stored form of a value: JSON text, as the parser reads it. */
    public static function encodeValue(string $operator, mixed $value): ?string
    {
        if (!self::needsValue($operator)) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** Typed value from form input: numbers for numeric fields, lists for in/between. */
    public function castValue(string $field, string $operator, mixed $value): mixed
    {
        $dtype = $this->dtype($field);
        $cast = function ($v) use ($dtype) {
            if ($v === null || $v === '') {
                return null;
            }
            return match ($dtype) {
                'int'   => (int) $v,
                'float' => (float) $v,
                'bool'  => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                default => (string) $v,
            };
        };
        if ($operator === 'between') {
            return [$cast($value['from'] ?? null), $cast($value['to'] ?? null)];
        }
        if (self::isList($operator)) {
            return array_values(array_map($cast, (array) $value));
        }

        return $cast($value);
    }

    /** "mileage_gt_200000", unique in parse_filters. */
    public static function autoName(string $field, string $operator, mixed $value, ?int $ignoreId = null): string
    {
        $tail = is_array($value) ? implode('_', array_slice($value, 0, 3)) : (string) $value;
        $base = Str::of("{$field}_{$operator}_{$tail}")->ascii()->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(56, '')->toString();
        $name = $base;
        for ($i = 2; ParseFilter::where('name', $name)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $name = "{$base}_{$i}";
        }

        return $name;
    }

    /**
     * Active lots of a source a condition matches now, or null when the field is
     * not a lots column (inspection-only fields). A full scan of lots, so it runs
     * only when the person editing the rule asks for it.
     */
    public function previewCount(string $field, string $operator, mixed $value, ?string $source = null): ?int
    {
        $column = $this->field($field)['column'] ?? $field;
        if (!in_array($column, $this->lotColumns(), true)) {
            return null;
        }
        $q = DB::table('lots')->where('is_active', true)->when($source, fn ($q) => $q->where('source', $source));
        $col = "`{$column}`";

        match ($operator) {
            'eq'           => $value === null ? $q->whereNull($column) : $q->where($column, $value),
            'ne'           => $q->where(fn ($w) => $w->where($column, '!=', $value)->orWhereNull($column)),
            'gt'           => $q->where($column, '>', $value),
            'gte'          => $q->where($column, '>=', $value),
            'lt'           => $q->where($column, '<', $value),
            'lte'          => $q->where($column, '<=', $value),
            'between'      => $q->whereBetween($column, array_values((array) $value)),
            'in'           => $q->whereIn($column, (array) $value),
            'not_in'       => $q->where(fn ($w) => $w->whereNotIn($column, (array) $value)->orWhereNull($column)),
            'is_null'      => $q->whereNull($column),
            'is_not_null'  => $q->whereNotNull($column),
            'contains'     => $q->where($column, 'like', '%' . addcslashes((string) $value, '%_\\') . '%'),
            'not_contains' => $q->where(fn ($w) => $w->where($column, 'not like', '%' . addcslashes((string) $value, '%_\\') . '%')->orWhereNull($column)),
            'regex'        => $q->whereRaw("{$col} REGEXP ?", [(string) $value]),
            default        => null,
        };

        return $q->count();
    }

    /**
     * Hits per rule name from filter_skip_log (skip and mark_inactive only; the
     * parser leaves rule_id empty, so rules are matched by name).
     *
     * @return array<string, array{lots: int, hits: int, last: ?string}>
     */
    public static function hits(?string $source = null): array
    {
        return DB::table('filter_skip_log')
            ->when($source, fn ($q) => $q->where('source', $source))
            ->groupBy('rule_name')
            ->selectRaw('rule_name, COUNT(*) AS lots, SUM(skip_count) AS hits, MAX(last_skipped_at) AS last')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->rule_name => ['lots' => (int) $r->lots, 'hits' => (int) $r->hits, 'last' => $r->last]])
            ->all();
    }

    /** @return list<string> */
    private function lotColumns(): array
    {
        return once(fn () => Schema::getColumnListing('lots'));
    }

    private static function categoryLabel(string $category): string
    {
        return [
            'identity'  => 'Модель и учёт',
            'price'     => 'Цена',
            'condition' => 'Состояние',
            'specs'     => 'Характеристики',
            'dealer'    => 'Продавец',
            'legal'     => 'Юридическое',
            'sales'     => 'Продажа',
        ][$category] ?? Str::ucfirst($category);
    }
}
