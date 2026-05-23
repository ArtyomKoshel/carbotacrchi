<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyAnomalyQueue;
use App\Models\TaxonomyRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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
}
