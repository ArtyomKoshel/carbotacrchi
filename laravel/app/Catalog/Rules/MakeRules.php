<?php

namespace App\Catalog\Rules;

/**
 * A make's engine designations: what is left of a badge once BadgeGrammar has cut out body,
 * complectation and editions ("xDrive 40i", "M3 Competition M xDrive") → the modification.
 */
interface MakeRules
{
    /**
     * @return array{code: ?string, fuel: ?string, drive: ?string, leftover: list<string>}
     *         code null with no leftover: the badge names no engine
     */
    public function modification(string $rest, array $model, array $generation): array;
}
