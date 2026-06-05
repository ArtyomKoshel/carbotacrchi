<?php

namespace App\Services;

class ExpansionResult
{
    /** @param string[] $notes */
    public function __construct(
        public readonly SearchResult $result,
        public readonly bool         $relaxed,
        public readonly array        $notes,
    ) {}

    public static function exact(SearchResult $result): self
    {
        return new self($result, false, []);
    }

    /** @param string[] $notes */
    public static function relaxed(SearchResult $result, array $notes): self
    {
        return new self($result, true, $notes);
    }

    public function relaxedMessage(): string
    {
        if (!$this->relaxed || empty($this->notes)) {
            return '';
        }

        return 'Нет точного совпадения. Убрали: ' . implode(', ', $this->notes);
    }
}
