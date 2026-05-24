<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LABELS = [
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
        'lot_url' => 'Ссылка на лот',
        'location' => 'Местоположение',
    ];

    public function up(): void
    {
        foreach (self::LABELS as $fieldName => $label) {
            DB::table('bot_filter_settings')
                ->where('field_name', $fieldName)
                ->update(['field_label' => $label]);
        }
    }

    public function down(): void
    {
        // Revert to English humanized labels
        foreach (self::LABELS as $fieldName => $label) {
            $english = ucwords(str_replace('_', ' ', $fieldName));
            DB::table('bot_filter_settings')
                ->where('field_name', $fieldName)
                ->update(['field_label' => $english]);
        }
    }
};
