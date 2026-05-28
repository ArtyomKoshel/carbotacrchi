<?php

namespace App\Services\Catalog;

final class CatalogLookupResult
{
    public function __construct(
        public readonly string  $makeKr,
        public readonly string  $makeEn,
        public readonly string  $modelKr,
        /** Full grade string as stored in catalog_grades (e.g. "VX 2WD", "2.2 디젤 2WD 프레스티지") */
        public readonly string  $gradeKr,
        /** Grade with spec tokens stripped — use this for lots.trim (e.g. "VX", "프레스티지") */
        public readonly ?string $trimKr,
        public readonly ?string $generation,
        public readonly ?string $fuelType,
        public readonly ?string $driveType,
        public readonly ?float  $engineVolume,
        public readonly ?int    $seatCount,
        public readonly ?int    $cylinders,
        public readonly ?string $bodyHint,
    ) {}
}
