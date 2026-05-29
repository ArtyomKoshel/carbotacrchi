<?php

namespace App\Services\Catalog;

/**
 * Result of GradeExtractorService::extract().
 * All fields are null if not found in the raw string.
 */
class GradeExtractionResult
{
    public ?string $model        = null;  // canonical model_kr from catalog_models
    public ?string $generation   = null;  // chassis/gen code, e.g. "G60", "LF", "DN8"
    public ?string $trim         = null;  // trim_kr, e.g. "M 스포츠", "프레스티지"
    public ?string $trimEn       = null;  // trim_en for display, e.g. "M Sport"
    public ?string $fuel         = null;  // canonical: gasoline|diesel|hybrid|electric|lpg|…
    public ?string $driveType    = null;  // canonical: fwd|rwd|awd|4wd
    public ?string $bodyType     = null;  // canonical: sedan|suv|hatchback|…
    public ?float  $engineVolume = null;  // litres, e.g. 2.0
    public ?int    $cylinders    = null;  // e.g. 6, 8
    public ?int    $seatCount    = null;  // e.g. 5, 7
    public string  $remainder    = '';    // unmatched text → TaxonomyAnomalyQueue
}
