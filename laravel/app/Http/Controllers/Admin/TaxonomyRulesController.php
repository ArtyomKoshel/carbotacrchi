<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyAnomalyQueue;
use App\Models\TaxonomyRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaxonomyRulesController extends Controller
{
    public function index(Request $request)
    {
        $source = trim((string) $request->query('source', 'encar'));
        $status = trim((string) $request->query('status', 'new'));

        $rules = TaxonomyRule::query()
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->orderBy('priority')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $queue = TaxonomyAnomalyQueue::query()
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('seen_count')
            ->paginate(50, ['*'], 'queue_page')
            ->withQueryString();

        return view('admin.taxonomy-rules', compact('rules', 'queue', 'source', 'status'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $data = $request->validate([
            'source' => 'required|string|max:32',
            'make' => 'nullable|string|max:80',
            'model_contains' => 'nullable|string|max:160',
            'unknown_tail' => 'nullable|string|max:191',
            'action' => 'required|in:set_trim,set_generation,strip_tail,replace_model',
            'action_value' => 'nullable|string|max:191',
            'priority' => 'nullable|integer|min:1|max:10000',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['priority'] = (int) ($data['priority'] ?? 100);

        TaxonomyRule::query()->create($data);

        return back()->with('success', 'Правило таксономии создано.');
    }

    public function updateRule(int $id, Request $request): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $rule = TaxonomyRule::query()->findOrFail($id);

        $data = $request->validate([
            'source' => 'required|string|max:32',
            'make' => 'nullable|string|max:80',
            'model_contains' => 'nullable|string|max:160',
            'unknown_tail' => 'nullable|string|max:191',
            'action' => 'required|in:set_trim,set_generation,strip_tail,replace_model',
            'action_value' => 'nullable|string|max:191',
            'priority' => 'nullable|integer|min:1|max:10000',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['priority'] = (int) ($data['priority'] ?? 100);

        $rule->fill($data)->save();

        return back()->with('success', 'Правило обновлено.');
    }

    public function deleteRule(int $id): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        TaxonomyRule::query()->whereKey($id)->delete();

        return back()->with('success', 'Правило удалено.');
    }

    public function updateQueue(int $id, Request $request): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $row = TaxonomyAnomalyQueue::query()->findOrFail($id);
        $status = (string) $request->input('status', 'new');
        if (!in_array($status, ['new', 'rule_created', 'ignored'], true)) {
            $status = 'new';
        }

        $row->status = $status;
        $row->save();

        return back()->with('success', 'Статус аномалии обновлён.');
    }

    public function createRuleFromQueue(int $id): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $row = TaxonomyAnomalyQueue::query()->findOrFail($id);
        if (!$row->suggested_action) {
            return back()->with('error', 'Для этой аномалии нет suggestion.');
        }

        $rule = TaxonomyRule::query()->create([
            'source' => $row->source,
            'make' => $row->make,
            'unknown_tail' => $row->unknown_tail,
            'action' => $row->suggested_action,
            'action_value' => $row->suggested_value,
            'priority' => 100,
            'is_active' => true,
            'notes' => 'Auto-created from anomaly queue #' . $row->id,
        ]);

        $row->status = 'rule_created';
        $row->rule_id = $rule->id;
        $row->save();

        return back()->with('success', 'Правило создано из аномалии.');
    }

    public function ingest(Request $request): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $source = trim((string) $request->input('source', 'encar'));
        $max = max(0, (int) $request->input('max', 50000));
        $code = Artisan::call('taxonomy:ingest-anomalies', [
            '--source' => $source,
            '--max' => $max,
        ]);

        if ($code !== 0) {
            return back()->with('error', 'Ошибка ingest anomalies: ' . trim(Artisan::output()));
        }

        return back()->with('success', trim(Artisan::output()));
    }

    public function bootstrap(Request $request): RedirectResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $source = trim((string) $request->input('source', 'encar'));
        $minSeen = max(1, (int) $request->input('min_seen', 5));
        $minConfidence = max(0.0, min(1.0, (float) $request->input('min_confidence', 0.80)));
        $actions = trim((string) $request->input('actions', 'set_trim'));
        $apply = (bool) $request->boolean('apply', false);

        $args = [
            '--source' => $source,
            '--min-seen' => $minSeen,
            '--min-confidence' => $minConfidence,
            '--actions' => $actions,
        ];
        if ($apply) {
            $args['--apply'] = true;
        }

        $code = Artisan::call('taxonomy:bootstrap-rules', $args);
        if ($code !== 0) {
            return back()->with('error', 'Ошибка bootstrap rules: ' . trim(Artisan::output()));
        }

        return back()->with('success', trim(Artisan::output()));
    }

    public function aiClassifyQueue(int $id): JsonResponse
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }

        $row = TaxonomyAnomalyQueue::query()->findOrFail($id);

        $apiKey = config('ai.api_key', '');
        $apiUrl = config('ai.api_url', '');
        $model  = config('ai.model', '');

        if (empty($apiKey)) {
            return response()->json(['error' => 'AI not configured'], 503);
        }

        $prompt = <<<'PROMPT'
