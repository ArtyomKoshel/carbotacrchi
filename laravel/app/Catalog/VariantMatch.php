<?php

namespace App\Catalog;

/**
 * One platform variant (make, model, badge, trim as the platform names it) placed on the
 * reference catalog.
 *
 * status:
 *   matched   — every word of the badge is understood and the modification is known
 *   no_engine — every word is understood, but the platform does not name the engine
 *   unmatched — unknown words are left (see $leftover) or the generation is unknown
 */
final class VariantMatch
{
    public const MATCHED = 'matched';
    public const NO_ENGINE = 'no_engine';
    public const UNMATCHED = 'unmatched';

    /**
     * @param list<string> $editions
     * @param list<string> $leftover words nothing understood (Korean ones included)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $make = null,
        public readonly ?string $model = null,
        public readonly ?string $generation = null,
        public readonly ?string $body = null,
        public readonly ?string $modification = null,
        public readonly ?string $fuel = null,
        public readonly ?string $drive = null,
        public readonly ?string $complectation = null,
        public readonly array $editions = [],
        public readonly ?int $seats = null,
        public readonly array $leftover = [],
        public readonly ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
