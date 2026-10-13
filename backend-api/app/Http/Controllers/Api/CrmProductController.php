<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmDocuments;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmProduct;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 2 — /api/crm/products: the tenant's catalogue. Documents copy what
 * they use, so editing or deleting a product never changes an issued document.
 * Gates: the CRM group + manage-crm-documents.
 */
class CrmProductController extends Controller
{
    use PresentsCrmDocuments;
    use ResolvesTenantAccount;

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:191'], 'active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1']]);

        $page = CrmProduct::query()->forAccount($account->id)
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when(array_key_exists('active', $f) && $f['active'] !== null, fn ($q) => $q->where('is_active', (bool) $f['active']))
            ->orderBy('name')->orderBy('id')
            ->paginate(min((int) ($f['per_page'] ?? 50), 200));
        $page->getCollection()->transform(fn (CrmProduct $p) => $this->presentProduct($p));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $product = $this->save(new CrmProduct(['account_id' => $account->id]), $request->validate($this->rules(true)));

        return response()->json(['message' => 'Product created.', 'data' => $this->presentProduct($product)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $product = $this->save($this->findOrFail($account->id, $id), $request->validate($this->rules(false)));

        return response()->json(['message' => 'Product updated.', 'data' => $this->presentProduct($product)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->findOrFail($account->id, $id)->delete();

        return response()->json(['message' => 'Product deleted. Documents that used it keep their own copy.']);
    }

    /** @param array<string, mixed> $data */
    private function save(CrmProduct $product, array $data): CrmProduct
    {
        try {
            $product->fill($data)->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry')) {
                throw ValidationException::withMessages(['code' => ['Another product already uses this code.']]);
            }
            throw $e;
        }

        return $product->fresh();
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:191'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'unit_price' => [$req, 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'hsn_sac' => ['sometimes', 'nullable', 'string', 'max:20'],
            'billing_frequency' => ['sometimes', Rule::in(CrmProduct::FREQUENCIES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function findOrFail(int $accountId, int $id): CrmProduct
    {
        $p = CrmProduct::query()->forAccount($accountId)->find($id);
        abort_if(! $p, 404, 'Product not found.');

        return $p;
    }
}
