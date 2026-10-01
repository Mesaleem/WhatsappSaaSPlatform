<?php

namespace App\Services\Industry;

use App\Models\Account;
use App\Models\User;

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

        foreach ($this->resolver->forAccount($account) as $assigned) {
            $industry = $assigned->industry;
            $definition = $this->registry->find($industry);
            if ($definition === null) {
                continue; // a registry entry removed after assignment: nothing to show
            }

            $denial = $this->authorizer->denial($account, $industry, null, true, $user, $superAdmin);
            $modules = [];
            foreach ($this->registry->modules($industry) as $key => $module) {
                $moduleDenial = $this->authorizer->denial($account, $industry, $key, true, $user, $superAdmin);
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
