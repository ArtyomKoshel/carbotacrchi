<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyRule;
use App\Models\TaxonomyRuleHit;
use Illuminate\Support\Carbon;

class TaxonomyRuleEngine
{
    /** @var array<int, TaxonomyRule>|null */
    private ?array $rules = null;

    public function apply(array $context, bool $storeHit = false): array
    {
        $state = [
            'source' => (string) ($context['source'] ?? 'encar'),
            'lot_id' => isset($context['lot_id']) ? (string) $context['lot_id'] : null,
            'make' => trim((string) ($context['make'] ?? '')),
            'model' => trim((string) ($context['model'] ?? '')),
            'model_raw' => trim((string) ($context['model_raw'] ?? '')),
            'generation' => $this->nullable($context['generation'] ?? null),
            'trim' => $this->nullable($context['trim'] ?? null),
            'unknown_tail' => $this->nullable($context['unknown_tail'] ?? null),
        ];

        foreach ($this->rules() as $rule) {
            if (!$this->matches($rule, $state)) {
                continue;
            }

            $before = $state;
            $this->applyRule($rule, $state);
            $changed = $before['model'] !== $state['model']
                || $before['generation'] !== $state['generation']
                || $before['trim'] !== $state['trim'];

            if ($changed) {
                $rule->increment('hit_count');
                $rule->forceFill(['last_hit_at' => Carbon::now()])->save();
            }

            if ($storeHit) {
                TaxonomyRuleHit::query()->create([
                    'rule_id' => $rule->id,
                    'source' => $state['source'],
                    'lot_id' => $state['lot_id'],
                    'make' => $state['make'] ?: null,
                    'model_before' => $before['model'] ?: null,
                    'model_after' => $state['model'] ?: null,
                    'generation_before' => $before['generation'],
                    'generation_after' => $state['generation'],
                    'trim_before' => $before['trim'],
                    'trim_after' => $state['trim'],
                    'unknown_tail' => $before['unknown_tail'],
                    'applied' => $changed,
                ]);
            }
        }

        return [
            'model' => $state['model'],
            'generation' => $state['generation'],
            'trim' => $state['trim'],
            'unknown_tail' => $state['unknown_tail'],
        ];
    }

    /** @return array<int, TaxonomyRule> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $this->rules = TaxonomyRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->rules;
    }

    private function matches(TaxonomyRule $rule, array $state): bool
    {
        $source = trim((string) $rule->source);
        if ($source !== '' && $source !== '*' && $source !== $state['source']) {
            return false;
        }

        $make = trim((string) ($rule->make ?? ''));
        if ($make !== '' && mb_strtolower($make) !== mb_strtolower($state['make'])) {
            return false;
        }

        $contains = trim((string) ($rule->model_contains ?? ''));
        if ($contains !== '' && mb_stripos($state['model_raw'] ?: $state['model'], $contains) === false) {
            return false;
        }

        $tail = trim((string) ($rule->unknown_tail ?? ''));
        if ($tail !== '' && mb_strtolower((string) $state['unknown_tail']) !== mb_strtolower($tail)) {
            return false;
        }

        return true;
    }

    private function applyRule(TaxonomyRule $rule, array &$state): void
    {
        $value = trim((string) ($rule->action_value ?? ''));
        switch ($rule->action) {
            case 'set_trim':
                if ($state['trim'] === null || $state['trim'] === '') {
                    $state['trim'] = $value !== '' ? $value : $state['unknown_tail'];
                    $state['unknown_tail'] = null;
                }
                break;

            case 'set_generation':
                if (($state['generation'] === null || $state['generation'] === '') && $value !== '') {
                    $state['generation'] = $value;
                }
                break;

            case 'strip_tail':
                $target = $value !== '' ? $value : (string) $state['unknown_tail'];
                if ($target !== '') {
                    $suffix = ' ' . $target;
                    if (str_ends_with($state['model'], $suffix)) {
                        $state['model'] = trim(substr($state['model'], 0, -strlen($suffix)));
                    }
                    if ($state['unknown_tail'] !== null && mb_strtolower($state['unknown_tail']) === mb_strtolower($target)) {
                        $state['unknown_tail'] = null;
                    }
                }
                break;

            case 'replace_model':
                if ($value !== '') {
                    $state['model'] = $value;
                }
                break;
        }
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : $v;
    }
}
