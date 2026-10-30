<?php

namespace App\Filament\Resources\Lots;

use App\Models\Lot;
use App\Support\Currency;
use App\Support\Taxonomy\TaxonomyLocalizer;
use Illuminate\Support\Facades\DB;

/** Display formats for lots in the panel. Prices are integers in the lot's currency (lots.currency). */
class LotFormat
{
    private const NBSP = "\u{00A0}";

    public const UNPLACED = 'не разобрано';

    /** @var array<string, array<int, object>> ref_* rows by id, one query per table per request */
    private static array $ref = [];

    /** Make and model from the reference catalog; Encar's own Korean names for a lot not on it. */
    public static function title(Lot $lot): string
    {
        $make = self::ref('ref_makes', $lot->ref_make_id)?->name ?? $lot->make;
        $model = self::ref('ref_models', $lot->ref_model_id)?->name ?? $lot->model;
        $generation = self::ref('ref_generations', $lot->ref_generation_id)?->code;

        return trim("{$make} {$model} {$generation}") ?: $lot->id;
    }

    /** Modification and complectation of the catalog (or "не разобрано") and the lot id. */
    public static function subtitle(Lot $lot): string
    {
        $place = $lot->ref_model_id
            ? [self::ref('ref_modifications', $lot->ref_modification_id)?->code, self::ref('ref_complectations', $lot->ref_complectation_id)?->name]
            : [self::UNPLACED];

        return implode(' · ', array_filter([...$place, $lot->id]));
    }

    /**
     * The lot's place in the catalog, level by level (null where Encar did not state it).
     *
     * @return array{make: ?string, model: ?string, generation: ?string, modification: ?string, complectation: ?string}
     */
    public static function place(Lot $lot): array
    {
        $generation = self::ref('ref_generations', $lot->ref_generation_id);
        $modification = self::ref('ref_modifications', $lot->ref_modification_id);

        return [
            'make' => self::ref('ref_makes', $lot->ref_make_id)?->name,
            'model' => self::ref('ref_models', $lot->ref_model_id)?->name,
            'generation' => $generation ? trim($generation->code . ' · ' . self::years($generation->year_from, $generation->year_to), ' ·') : null,
            'modification' => $modification ? implode(', ', array_filter([
                $modification->code,
                $modification->fuel ? TaxonomyLocalizer::label('fuel', $modification->fuel) : null,
                $modification->drive ? TaxonomyLocalizer::label('drive_type', $modification->drive) : null,
            ])) : null,
            'complectation' => self::ref('ref_complectations', $lot->ref_complectation_id)?->name,
        ];
    }

    /** How the catalog read the lot's variant: placed, no engine, unplaced, or placed by hand. */
    public static function placeStatus(Lot $lot): string
    {
        $variant = $lot->ref_variant_id
            ? DB::table('ref_variants')->where('id', $lot->ref_variant_id)->first(['status', 'manual', 'reason'])
            : null;

        return match (true) {
            $variant === null || $lot->ref_model_id === null => self::UNPLACED . ($variant?->reason ? " ({$variant->reason})" : ''),
            (bool) $variant->manual => 'размечен вручную',
            $variant->status === 'no_engine' => 'разобран, двигатель не указан',
            $variant->status === 'unmatched' => 'частично: ' . ($variant->reason ?: 'есть непонятые слова'),
            default => 'разобран',
        };
    }

    public static function years(?int $from, ?int $to): string
    {
        return $from ? $from . '–' . ($to ?? '') : '';
    }

    public static function won(mixed $value): string
    {
        return self::money($value, Currency::DEFAULT);
    }

    /** An amount in the lot's currency (lots.currency); null = KRW. */
    public static function money(mixed $value, ?string $currency): string
    {
        return is_numeric($value) ? Currency::symbol($currency) . self::NBSP . self::number((int) $value) : '—';
    }

    public static function km(mixed $value): string
    {
        return is_numeric($value) ? self::number((int) $value) . self::NBSP . 'км' : '—';
    }

    public static function liters(mixed $value): string
    {
        return is_numeric($value) && $value > 0 ? number_format((float) $value, 1, ',', '') . self::NBSP . 'л' : '—';
    }

    public static function yesNo(mixed $value): string
    {
        return $value === null ? '—' : ((bool) $value ? 'да' : 'нет');
    }

    public static function taxonomy(string $field, ?string $value): string
    {
        return filled($value) ? TaxonomyLocalizer::label($field, $value) : '—';
    }

    public static function number(int $value): string
    {
        return number_format($value, 0, ',', self::NBSP);
    }

    /** A value from lot_changes.changes, as the History tab shows it. */
    public static function changeValue(string $field, mixed $v, ?string $source = null): string
    {
        return match (true) {
            $v === null, $v === ''                                         => '—',
            in_array($field, ['price', 'retail_value', 'repair_cost'], true) => self::money($v, Currency::of($source)),
            $field === 'mileage'                                           => self::km($v),
            $field === 'is_active'                                         => $v ? 'активен' : 'снят',
            is_bool($v)                                                    => $v ? 'да' : 'нет',
            is_scalar($v)                                                  => (string) $v,
            default                                                        => json_encode($v, JSON_UNESCAPED_UNICODE),
        };
    }

    private static function ref(string $table, mixed $id): ?object
    {
        if (!$id) {
            return null;
        }
        self::$ref[$table] ??= DB::table($table)->get()->keyBy('id')->all();

        return self::$ref[$table][(int) $id] ?? null;
    }
}
