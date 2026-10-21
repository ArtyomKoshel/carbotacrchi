<?php

namespace App\Ingest;

/** Counters of one or more consumed batches. */
final class IngestResult
{
    public function __construct(
        public int $consumed = 0,
        public int $inserted = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $skipped = 0,
        public int $rejected = 0,
        public int $changes = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->consumed + $other->consumed,
            $this->inserted + $other->inserted,
            $this->updated + $other->updated,
            $this->unchanged + $other->unchanged,
            $this->skipped + $other->skipped,
            $this->rejected + $other->rejected,
            $this->changes + $other->changes,
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
