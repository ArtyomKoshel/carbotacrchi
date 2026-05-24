<?php

namespace App\Support\Taxonomy;

final class TaxonomyCatalog
{
    /**
     * Raw source value -> canonical value.
     * Canonical value is English enum/name where possible.
     *
     * @var array<string, array<string, string>>
     */
    private const NORMALIZATION_MAPS = [
        'make' => [
            '현대' => 'Hyundai',
            '기아' => 'Kia',
            '제네시스' => 'Genesis',
            '쉐보레(GM대우)' => 'Chevrolet',
            '쉐보레' => 'Chevrolet',
            'GM대우' => 'Chevrolet',
            '한국GM' => 'Chevrolet',
            '르노코리아(삼성)' => 'Renault Korea',
            '르노코리아' => 'Renault Korea',
            '르노삼성' => 'Renault Samsung',
            '쌍용' => 'SsangYong',
            'KG모빌리티' => 'KG Mobility',
            '벤츠' => 'Mercedes-Benz',
            '메르세데스-벤츠' => 'Mercedes-Benz',
            '아우디' => 'Audi',
            '폭스바겐' => 'Volkswagen',
            '볼보' => 'Volvo',
            '랜드로버' => 'Land Rover',
            '재규어' => 'Jaguar',
            '포르쉐' => 'Porsche',
            '렉서스' => 'Lexus',
            '토요타' => 'Toyota',
            '도요타' => 'Toyota',
            '혼다' => 'Honda',
            '닛산' => 'Nissan',
            '인피니티' => 'Infiniti',
            '마쯔다' => 'Mazda',
            '마쓰다' => 'Mazda',
            '미쯔비시' => 'Mitsubishi',
            '미쓰비시' => 'Mitsubishi',
            '스바루' => 'Subaru',
            '테슬라' => 'Tesla',
            '포드' => 'Ford',
            '지프' => 'Jeep',
            '닷지' => 'Dodge',
            '링컨' => 'Lincoln',
            '푸조' => 'Peugeot',
            '대우' => 'Daewoo',
            '미니' => 'MINI',
            '페라리' => 'Ferrari',
            '람보르기니' => 'Lamborghini',
            '마세라티' => 'Maserati',
            '벤틀리' => 'Bentley',
            '롤스로이스' => 'Rolls-Royce',

            'hyundai' => 'Hyundai',
            'kia' => 'Kia',
            'genesis' => 'Genesis',
            'chevrolet' => 'Chevrolet',
            'renault korea' => 'Renault Korea',
            'renault samsung' => 'Renault Samsung',
            'ssangyong' => 'SsangYong',
            'kg mobility' => 'KG Mobility',
            'bmw' => 'BMW',
            'mercedes-benz' => 'Mercedes-Benz',
            'audi' => 'Audi',
            'volkswagen' => 'Volkswagen',
            'volvo' => 'Volvo',
            'land rover' => 'Land Rover',
            'jaguar' => 'Jaguar',
            'porsche' => 'Porsche',
            'lexus' => 'Lexus',
            'toyota' => 'Toyota',
            'honda' => 'Honda',
            'nissan' => 'Nissan',
            'infiniti' => 'Infiniti',
            'mazda' => 'Mazda',
            'mitsubishi' => 'Mitsubishi',
            'subaru' => 'Subaru',
            'tesla' => 'Tesla',
            'ford' => 'Ford',
            'jeep' => 'Jeep',
            'dodge' => 'Dodge',
            'lincoln' => 'Lincoln',
            'peugeot' => 'Peugeot',
            'daewoo' => 'Daewoo',
            'mini' => 'MINI',
            'ferrari' => 'Ferrari',
            'lamborghini' => 'Lamborghini',
            'maserati' => 'Maserati',
            'bentley' => 'Bentley',
            'rolls-royce' => 'Rolls-Royce',
        ],

        'body_type' => [
            '세단' => 'sedan',
            '대형차' => 'sedan',
            '중형차' => 'sedan',
            '준중형차' => 'sedan',
            '소형차' => 'sedan',
            '대형' => 'sedan',
            '준대형' => 'sedan',
            '중형' => 'sedan',
            '소형' => 'sedan',
            '준중형' => 'sedan',
            'sedan' => 'sedan',

            'RV' => 'suv',
            'SUV' => 'suv',
            'suv' => 'suv',

            '해치백' => 'hatchback',
            'hatchback' => 'hatchback',

            '쿠페' => 'coupe',
            '스포츠카' => 'coupe',
            'coupe' => 'coupe',

            '컨버터블' => 'convertible',
            'convertible' => 'convertible',

            '왜건' => 'wagon',
            'wagon' => 'wagon',

            '밴' => 'van',
            'van' => 'van',
            'MPV' => 'van',

            '승합' => 'minivan',

            '트럭' => 'truck',
            'truck' => 'truck',

            '카고' => 'cargo',

            '경차' => 'kei',

            '픽업트럭' => 'pickup',
            '픽업' => 'pickup',
        ],

        'transmission' => [
            '오토' => 'automatic',
            '자동' => 'automatic',
            'auto' => 'automatic',
            'automatic' => 'automatic',

            '수동' => 'manual',
            'manual' => 'manual',

            'CVT' => 'cvt',
            'cvt' => 'cvt',

            'DCT' => 'dct',
            'dct' => 'dct',
            '자동(DCT)' => 'dct',
        ],

        'fuel' => [
            '가솔린' => 'gasoline',
            'petrol' => 'gasoline',
            'gasoline' => 'gasoline',

            '디젤' => 'diesel',
            'diesel' => 'diesel',

            'LPG' => 'lpg',
            'lpg' => 'lpg',

            'CNG' => 'cng',
            'cng' => 'cng',

            '전기' => 'electric',
            'electric' => 'electric',

            '하이브리드' => 'hybrid',
            '가솔린+전기' => 'hybrid',
            '디젤+전기' => 'hybrid',
            'LPG+전기' => 'hybrid',
            '가솔린 하이브리드' => 'hybrid',
            '디젤 하이브리드' => 'hybrid',
            'hybrid' => 'hybrid',
            'HEV' => 'hybrid',

            '플러그인 하이브리드' => 'plugin_hybrid',
            '가솔린+전기(플러그인)' => 'plugin_hybrid',
            'PHEV' => 'plugin_hybrid',
            'plugin_hybrid' => 'plugin_hybrid',

            '수소' => 'hydrogen',
            '수소전기' => 'hydrogen',
            '수소전기차' => 'hydrogen',
            '연료전지' => 'hydrogen',
            'FCEV' => 'hydrogen',
            'hydrogen' => 'hydrogen',
        ],

        'drive_type' => [
            '전륜' => 'fwd',
            '전륜구동' => 'fwd',
            'FF' => 'fwd',
            'FWD' => 'fwd',
            'fwd' => 'fwd',
            '2WD' => 'fwd',

            '후륜' => 'rwd',
            '후륜구동' => 'rwd',
            'FR' => 'rwd',
            'RWD' => 'rwd',
            'rwd' => 'rwd',
            'sDrive' => 'rwd',

            'AWD' => 'awd',
            'awd' => 'awd',
            '4WD' => 'awd',
            '4wd' => 'awd',
            '4륜' => 'awd',
            '4륜구동' => 'awd',
            '사륜' => 'awd',
            '사륜구동' => 'awd',
            '상시사륜' => 'awd',
            '풀타임사륜' => 'awd',
            '파트타임사륜' => 'awd',
            '4Matic' => 'awd',
            '4Matic+' => 'awd',
            'xDrive' => 'awd',
        ],
    ];

