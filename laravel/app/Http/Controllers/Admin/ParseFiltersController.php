<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParseFilter;
use App\Services\FieldRegistryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as ValidationRule;

class ParseFiltersController extends Controller
{
    /** GET /admin/filters — list and manage parse_filters rules. */
    public function index(FieldRegistryService $registry)
    {
        $filters = ParseFilter::orderBy('priority')->orderBy('id')->get();

        $recentHits = DB::table('lot_changes')
            ->where('event', 'like', 'deactivated_filter%')
            ->where('recorded_at', '>=', now()->subDay())
            ->count();

        return view('admin.filters', [
            'filters' => $filters,
            'schema' => $registry->schema(),
            'groupedFields' => $registry->groupedFilterable(),
            'operators' => ParseFilter::OPERATORS,
            'operatorLabels' => ParseFilter::OPERATOR_LABELS,
            'actions' => ParseFilter::ACTIONS,
            'actionLabels' => ParseFilter::ACTION_LABELS,
            'sources' => ParseFilter::SOURCES,
            'phases' => ParseFilter::PHASES,
            'phaseLabels' => ParseFilter::PHASE_LABELS,
            'recentHits' => $recentHits,
        ]);
    }

    /** POST /admin/filters — create a new rule. */
    public function create(Request $request)
    {
        $data = $this->validateFilterPayload($request);
        ParseFilter::create($data);

        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$data['name']}' created — parser picks it up within 60s.");
    }

    /** PUT /admin/filters/{id} — update an existing rule. */
    public function update(Request $request, int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $data = $this->validateFilterPayload($request, $id);
        $filter->update($data);

        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$filter->name}' updated.");
    }

    /** DELETE /admin/filters/{id} — delete a rule. */
    public function delete(int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $name = $filter->name;
        $filter->delete();

        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$name}' deleted.");
    }

    /** PATCH /admin/filters/{id}/toggle — quickly flip enabled. */
    public function toggle(int $id)
    {
        $filter = ParseFilter::findOrFail($id);
        $filter->enabled = !$filter->enabled;
        $filter->save();

        return redirect()->route('admin.filters')
            ->with('success', "Rule '{$filter->name}' " . ($filter->enabled ? 'enabled' : 'disabled') . '.');
    }

    private function validateFilterPayload(Request $request, ?int $ignoreId = null): array
    {
        $nameRule = ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/i'];
        $nameRule[] = ValidationRule::unique('parse_filters', 'name')
            ->ignore($ignoreId);

        $validated = $request->validate([
            'name' => $nameRule,
            'source' => ['nullable', ValidationRule::in(ParseFilter::SOURCES)],
            'field' => ['required', 'string', 'max:64'],
            'operator' => ['required', ValidationRule::in(ParseFilter::OPERATORS)],
            'value' => ['nullable', 'string', 'max:4096'],
            'action' => ['required', ValidationRule::in(ParseFilter::ACTIONS)],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'rule_group_id' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/i'],
            'enabled' => ['nullable'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['enabled'] = (bool) $request->input('enabled', false);
        $validated['source'] = $validated['source'] ?: null;
        $validated['rule_group_id'] = $validated['rule_group_id'] ?? null ?: null;

        $raw = $validated['value'] ?? null;
        if ($raw === null || $raw === '') {
            $validated['value'] = null;
        } elseif ($this->isJson($raw)) {
            $validated['value'] = $raw;
        } else {
            $validated['value'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        return $validated;
    }

    private function isJson(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        json_decode($raw);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
