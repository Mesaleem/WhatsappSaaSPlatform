<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmSales;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmCompany;
use App\Services\Crm\CrmCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phase 1 — /api/crm/companies. Inside the CRM route group, so the same
 * gates as leads (crm.target, lead_crm module, manage-crm, crm capability). Every
 * lookup is tenant-scoped first, so a foreign id and a missing id are the same 404.
 */
class CrmCompanyController extends Controller
{
    use PresentsCrmSales;
    use ResolvesTenantAccount;

    public function __construct(private readonly CrmCompanyService $companies)
    {
    }

    /** GET /api/crm/companies?search=&type=&status=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(CrmCompany::TYPES)],
            'status' => ['nullable', Rule::in(CrmCompany::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $companies = CrmCompany::query()
            ->forAccount($account->id)
            ->with('owner')
            ->withCount(['contacts', 'deals'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('gstin', 'like', "%{$s}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        $companies->getCollection()->transform(fn (CrmCompany $c) => $this->presentCompany($c));

        return response()->json($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $company = $this->companies->create($account, $request->validate($this->rules(true)));

        return response()->json(['message' => 'Company created.', 'data' => $this->presentCompany($company->load('owner'))], 201);
    }

    /** GET /api/crm/companies/{id} — with its contacts and deals. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $company = $this->findOrFail($account->id, $id)->load(['owner', 'contacts', 'deals.stage'])->loadCount(['contacts', 'deals']);

        return response()->json(['data' => $this->presentCompany($company) + [
            'contacts' => $company->contacts->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone_number' => $c->phone_number, 'email' => $c->email, 'designation' => $c->designation])->values(),
            'deals' => $company->deals->map(fn ($d) => $this->presentDeal($d))->values(),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $company = $this->companies->update($this->findOrFail($account->id, $id), $request->validate($this->rules(false)));

        return response()->json(['message' => 'Company updated.', 'data' => $this->presentCompany($company->load('owner'))]);
    }

    /** DELETE — the company's contacts, leads, deals and activities stay, unlinked. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->companies->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Company deleted.']);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:191'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gstin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{2}[A-Za-z0-9]{13}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:191'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pincode' => ['sometimes', 'nullable', 'string', 'max:12'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', Rule::in(CrmCompany::TYPES)],
            'status' => ['sometimes', Rule::in(CrmCompany::STATUSES)],
        ];
    }

    private function findOrFail(int $accountId, int $id): CrmCompany
    {
        $company = CrmCompany::query()->forAccount($accountId)->find($id);
        abort_if(! $company, 404, 'Company not found.');

        return $company;
    }
}
