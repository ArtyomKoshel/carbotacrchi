<?php

namespace App\Catalog;

use App\Catalog\Encar\EncarMatcher;
use App\Services\SearchQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Names a search carries as text — the AI search, the bot, saved subscriptions, old links:
 * "BMW", "5 Series", "G60" or "Sonata (DN8)", "Prestige" or "프레스티지" — to reference ids.
 * A name the catalog does not know matches no lot: a subscription to it must not turn into
 * "every car".
 */
class CatalogNames
{
    private const TTL = 600;

    private ?array $index = null;

    public function __construct(private readonly EncarMatcher $matcher) {}

    public function resolve(SearchQuery $q): void
    {
        $model = null;
        if ($q->refMakeId === 0 && $q->make !== '') {
            $q->refMakeId = $this->make($q->make) ?? 0;
            if ($q->refMakeId === 0) {
                $q->matchesNothing = true;
                return;
            }
        }
        if ($q->refModelId === 0 && $q->model !== '') {
            $model = $this->model($q->refMakeId ?: null, $q->model);
            if ($model === null) {
                $q->matchesNothing = true;
                return;
            }
            [$q->refMakeId, $q->refModelId] = [$model['make_id'], $model['id']];
        }
        if ($q->refGenerationIds === [] && $q->generation !== '' && $q->refModelId > 0) {
            $q->refGenerationIds = $this->generations($q->refModelId, $q->generation);
        }
        if ($q->refModificationIds === [] && $q->badge !== '' && $q->refModelId > 0) {
            $q->refModificationIds = $this->modifications($q->refModelId, $q->badge);
        }
        foreach ([[$q->generation, $q->refGenerationIds], [$q->badge, $q->refModificationIds]] as [$text, $ids]) {
            if ($text !== '' && $ids === []) {
                $q->matchesNothing = true;
            }
        }
        // the old filter's trim was Encar's BadgeDetail, often engine + complectation ("530e M 스포츠")
        if ($q->refComplectationIds === [] && $q->trim !== '' && $q->refMakeId > 0) {
            [$q->refComplectationIds, $rest] = $this->complectationsIn($q->refMakeId, $q->trim);
            if ($rest !== '') {
                $mods = $q->refModelId > 0 && $q->refModificationIds === [] ? $this->modifications($q->refModelId, $rest) : [];
                if ($mods === []) {
                    $q->matchesNothing = true;
                }
                $q->refModificationIds = $mods;
            }
        } elseif ($q->trim !== '' && $q->refComplectationIds === []) {
            $q->matchesNothing = true;
        }
    }

    /**
     * The make's complectations named in a text, longest first, whole words; and what is left.
     *
     * @return array{0: list<int>, 1: string}
     */
    public function complectationsIn(int $makeId, string $text): array
    {
        $text = ' ' . $this->matcher->english(trim($text)) . ' ';
        $all = DB::table('ref_complectations')->where('make_id', $makeId)->pluck('name', 'id')->all();
        uasort($all, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $ids = [];
        foreach ($all as $id => $name) {
            $pattern = '/(?<=\s)' . preg_quote($name, '/') . '(?=\s)/iu';
            if (preg_match($pattern, $text)) {
                $ids[] = (int) $id;
                $text = preg_replace($pattern, ' ', $text);
            }
        }
        return [$ids, trim(preg_replace('/\s+/u', ' ', $text))];
    }

    public function make(string $name): ?int
    {
        return $this->index()['makes'][self::key($name)] ?? null;
    }

    /**
     * The model of that name; with no make, the one model of that name across makes. An exact
     * name first, else the one model whose name holds the text ("Carnival" is not "Grand Carnival").
     *
     * @return array{id: int, make_id: int}|null
     */
    public function model(?int $makeId, string $name): ?array
    {
        $models = array_values(array_filter($this->index()['models'], fn (array $m) => $makeId === null || $m['make_id'] === $makeId));
        $key = self::key($name);
        $exact = array_values(array_filter($models, fn (array $m) => $m['key'] === $key));
        $found = $exact ?: array_values(array_filter($models, fn (array $m) => str_contains($m['key'], $key)));
        return count($found) === 1 ? ['id' => $found[0]['id'], 'make_id' => $found[0]['make_id']] : null;
    }

    /**
     * "G60", "Sonata (DN8)", "DN8": the generation of that code and its facelifts ("DN8 · The Edge").
     *
     * @return list<int>
     */
    public function generations(int $modelId, string $text): array
    {
        $code = preg_match('/\(([^)]+)\)\s*$/u', $text, $m) ? $m[1] : $text;
        return DB::table('ref_generations')->where('model_id', $modelId)
            ->where(fn ($q) => $q->where('code', trim($code))->orWhere('code', 'like', trim($code) . ' · %'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    public function modifications(int $modelId, string $code): array
    {
        return DB::table('ref_modifications as d')->join('ref_generations as g', 'g.id', '=', 'd.generation_id')
            ->where('g.model_id', $modelId)->where('d.code', trim($code))
            ->pluck('d.id')->map(fn ($id) => (int) $id)->all();
    }

    private function index(): array
    {
        return $this->index ??= Cache::remember('catalog_names', self::TTL, function () {
            $makes = [];
            foreach (DB::table('ref_makes')->get(['id', 'name']) as $r) {
                $makes[self::key($r->name)] = (int) $r->id;
            }
            $models = DB::table('ref_models')->get(['id', 'make_id', 'name'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'make_id' => (int) $r->make_id, 'key' => self::key($r->name)])->all();
            return ['makes' => $makes, 'models' => $models];
        });
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}
