<?php

namespace App\Services\Industry;

use App\Models\Account;
use App\Models\User;
use App\Services\Access\DenialScope;

/**
 * Phase 11 Task 1 — what an account can see of its industries: for each assigned industry, whether it
 * may be used right now and, per registered module, whether that module is usable. The shape the API,
 * /auth/me and (later) the sidebar are all built from, so none of them re-implements the rules.
 */
class IndustryModuleResolver
{
    public function __construct(
        private readonly IndustryRegistry $registry,
        private readonly IndustryResolver $resolver,
        private readonly IndustryAuthorizer $authorizer,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contextFor(Account $account, ?User $user = null, ?bool $superAdmin = null): array
    {
        $out = [];
        // Phase 12 Task 4 — one scope for this whole read-only pass (the industry + every module share the
        // fresh target load and capability/module lookups). Discarded when the method returns.
        $scope = new DenialScope();
        $assignedRows = $this->resolver->forAccount($account);
        foreach ($assignedRows as $row) {
            $scope->seed("has:{$account->id}:{$row->industry}", true); // just read from account_industries
        }

        foreach ($assignedRows as $assigned) {
            $industry = $assigned->industry;
            $definition = $this->registry->find($industry);
            if ($definition === null) {
                continue; // a registry entry removed after assignment: nothing to show
            }

            $denial = $this->authorizer->denialWithin($scope, $account, $industry, null, true, $user, $superAdmin);
            $modules = [];
            foreach ($this->registry->modules($industry) as $key => $module) {
                $moduleDenial = $this->authorizer->denialWithin($scope, $account, $industry, $key, true, $user, $superAdmin);
                $modules[] = [
                    'key' => $key,
                    'label' => $module['label'],
                    'crm_anchor' => $module['crm_anchor'] ?? null,
                    'available' => (bool) ($module['available'] ?? false),
                    'allowed' => $moduleDenial === null,
                    'denial_code' => $moduleDenial['code'] ?? null,
                ];
            }

            $out[] = [
                'industry' => $industry,
                'label' => $definition['label'],
                'subtype' => $assigned->subtype,
                'subtype_label' => $assigned->subtype ? ($this->registry->verticals($industry)[$assigned->subtype] ?? null) : null,
                'allowed' => $denial === null,
                'denial_code' => $denial['code'] ?? null,
                'denial_message' => $denial['message'] ?? null,
                'modules' => $modules,
            ];
        }

        return $out;
    }

    /**
     * "industry.module" keys the user may use right now (read) — for navigation gating.
     *
     * @return list<string>
     */
    public function usableKeys(Account $account, ?User $user = null, ?bool $superAdmin = null): array
    {
        $keys = [];
        foreach ($this->contextFor($account, $user, $superAdmin) as $industry) {
            foreach ($industry['modules'] as $module) {
                if ($module['allowed']) {
                    $keys[] = $industry['industry'].'.'.$module['key'];
                }
            }
        }

        return $keys;
    }
}
