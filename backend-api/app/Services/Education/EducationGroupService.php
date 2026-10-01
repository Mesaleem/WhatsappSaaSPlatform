<?php

namespace App\Services\Education;

use App\Models\Account;
use App\Models\Education\EducationGroup;
use App\Services\Industry\IndustryRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Phase 11 Task 2 — classes (school) and batches (coaching/institute) through one model. The account's
 * education sub-type only supplies the DEFAULT kind; it never selects a different code path.
 */
class EducationGroupService
{
    public function __construct(private readonly IndustryRegistry $registry)
    {
    }

    /** @param array<string, mixed> $data name, kind?, academic_year?, status?, metadata? */
    public function create(Account $account, array $data): EducationGroup
    {
        $kind = $data['kind'] ?? $this->defaultKind($account);
        $this->assertNameFree($account, $kind, $data['name'], $data['academic_year'] ?? null, null);

        return EducationGroup::create([
            'account_id' => $account->id,
            'kind' => $kind,
            'name' => trim($data['name']),
            'academic_year' => $this->blankToNull($data['academic_year'] ?? null),
            'status' => $data['status'] ?? 'active',
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data name, academic_year, status, metadata (kind is fixed once created) */
    public function update(EducationGroup $group, array $data): EducationGroup
    {
        $name = $data['name'] ?? $group->name;
        $year = array_key_exists('academic_year', $data) ? $data['academic_year'] : $group->academic_year;
        $this->assertNameFree(Account::findOrFail($group->account_id), $group->kind, $name, $year, $group->getKey());

        $attributes = array_intersect_key($data, array_flip(['name', 'academic_year', 'status', 'metadata']));
        if (isset($attributes['name'])) {
            $attributes['name'] = trim($attributes['name']);
        }
        if (array_key_exists('academic_year', $attributes)) {
            $attributes['academic_year'] = $this->blankToNull($attributes['academic_year']);
        }
        $group->fill($attributes)->save();

        return $group->fresh();
    }

    /** The kind a NEW group gets: from the account's education vertical (school → class), else batch. */
    public function defaultKind(Account $account): string
    {
        $subtype = $account->industries()->where('industry', 'education')->value('subtype');

        return (string) ($this->registry->verticalConfig('education', $subtype)['group_kind'] ?? 'batch');
    }

    private function assertNameFree(Account $account, string $kind, string $name, mixed $year, ?int $exceptId): void
    {
        $year = $this->blankToNull($year);
        $taken = EducationGroup::query()->forAccount($account->id)->where('kind', $kind)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($year === null, fn ($q) => $q->whereNull('academic_year'), fn ($q) => $q->where('academic_year', $year))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ["A {$kind} with this name already exists for this academic year."]]);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
