<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmSales;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Services\Crm\CrmActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phase 1 — /api/crm/activities: follow-ups, tasks, notes and the timeline.
 *
 * GET filters: one of crm_lead_id / contact_id / crm_company_id / crm_deal_id (a
 * record's timeline, newest first), or the task list: status, assigned_user_id
 * (an id, "none" or "me") and due = overdue | today | upcoming (soonest first).
 */
class CrmActivityController extends Controller
{
    use PresentsCrmSales;
    use ResolvesTenantAccount;

    public function __construct(private readonly CrmActivityService $activities)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $f = $request->validate([
            'crm_lead_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'crm_company_id' => ['nullable', 'integer'],
            'crm_deal_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(CrmActivity::TYPES)],
            'status' => ['nullable', Rule::in(CrmActivity::STATUSES)],
            'assigned_user_id' => ['nullable', 'string', 'regex:/^(none|me|[1-9][0-9]*)$/'],
            'due' => ['nullable', Rule::in(['overdue', 'today', 'upcoming'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = CrmActivity::query()->forAccount($account->id)->with($this->activityRelations());

        foreach (CrmActivity::LINKS as $link) {
            if (! empty($f[$link])) {
                $query->where($link, (int) $f[$link]);
            }
        }

        $query->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['assigned_user_id'] ?? null, fn ($q, $v) => match ($v) {
                'none' => $q->whereNull('assigned_user_id'),
                'me' => $q->where('assigned_user_id', $request->user()->id),
                default => $q->where('assigned_user_id', (int) $v),
            });

        $due = $f['due'] ?? null;
        if ($due !== null) {
            $query->where('status', CrmActivity::STATUS_PENDING)->whereNotNull('due_at');
            match ($due) {
                'overdue' => $query->where('due_at', '<', now()),
                'today' => $query->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]),
                'upcoming' => $query->where('due_at', '>=', now()),
            };
            $query->orderBy('due_at')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $page = $query->paginate(min((int) ($f['per_page'] ?? 25), 100));
        $page->getCollection()->transform(fn (CrmActivity $a) => $this->presentActivity($a));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(CrmActivity::USER_TYPES)],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(CrmActivity::PRIORITIES)],
            'status' => ['nullable', Rule::in([CrmActivity::STATUS_PENDING, CrmActivity::STATUS_DONE])],
            'outcome' => ['nullable', 'string', 'max:255'],
            'assigned_user_id' => ['nullable', 'integer'],
            'crm_lead_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'crm_company_id' => ['nullable', 'integer'],
            'crm_deal_id' => ['nullable', 'integer'],
        ]);
        $data['priority'] ??= 'normal';

        $activity = $this->activities->create($account, $data, $request->user()?->id);

        return response()->json(['message' => 'Activity added.', 'data' => $this->presentActivity($activity->load($this->activityRelations()))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $activity = $this->activities->update($this->findOrFail($account->id, $id), $request->validate([
            'subject' => ['sometimes', 'string', 'max:191'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', Rule::in(CrmActivity::PRIORITIES)],
            'status' => ['sometimes', Rule::in(CrmActivity::STATUSES)],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
        ]));

        return response()->json(['message' => 'Activity updated.', 'data' => $this->presentActivity($activity->load($this->activityRelations()))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->activities->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Activity deleted.']);
    }

    private function findOrFail(int $accountId, int $id): CrmActivity
    {
        $activity = CrmActivity::query()->forAccount($accountId)->find($id);
        abort_if(! $activity, 404, 'Activity not found.');

        return $activity;
    }
}
