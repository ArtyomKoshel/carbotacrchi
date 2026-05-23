<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyRule;

class TaxonomySuggestionService
{
    public function suggest(?string $unknownTail, ?string $modelRaw = null): array
    {
        $tail = trim((string) $unknownTail);
        if ($tail === '') {
            return ['action' => null, 'value' => null, 'confidence' => 0.0];
        }

        $lower = mb_strtolower($tail);
        if ($this->looksLikeTrim($lower)) {
            return ['action' => 'set_trim', 'value' => $tail, 'confidence' => 0.82];
        }

        if (preg_match('/^[A-Z]{1,3}\d{1,3}$/', $tail) === 1) {
            return ['action' => 'set_generation', 'value' => $tail, 'confidence' => 0.86];
        }

        $fromExisting = $this->matchExistingRule($tail);
        if ($fromExisting !== null) {
            return $fromExisting;
        }

        if ($modelRaw && preg_match('/\(([A-Za-z0-9]{2,6})\)/', $modelRaw, $m) === 1) {
            return ['action' => 'set_generation', 'value' => $m[1], 'confidence' => 0.74];
        }

        return ['action' => 'strip_tail', 'value' => $tail, 'confidence' => 0.55];
    }

    private function looksLikeTrim(string $tailLower): bool
    {
        foreach (['에디션', '스페셜', '패키지', '플러스', '스타일', '셀렉션', '노블레스', '프레스티지', '시그니처', '인스퍼레이션'] as $hint) {
            if (str_contains($tailLower, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function matchExistingRule(string $tail): ?array
    {
        $rules = TaxonomyRule::query()
            ->where('is_active', true)
            ->whereNotNull('unknown_tail')
            ->whereNotNull('action')
            ->orderBy('priority')
            ->limit(300)
            ->get(['unknown_tail', 'action', 'action_value']);

        $best = null;
        $bestPct = 0.0;

        foreach ($rules as $rule) {
            $left = mb_strtolower((string) $rule->unknown_tail);
            $right = mb_strtolower($tail);
            if ($left === '' || $right === '') {
                continue;
            }

            similar_text($left, $right, $pct);
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = $rule;
            }
        }

        if ($best === null || $bestPct < 88.0) {
            return null;
        }

        return [
            'action' => (string) $best->action,
            'value' => (string) ($best->action_value ?: $tail),
            'confidence' => min(0.95, round($bestPct / 100, 2)),
        ];
    }
}
