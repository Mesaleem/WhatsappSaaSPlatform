<?php

namespace App\Http\Controllers\Api\Industry;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionChargeItem;
use App\Models\Collections\CollectionPayment;
use App\Models\Education\EducationStudent;
use App\Services\Education\EducationFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 11 Task 4 — Education fees under /api/industry/education: the Education ADAPTER's HTTP surface over
 * the generic Billing & Collections core.
 *
 *   GET|POST  /fee-items            GET|PATCH /fee-items/{id}        the charge catalogue
 *   GET|POST  /students/{id}/fees   PUT /students/{id}/fees/{fee}    a student's charges (+ balances) / assign / adjust
 *   POST      /students/{id}/fees/{fee}/payments                     record a payment
 *   POST      /students/{id}/fees/{fee}/cancel | /reinstate          void a charge with no payments / undo it
 *   GET       /students/{id}/fee-payments    GET /fee-payments       payment history
 *
 * Authorization is `industry.guard:education,fees` on the route (view-education for reads; manage-education +
 * active subscription for writes; plus the generic `billing_collections` capability the `fees` module
 * requires). This controller resolves the TARGET account (Super Admin must pass ?account_id=), looks every id up
 * INSIDE it (a foreign id is a 404) and delegates — it holds no money logic.
 */
class EducationFeeController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(private readonly EducationFeeService $fees)
    {
    }

    // ------------------------------------------------------------ catalogue

    public function items(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CollectionChargeItem::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $this->fees->items($account)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['search']) && trim($filters['search']) !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes(trim($filters['search']), '%_\\').'%'))
            ->orderBy('name')->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX));
        $page->getCollection()->transform(fn (CollectionChargeItem $i) => $this->presentItem($i));

        return response()->json($page);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $item = $this->fees->createItem($account, $request->validate($this->itemRules(true)));

        return response()->json(['message' => 'Created.', 'data' => $this->presentItem($item)], 201);
    }

    public function showItem(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        return response()->json(['data' => $this->presentItem($this->fees->item($account, $id))]);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $item = $this->fees->item($account, $id);
        $item = $this->fees->updateItem($account, $item, $request->validate($this->itemRules(false)));

        return response()->json(['message' => 'Updated.', 'data' => $this->presentItem($item)]);
    }

    // ------------------------------------------------------------ a student's charges

    public function studentFees(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'partially_paid', 'paid', 'overdue', 'cancelled'])]]);
        $student = $this->student($account->id, $id);

        return response()->json($this->fees->ledger($account, $student, $filters['status'] ?? null) + [
            'student' => ['id' => $student->id, 'name' => $student->contact?->name, 'phone_number' => $student->contact?->phone_number, 'admission_number' => $student->admission_number],
        ]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate([
            'charge_item_id' => ['required', 'integer'],
            'amount_due' => ['nullable'],
            'due_date' => ['nullable', 'string'],
        ]);
        $assignment = $this->fees->assign($account, $this->student($account->id, $id), $data, $request->user()?->id);

        return response()->json(['message' => 'Charge assigned.', 'data' => $this->fees->core()->present($account, $assignment)], 201);
    }

    public function updateAssignment(Request $request, int $id, int $feeId): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate([
            'amount_due' => ['sometimes', 'required'],
            'due_date' => ['sometimes', 'nullable', 'string'],
            // Not adjustable on an existing assignment (it would change what the payments mean) — refused, not ignored.
            'charge_item_id' => ['prohibited'],
            'status' => ['prohibited'],
            'amount_paid' => ['prohibited'],
            'outstanding' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
        ]);
        $assignment = $this->fees->updateAssignment($account, $this->student($account->id, $id), $feeId, $data);

        return response()->json(['message' => 'Updated.', 'data' => $this->fees->core()->present($account, $assignment)]);
    }

    // ------------------------------------------------------------ lifecycle

    public function cancel(Request $request, int $id, int $feeId): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $this->rejectUnsupportedFields($request, ['reason']);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $assignment = $this->fees->cancelFee($account, $this->student($account->id, $id), $feeId, $data['reason'] ?? null, $request->user()?->id);

        return response()->json(['message' => 'Charge cancelled.', 'data' => $this->fees->core()->present($account, $assignment)]);
    }

    public function reinstate(Request $request, int $id, int $feeId): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $this->rejectUnsupportedFields($request, []);
        $assignment = $this->fees->reinstateFee($account, $this->student($account->id, $id), $feeId);

        return response()->json(['message' => 'Charge reinstated.', 'data' => $this->fees->core()->present($account, $assignment)]);
    }

    // ------------------------------------------------------------ payments

    public function pay(Request $request, int $id, int $feeId): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate([
            'amount' => ['required'],
            'payment_date' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $result = $this->fees->recordPayment($account, $this->student($account->id, $id), $feeId, $data, $request->user()?->id);

        return response()->json([
            'message' => $result['idempotent'] ? 'Payment already recorded.' : 'Payment recorded.',
            'idempotent' => $result['idempotent'],
            'payment' => $this->fees->core()->presentPayment($result['payment']),
            'data' => $result['assignment'],
        ], $result['idempotent'] ? 200 : 201);
    }

    public function studentPayments(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $this->paymentFilters($request);
        $student = $this->student($account->id, $id);

        return $this->paymentPage($request, $this->fees->studentPayments($account, $student, $filters, $this->perPage($request)));
    }

    public function payments(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $this->paymentFilters($request);
        if (! empty($filters['student_id'])) {
            $filters['contact_id'] = (int) $this->student($account->id, (int) $filters['student_id'])->contact_id;
        }
        unset($filters['student_id']);

        return $this->paymentPage($request, $this->fees->payments($account, $filters, $this->perPage($request)));
    }

    // ------------------------------------------------------------ helpers

    /**
     * Lifecycle endpoints take no field they do not use: anything else in the BODY (a status, a balance, an
     * account id…) is a clear 422, never silently ignored. (The target account travels in the query string.)
     *
     * @param list<string> $allowed
     */
    private function rejectUnsupportedFields(Request $request, array $allowed): void
    {
        $unsupported = array_diff(array_keys($request->json()->all() + $request->request->all()), $allowed);
        if ($unsupported !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys(array_map('strval', $unsupported), ['This field is not supported here.']),
            );
        }
    }

    private function student(int $accountId, int $id): EducationStudent
    {
        $student = EducationStudent::query()->forAccount($accountId)->with('contact')->find($id);
        abort_if(! $student, 404, 'Student not found.');

        return $student;
    }

    /** @return array<string, mixed> */
    private function paymentFilters(Request $request): array
    {
        return $request->validate([
            'student_id' => ['nullable', 'integer'],
            'charge_assignment_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(CollectionPayment::METHODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);
    }

    private function paymentPage(Request $request, $page): JsonResponse
    {
        return response()->json([
            'data' => $page->getCollection()->map(fn (CollectionPayment $p) => $this->fees->core()->presentPayment($p))->values(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function itemRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => [$required],
            'frequency' => [$creating ? 'nullable' : 'sometimes', Rule::in(CollectionChargeItem::FREQUENCIES)],
            'status' => [$creating ? 'nullable' : 'sometimes', Rule::in(CollectionChargeItem::STATUSES)],
        ];
    }

    /** @return array<string, mixed> */
    private function presentItem(CollectionChargeItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'amount' => $this->fees->core()->format($this->fees->core()->toMinor(number_format((float) $item->amount, 2, '.', ''))),
            'frequency' => $item->frequency,
            'status' => $item->status,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
