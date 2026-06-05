<?php

namespace Database\Seeders;

use App\Models\BotFilterSetting;
use Illuminate\Database\Seeder;

class BotFilterSettingsSeeder extends Seeder
{
    private const DEFAULTS = [
        //                                               tolerance_type   value   display_in_card
        'source'                  => ['enabled' => false, 'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'make'                    => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'model'                   => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        // year: ±1 год только при точном совпадении (2020 → 2019–2021), при "от"/"до" только расширяем одну границу
        'year'                    => ['enabled' => true,  'tolerance_type' => 'absolute',   'tolerance_value' => 1,    'display_in_card' => true],
        // price: +10% при "до X" (найти чуть дороже), -10% при "от X" (найти чуть дешевле)
        'price'                   => ['enabled' => true,  'tolerance_type' => 'percentage', 'tolerance_value' => 0.10, 'display_in_card' => true],
        // mileage: +10% при "до X" (100 000 → до 110 000), -10% при "от X"
        'mileage'                 => ['enabled' => true,  'tolerance_type' => 'percentage', 'tolerance_value' => 0.10, 'display_in_card' => true],
        // engine_volume: точное совпадение — 2.0 ≠ 1.8 (разные двигатели)
        'engine_volume'           => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'fuel'                    => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'transmission'            => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'body_type'               => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'drive_type'              => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'color'                   => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'has_accident'            => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'flood_history'           => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'total_loss_history'      => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        // insurance_count: точное требование — "не более 2" значит не более 2, без погрешности
        'insurance_count'         => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        // owners_count: точное требование — "1 владелец" = 1, погрешность ±1 давала 0–2 что неприемлемо
        'owners_count'            => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'repair_cost'             => ['enabled' => false, 'tolerance_type' => 'percentage', 'tolerance_value' => 0.20, 'display_in_card' => false],
        'retail_value'            => ['enabled' => false, 'tolerance_type' => 'percentage', 'tolerance_value' => 0.15, 'display_in_card' => false],
        'lien_status'             => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'seizure_status'          => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'sell_type'               => ['enabled' => false, 'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'seat_count'              => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
        'trim'                    => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'generation'              => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'registration_year_month' => ['enabled' => false, 'tolerance_type' => 'absolute',   'tolerance_value' => 6,    'display_in_card' => false],
        'first_reg_date'          => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => true],
        'listed_at'               => ['enabled' => true,  'tolerance_type' => 'none',       'tolerance_value' => null, 'display_in_card' => false],
    ];

    public function run(): void
    {
        $path = storage_path('app/fields.json');
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $fields = is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];

        $existing = BotFilterSetting::pluck('id', 'field_name')->all();
        $isEmpty = empty($existing);

        $sort = 0;
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['name']) || empty($field['filterable'])) {
                continue;
            }

            $name = (string) $field['name'];
            $defaults = self::DEFAULTS[$name] ?? [
                'enabled' => false,
                'tolerance_type' => 'none',
                'tolerance_value' => null,
                'display_in_card' => false,
            ];

            $payload = [
                'field_name' => $name,
                'field_label' => $this->humanize($name),
                'dtype' => (string) ($field['dtype'] ?? 'string'),
                'category' => (string) ($field['category'] ?? ''),
                'enabled' => (bool) $defaults['enabled'],
                'tolerance_type' => (string) $defaults['tolerance_type'],
                'tolerance_value' => $defaults['tolerance_value'] !== null ? (float) $defaults['tolerance_value'] : null,
                'display_in_card' => (bool) $defaults['display_in_card'],
                'enum_values' => is_array($field['enum_values'] ?? null) ? array_values($field['enum_values']) : null,
                'description' => (string) ($field['description'] ?? ''),
                'sort_order' => $sort,
            ];

            if ($isEmpty) {
                BotFilterSetting::create($payload);
            } elseif (!isset($existing[$name])) {
                BotFilterSetting::create($payload);
            }

            $sort++;
        }

        $this->ensureVirtualField(
            existing: $existing,
            isEmpty: $isEmpty,
            sort: $sort,
            name: 'lot_url',
            label: 'Lot Url',
            dtype: 'string',
            category: 'links',
            description: 'Ссылка на лот',
            defaults: [
                'enabled' => false,
                'tolerance_type' => 'none',
                'tolerance_value' => null,
                'display_in_card' => true,
            ],
        );

        $this->ensureVirtualField(
            existing: $existing,
            isEmpty: $isEmpty,
            sort: $sort + 1,
            name: 'location',
            label: 'Location',
            dtype: 'string',
            category: 'identification',
            description: 'Местоположение лота',
            defaults: [
                'enabled' => false,
                'tolerance_type' => 'none',
                'tolerance_value' => null,
                'display_in_card' => false,
            ],
        );

    }

    /** @param array<string, int> $existing */
    private function ensureVirtualField(
        array $existing,
        bool $isEmpty,
        int $sort,
        string $name,
        string $label,
        string $dtype,
        string $category,
        string $description,
        array $defaults
    ): void {
        if (!$isEmpty && isset($existing[$name])) {
            return;
        }

        BotFilterSetting::create([
            'field_name' => $name,
            'field_label' => $label,
            'dtype' => $dtype,
            'category' => $category,
            'enabled' => (bool) ($defaults['enabled'] ?? false),
            'tolerance_type' => (string) ($defaults['tolerance_type'] ?? 'none'),
            'tolerance_value' => $defaults['tolerance_value'] ?? null,
            'display_in_card' => (bool) ($defaults['display_in_card'] ?? false),
            'enum_values' => null,
            'description' => $description,
            'sort_order' => $sort,
        ]);
    }

    private const FIELD_LABELS_RU = [
        'source' => 'Источники',
        'make' => 'Марка / Модель / Комплектация',
        'model' => 'Модель',
        'trim' => 'Комплектация',
        'generation' => 'Поколение / код',
        'year' => 'Год выпуска',
        'price' => 'Цена (₩)',
        'mileage' => 'Пробег (km)',
        'engine_volume' => 'Объём двигателя (л)',
        'body_type' => 'Тип кузова',
        'transmission' => 'Трансмиссия',
        'fuel' => 'Топливо',
        'drive_type' => 'Привод',
        'color' => 'Цвет',
        'has_accident' => 'Состояние (ДТП)',
        'flood_history' => 'Затопление',
        'total_loss_history' => 'Полная гибель',
        'insurance_count' => 'Страховых случаев',
        'owners_count' => 'Владельцев',
        'repair_cost' => 'Стоимость ремонта',
        'retail_value' => 'Рыночная стоимость',
        'lien_status' => 'Залог',
        'seizure_status' => 'Арест',
        'sell_type' => 'Тип продажи',
        'seat_count' => 'Количество мест',
        'registration_year_month' => 'Дата регистрации',
        'first_reg_date' => 'Дата первой регистрации',
        'listed_at' => 'Дата публикации объявления',
        'lot_url' => 'Ссылка на лот',
        'location' => 'Местоположение',
    ];

    private function humanize(string $name): string
    {
        return self::FIELD_LABELS_RU[$name] ?? ucwords(str_replace('_', ' ', $name));
    }
}