    /**
     * value(lowercase) -> label by locale and field.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const LABELS = [
        'ru' => [
            'body_type' => [
                'sedan' => 'Седан',
                'suv' => 'SUV',
                'hatchback' => 'Хэтчбек',
                'coupe' => 'Купе',
                'convertible' => 'Кабриолет',
                'wagon' => 'Универсал',
                'van' => 'Фургон',
                'minivan' => 'Минивэн',
                'pickup' => 'Пикап',
                'truck' => 'Грузовой',
                'cargo' => 'Грузовой фургон',
                'kei' => 'Малолитражка',
            ],
            'transmission' => [
                'automatic' => 'Автомат',
                'manual' => 'Механика',
                'cvt' => 'Вариатор',
                'dct' => 'Робот (DCT)',
            ],
            'fuel' => [
                'gasoline' => 'Бензин',
                'diesel' => 'Дизель',
                'lpg' => 'LPG',
                'cng' => 'CNG',
                'electric' => 'Электро',
                'hybrid' => 'Гибрид',
                'plugin_hybrid' => 'Plug-in гибрид',
                'hydrogen' => 'Водород',
            ],
            'drive_type' => [
                'fwd' => 'Передний',
                'rwd' => 'Задний',
                'awd' => 'Полный',
                '4wd' => '4WD',
                '2wd' => '2WD',
            ],
        ],

        'en' => [
            'body_type' => [
                'sedan' => 'Sedan',
                'suv' => 'SUV',
                'hatchback' => 'Hatchback',
                'coupe' => 'Coupe',
                'convertible' => 'Convertible',
                'wagon' => 'Wagon',
                'van' => 'Van',
                'minivan' => 'Minivan',
                'pickup' => 'Pickup',
                'truck' => 'Truck',
                'cargo' => 'Cargo Van',
                'kei' => 'Kei Car',
            ],
            'transmission' => [
                'automatic' => 'Automatic',
                'manual' => 'Manual',
                'cvt' => 'CVT',
                'dct' => 'DCT',
            ],
            'fuel' => [
                'gasoline' => 'Gasoline',
                'diesel' => 'Diesel',
                'lpg' => 'LPG',
                'cng' => 'CNG',
                'electric' => 'Electric',
                'hybrid' => 'Hybrid',
                'plugin_hybrid' => 'Plug-in Hybrid',
                'hydrogen' => 'Hydrogen',
            ],
            'drive_type' => [
                'fwd' => 'FWD',
                'rwd' => 'RWD',
                'awd' => 'AWD',
                '4wd' => '4WD',
                '2wd' => '2WD',
            ],
        ],
    ];

    /** @return array<string, string> */
    public static function normalizationMap(string $field): array
    {
        return self::NORMALIZATION_MAPS[$field] ?? [];
    }

    /** @return array<string, array<string, string>> */
    public static function labelMaps(string $locale = 'ru'): array
    {
        $locale = mb_strtolower(trim($locale), 'UTF-8');
        return self::LABELS[$locale] ?? [];
    }

    /** @return array<string, string> */
    public static function labelMap(string $field, string $locale = 'ru'): array
    {
        $all = self::labelMaps($locale);
        return $all[$field] ?? [];
    }
}
