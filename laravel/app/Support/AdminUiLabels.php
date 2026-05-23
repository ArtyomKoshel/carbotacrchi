<?php

namespace App\Support;

class AdminUiLabels
{
    private const SOURCE_LABELS = [
        'encar' => 'Encar',
        'kbcha' => 'Второй источник',
    ];

    private const EVENT_LABELS = [
        'update' => 'Обновление',
        'delisted' => 'Снят с продажи',
        'relisted' => 'Возвращён в продажу',
        'deactivated_filter' => 'Деактивирован фильтром',
    ];

    private const STATUS_LABELS = [
        'pending' => 'В очереди',
        'running' => 'Выполняется',
        'done' => 'Готово',
        'error' => 'Ошибка',
        'cancelled' => 'Отменено',
        'interrupted' => 'Прервано',
    ];

    private const PHASE_LABELS = [
        'prepare' => 'Подготовка',
        'fetch' => 'Загрузка',
        'parse' => 'Парсинг',
        'save' => 'Сохранение',
        'finalize' => 'Завершение',
        'cleanup' => 'Очистка',
        'pre' => 'До осмотра',
        'post' => 'После осмотра',
    ];

    private const FIELD_LABELS = [
        'id' => 'Внутренний ID',
        'source' => 'Источник',
        'lot_id' => 'ID лота',
        'lot_url' => 'Ссылка на лот',
        'location' => 'Местоположение',
        'make' => 'Марка',
        'model' => 'Модель',
        'trim' => 'Комплектация',
        'year' => 'Год',
        'registration_date' => 'Дата первой регистрации',
        'registration_year_month' => 'Год и месяц регистрации',
        'price' => 'Цена',
        'retail_value' => 'Розничная стоимость',
        'repair_cost' => 'Стоимость ремонта',
        'mileage' => 'Пробег',
        'fuel' => 'Топливо',
        'transmission' => 'Коробка передач',
        'engine_volume' => 'Объём двигателя',
        'drive_type' => 'Привод',
        'body_type' => 'Тип кузова',
        'seat_count' => 'Количество мест',
        'sell_type' => 'Тип продажи',
        'insurance_count' => 'Страховых случаев',
        'has_accident' => 'Были ДТП',
        'owners_count' => 'Кол-во владельцев',
        'flood_history' => 'История затопления',
        'total_loss_history' => 'Полная гибель (история)',
        'lien_status' => 'Статус залога',
        'seizure_status' => 'Статус ареста',
        'is_active' => 'Активность лота',
        'plate_number' => 'Гос. номер',
        'vin' => 'VIN',
        'color' => 'Цвет',
        'engine_type' => 'Тип двигателя',
        'inspection_record' => 'Отчёт осмотра',
        'photos' => 'Фото',
        'seller_type' => 'Тип продавца',
        'region' => 'Регион',
        'city' => 'Город',
        'status' => 'Статус',
        'created_at' => 'Создано',
        'updated_at' => 'Обновлено',
    ];

    public static function source(?string $source): string
    {
        if (!$source) {
            return 'Все источники';
        }

        return self::SOURCE_LABELS[$source] ?? mb_strtoupper($source);
    }

    public static function event(?string $event): string
    {
        if (!$event) {
            return '—';
        }

        if (str_starts_with($event, 'deactivated_filter')) {
            return 'Деактивирован фильтром';
        }

        return self::EVENT_LABELS[$event] ?? self::humanize($event);
    }

    public static function status(?string $status): string
    {
        if (!$status) {
            return '—';
        }

        return self::STATUS_LABELS[$status] ?? self::humanize($status);
    }

    public static function phase(?string $phase): string
    {
        if (!$phase) {
            return '—';
        }

        return self::PHASE_LABELS[$phase] ?? self::humanize($phase);
    }

    public static function field(?string $field): string
    {
        if (!$field) {
            return '—';
        }

        return self::FIELD_LABELS[$field] ?? self::humanize($field);
    }

    public static function boolValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        return (string) $value;
    }

    private static function humanize(string $value): string
    {
        $normalized = str_replace(['_', '-'], ' ', trim($value));
        return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
    }
}