You are an expert in Korean car marketplace (Encar) taxonomy. A token at the end of a car model string could not be automatically classified. Determine what it represents and provide a useful explanation in Russian.

Fields: make, model (full original string from Encar), tail (the unclassified token).

IMPORTANT: The tail sometimes includes the model name as a prefix (e.g., tail="코란도 R-플러스" for Korando model). In that case, strip the model prefix — the actual token is "R-플러스" which is a trim, not a model_suffix.

Classify "tail" as one of:
- "trim": grade/trim level (프레스티지, 노블레스, 스포츠, AMG, N라인, 모던, 에디션 variants, GT-Line, F Sport, R-플러스, etc.)
- "package": option package (패키지, 팩, 래더패키지, AMG패키지, 디자인패키지, 런치팩, etc.)
- "variant": engine/performance code (320d, S500L, 55 TFSI, 2.0T, xDrive40i, etc.)
- "body_style": body type (쿠페, 해치백, 왜건, 카브리올레, 4도어, etc.)
- "model_suffix": sub-model name that belongs in model field (뉴 라이즈, 더 볼드, 마이스터, etc.)
- "noise": irrelevant, remove

Return JSON with these fields:
- "type": classification type (one of above)
- "value": the clean token (strip model name prefix if present, strip drive tokens like 2WD/4WD)
- "confidence": 0.0-1.0
- "translation_en": English translation/meaning of the token (e.g. "R-Plus", "Sport", "Prestige", "Long Range Edition")
- "context": 1-2 sentences in Russian explaining what this trim/package/variant means for this car model, which years, what it includes or how it differs from base trim. Be specific and informative.
- "reason": one sentence in Russian explaining why you chose this classification
PROMPT;

        $input = json_encode([
            'make'  => $row->make,
            'model' => $row->sample_model_raw,
            'tail'  => $row->unknown_tail,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($apiUrl, [
                    'model'           => $model,
                    'max_tokens'      => 300,
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user',   'content' => $input],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('[TaxonomyAI] API error: ' . $response->status());
                return response()->json(['error' => 'AI API error ' . $response->status()], 502);
            }

            $content = $response->json('choices.0.message.content', '');
            $result  = json_decode($content, true);

            if (!is_array($result) || empty($result['type'])) {
                return response()->json(['error' => 'Unexpected AI response', 'raw' => $content], 502);
            }

            $actionMap = [
                'trim'         => 'set_trim',
                'package'      => 'set_package',
                'variant'      => 'set_variant',
                'noise'        => 'strip_tail',
                'body_style'   => 'strip_tail',
                'model_suffix' => null,
            ];

            $suggested_action = $actionMap[$result['type']] ?? null;
            $confidence       = (float) ($result['confidence'] ?? 0.5);

            $row->update([
                'suggested_action'      => $suggested_action,
                'suggested_value'       => $result['value'] ?? $row->unknown_tail,
                'suggestion_confidence' => $confidence,
                'status'                => 'ai_reviewed',
            ]);

            return response()->json([
                'type'           => $result['type'],
                'value'          => $result['value'] ?? $row->unknown_tail,
                'action'         => $suggested_action,
                'confidence'     => $confidence,
                'translation_en' => $result['translation_en'] ?? null,
                'context'        => $result['context'] ?? null,
                'reason'         => $result['reason'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::error('[TaxonomyAI] ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
