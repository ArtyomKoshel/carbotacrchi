<?php

declare(strict_types=1);

namespace Dto;

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
            'auctionDate' => $this->auctionDate,
            'createdAt'   => $this->createdAt,
        ];
    }
}
