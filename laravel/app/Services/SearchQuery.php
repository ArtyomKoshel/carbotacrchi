<?php

namespace App\Services;

class SearchQuery
{
    public string $make     = '';
    public string $model    = '';
    public int    $yearFrom = 0;
    public int    $yearTo   = 0;
    public int    $priceMax       = 0;
    public int    $priceMin       = 0;
    public int    $mileageMin     = 0;
    public int    $mileageMax     = 0;
    public float  $engineMin      = 0;
    public float  $engineMax      = 0;
    /** @var string[] */
    public array  $damageTypes    = [];
    /** @var string[] */
    public array  $titleTypes     = [];
    /** @var string[] */
    public array  $bodyTypes      = [];
    /** @var string[] */
    public array  $transmissions  = [];
    /** @var string[] */
    public array  $fuelTypes      = [];
    /** @var string[] */
    public array  $driveTypes     = [];
    public string $vin            = '';
    /** @var string[] */
    public array  $sources        = ['copart', 'iai', 'manheim', 'encar', 'kbcha'];
    public string $sort     = 'date';
    public int    $limit    = 20;
    public int    $offset   = 0;

    public static function fromArray(array $data): self
    {
        $q           = new self();
        $q->make     = trim((string) ($data['make']     ?? ''));
        $q->model    = trim((string) ($data['model']    ?? ''));
        $q->yearFrom = (int) ($data['yearFrom'] ?? 0);
        $q->yearTo   = (int) ($data['yearTo']   ?? 0);
        $q->priceMax   = (int)   ($data['priceMax']   ?? 0);
        $q->priceMin   = (int)   ($data['priceMin']   ?? 0);
        $q->mileageMin = (int)   ($data['mileageMin'] ?? 0);
        $q->mileageMax = (int)   ($data['mileageMax'] ?? 0);
        $q->engineMin  = (float) ($data['engineMin']  ?? 0);
        $q->engineMax  = (float) ($data['engineMax']  ?? 0);
        $q->vin        = trim((string) ($data['vin']  ?? ''));
        foreach (['damageTypes','titleTypes','bodyTypes','transmissions','fuelTypes','driveTypes'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $q->$key = array_map('strval', $data[$key]);
            }
        }
        $q->sort     = in_array($data['sort'] ?? '', ['date', 'price_asc', 'price_desc'], true)
                       ? $data['sort']
                       : 'date';
        $q->limit    = min((int) ($data['limit']  ?? 20), 100);
        $q->offset   = max((int) ($data['offset'] ?? 0), 0);

        if (!empty($data['sources']) && is_array($data['sources'])) {
            $q->sources = array_map('strval', $data['sources']);
        }

        return $q;
    }
}
