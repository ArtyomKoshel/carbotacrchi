<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Lot extends Model
{
    use Searchable;

    protected $table    = 'lots';
    public $incrementing = false;
    protected $keyType  = 'string';

    protected $casts = [
        'options'    => 'array',
        'raw_data'   => 'array',
        'is_active'  => 'boolean',
        'parsed_at'  => 'datetime',
        'fetched_at' => 'datetime',
    ];

    public function searchableAs(): string
    {
        return 'lots';
    }

    public function toSearchableArray(): array
    {
        return [
            'id'           => $this->id,
            'source'       => $this->source,
            'make'         => $this->make,
            'model'        => $this->model,
            'model_en'     => $this->model_en,
            'generation'   => $this->generation,
            'trim'         => $this->trim,
            'badge'        => $this->badge,
            'year'         => (int) $this->year,
            'price'        => (int) $this->price,
            'mileage'      => (int) $this->mileage,
            'transmission' => $this->transmission,
            'fuel'         => $this->fuel,
            'body_type'    => $this->body_type,
            'drive_type'   => $this->drive_type,
            'color'        => $this->color,
            'has_accident' => (bool) $this->has_accident,
            'flood_history'=> (bool) $this->flood_history,
            'is_active'    => (bool) $this->is_active,
            'location'     => $this->location,
            'vin'          => $this->vin,
            'listed_at'    => $this->listed_at,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return (bool) $this->is_active;
    }
}
