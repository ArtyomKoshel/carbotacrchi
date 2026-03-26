<?php

namespace App\Services;

use App\Dto\LotDTO;

class SearchResult
{
    /** @param LotDTO[] $lots */
    public function __construct(
        public readonly array $lots,
        public readonly int   $total,
        /** @var string[] */
        public readonly array $errors = [],
    ) {}

    public function toArray(): array
    {
        return [
            'lots'   => array_map(fn (LotDTO $l) => $l->toArray(), $this->lots),
            'total'  => $this->total,
            'errors' => $this->errors,
        ];
    }
}
