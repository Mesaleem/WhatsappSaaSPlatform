<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\CrmCompany;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use Illuminate\Support\Facades\DB;

/**
 * CRM Sales Phase 1 — customer companies.
 *
 * findOrCreate() is the conversion's dedupe: a company is the same company when its
 * GSTIN matches, else when its name matches case-insensitively, within the tenant.
 *
 * delete() keeps every person and sale: contacts, leads, deals and activities are
 * detached (their company link set to NULL) in the same transaction, then the company
 * row goes. That is also what the RESTRICT foreign keys on MySQL require.
 */
class CrmCompanyService
{
    /** @param array<string, mixed> $data */
    public function create(Account $account, array $data): CrmCompany
    {
        return CrmCompany::create(['account_id' => $account->id] + $this->only($data));
    }

    /** @param array<string, mixed> $data */
    public function update(CrmCompany $company, array $data): CrmCompany
    {
        $company->fill($this->only($data))->save();

        return $company->fresh();
    }

    /**
     * The tenant's company with this GSTIN, else with this name (case-insensitive),
     * else a new one. Locked against a concurrent conversion creating the same company.
     *
     * @param array<string, mixed> $data name required; gstin and the rest optional
     */
    public function findOrCreate(Account $account, array $data): CrmCompany
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        $gstin = isset($data['gstin']) && trim((string) $data['gstin']) !== '' ? strtoupper(trim((string) $data['gstin'])) : null;

        return DB::transaction(function () use ($account, $data, $name, $gstin): CrmCompany {
            Account::query()->whereKey($account->id)->lockForUpdate()->first();

            $existing = null;
            if ($gstin !== null) {
                $existing = CrmCompany::query()->forAccount($account->id)->where('gstin', $gstin)->first();
            }
            if ($existing === null && $name !== '') {
                $existing = CrmCompany::query()->forAccount($account->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])->first();
            }

            return $existing ?? $this->create($account, ['name' => $name, 'gstin' => $gstin] + $data);
        });
    }

    public function delete(CrmCompany $company): void
    {
        DB::transaction(function () use ($company): void {
            $scope = fn (string $model) => $model::query()->where('account_id', $company->account_id)->where('crm_company_id', $company->id);

            // Through the models where an audit trail matters (contacts, deals); a bulk
            // update for leads and activities, whose own history is not changed.
            $scope(Contact::class)->each(fn (Contact $c) => $c->forceFill(['crm_company_id' => null])->save());
            $scope(CrmDeal::class)->each(fn (CrmDeal $d) => $d->forceFill(['crm_company_id' => null])->save());
            $scope(CrmLead::class)->update(['crm_company_id' => null]);
            $scope(CrmActivity::class)->update(['crm_company_id' => null]);

            $company->delete();
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'industry', 'website', 'gstin', 'phone', 'email', 'address_line1', 'address_line2',
            'city', 'state', 'pincode', 'country', 'owner_user_id', 'type', 'status',
        ]));
    }
}
