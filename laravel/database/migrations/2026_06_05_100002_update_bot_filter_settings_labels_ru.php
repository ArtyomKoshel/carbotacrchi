<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $labels = [
            'source'                  => 'Источники',
            'make'                    => 'Марка / Модель / Комплектация',
            'model'                   => 'Модель',
            'badge'                   => 'Badge',
            'model_group'             => 'Группа моделей',
            'trim'                    => 'Комплектация',
            'generation'              => 'Поколение',
            'year'                    => 'Год выпуска',
            'first_reg_date'          => 'Первая регистрация',
            'listed_at'               => 'Дата публикации',
            'registration_date'       => 'Дата регистрации',
            'registration_year_month' => 'Год/месяц регистрации',
            'price'                   => 'Цена (₩)',
            'mileage'                 => 'Пробег (км)',
            'engine_volume'           => 'Объём двигателя (л)',
            'seat_count'              => 'Мест в салоне',
            'fuel'                    => 'Тип топлива',
            'transmission'            => 'Коробка передач',
            'body_type'               => 'Тип кузова',
            'drive_type'              => 'Привод',
            'color'                   => 'Цвет',
            'seat_color'              => 'Цвет салона',
            'has_accident'            => 'История ДТП',
            'flood_history'           => 'Подтопление',
            'total_loss_history'      => 'Тотальная потеря',
            'insurance_count'         => 'Страховых случаев',
            'owners_count'            => 'Владельцев',
            'lien_status'             => 'Залог',
            'seizure_status'          => 'Арест',
            'repair_cost'             => 'Стоимость ремонта',
            'retail_value'            => 'Розничная стоимость',
            'options'                 => 'Опции / Комплектация',
            'sell_type'               => 'Тип продажи',
            'location'                => 'Регион',
            'vin'                     => 'VIN',
            'plate_number'            => 'Номерной знак',
            'lot_url'                 => 'Ссылка на лот',
            'paid_options'            => 'Платные опции',
            'inspection_count'        => 'Количество инспекций',
            'accident_max_cost'       => 'Макс. стоимость ДТП',
            'total_accident_cost'     => 'Общая стоимость ДТП',
            'inspection_has_accident' => 'Инспекция: ДТП',
            'inspection_has_flood'    => 'Инспекция: подтопление',
            'is_domestic'             => 'Местный рынок',
            'import_type'             => 'Тип импорта',
            'title'                   => 'Заголовок',
        ];

        foreach ($labels as $field => $label) {
            DB::table('bot_filter_settings')
                ->where('field_name', $field)
                ->update(['field_label' => $label]);
        }
    }

    public function down(): void
    {
        // Labels before this migration were English — no rollback needed
    }
};
