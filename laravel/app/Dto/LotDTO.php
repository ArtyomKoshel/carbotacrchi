<?php

namespace App\Dto;

readonly class LotDTO
{
    public function __construct(
        public string  $id,
        public string  $source,
        public string  $sourceName,
        public string  $make,
        public string  $model,
        public int     $year,
        public int     $price,
        public int     $mileage,
        public ?string $damage,
        public string  $title,
        public string  $location,
        public string  $lotUrl,
        public ?string $imageUrl,
        public ?string $vin,
        public ?string $auctionDate,
        public string  $createdAt,
        public ?string $transmission    = null,
        public ?string $fuel            = null,
        public ?string $bodyType        = null,
        public ?string $driveType       = null,
        public ?string $color           = null,
        public ?float  $engineVolume    = null,
        public ?int    $cylinders       = null,
        public ?bool   $hasKeys         = null,
        public ?string $secondaryDamage = null,
        public ?string $document        = null,
        public ?int    $retailValue     = null,
        public ?int    $repairCost      = null,
        public ?string $trim            = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'source'      => $this->source,
            'sourceName'  => $this->sourceName,
            'make'        => $this->make,
            'model'       => $this->model,
            'year'        => $this->year,
            'price'       => $this->price,
            'mileage'     => $this->mileage,
            'damage'      => $this->damage,
            'title'       => $this->title,
            'location'    => $this->location,
            'lotUrl'      => $this->lotUrl,
            'imageUrl'    => $this->imageUrl,
            'vin'         => $this->vin,
            'auctionDate'     => $this->auctionDate,
            'createdAt'       => $this->createdAt,
            'transmission'    => $this->transmission,
            'fuel'            => $this->fuel,
            'bodyType'        => $this->bodyType,
            'driveType'       => $this->driveType,
            'color'           => $this->color,
            'engineVolume'    => $this->engineVolume,
            'cylinders'       => $this->cylinders,
            'hasKeys'         => $this->hasKeys,
            'secondaryDamage' => $this->secondaryDamage,
            'document'        => $this->document,
            'retailValue'     => $this->retailValue,
            'repairCost'      => $this->repairCost,
            'trim'            => $this->trim,
        ];
    }
}
