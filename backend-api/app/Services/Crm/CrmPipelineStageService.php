<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmDeal;
use App\Models\CrmPipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — a tenant's deal pipeline stages.
 *
 * The default set (CrmPipelineStage::DEFAULTS) is created on first use, under a lock on
 * the tenant's accounts row, so two first requests at once cannot create it twice. The
 * pipeline must always keep at least one open, one won and one lost stage, so a deal
 * can always be won or lost. A stage that still holds deals cannot be deleted.
 */
class CrmPipelineStageService
{
    /** @return Collection<int, CrmPipelineStage> */
    public function forAccount(Account $account): Collection
    {
        $this->ensureDefaults($account);

        return CrmPipelineStage::query()->forAccount($account->id)->ordered()->get();
    }

    public function ensureDefaults(Account $account): void
    {
        if (CrmPipelineStage::query()->forAccount($account->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($account): void {
            Account::query()->whereKey($account->id)->lockForUpdate()->first();

            if (CrmPipelineStage::query()->forAccount($account->id)->exists()) {
                return;
            }

            foreach (CrmPipelineStage::DEFAULTS as $position => [$name, $probability, $kind]) {
                CrmPipelineStage::create([
                    'account_id' => $account->id, 'name' => $name, 'position' => $position + 1,
                    'probability' => $probability, 'kind' => $kind,
                ]);
            }
        });
    }

    /** The first open stage: where a new deal starts. */
    public function firstOpen(Account $account): CrmPipelineStage
    {
        $this->ensureDefaults($account);

        return CrmPipelineStage::query()->forAccount($account->id)->where('kind', CrmPipelineStage::KIND_OPEN)->ordered()->firstOrFail();
    }

    /** @param array{name: string, probability?: int, kind?: string, position?: int} $data */
    public function create(Account $account, array $data): CrmPipelineStage
    {
        $this->ensureDefaults($account);

        $position = $data['position'] ?? ((int) CrmPipelineStage::query()->forAccount($account->id)->max('position') + 1);

        return CrmPipelineStage::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'probability' => $data['probability'] ?? 0,
            'kind' => $data['kind'] ?? CrmPipelineStage::KIND_OPEN,
            'position' => $position,
        ]);
    }

    /**
     * Rename, change probability or kind. Changing the kind is refused while the stage
     * holds deals (their status would no longer match), and never leaves the pipeline
     * without an open, a won and a lost stage.
     *
     * @param array{name?: string, probability?: int, kind?: string} $data
     */
    public function update(CrmPipelineStage $stage, array $data): CrmPipelineStage
    {
        return DB::transaction(function () use ($stage, $data): CrmPipelineStage {
            if (isset($data['kind']) && $data['kind'] !== $stage->kind) {
                if (CrmDeal::query()->where('stage_id', $stage->id)->exists()) {
                    throw ValidationException::withMessages(['kind' => ['Move the deals out of this stage before changing what it means.']]);
                }
                $this->assertKeepsEveryKind($stage, $data['kind']);
            }

            $stage->fill(array_intersect_key($data, array_flip(['name', 'probability', 'kind'])))->save();

            return $stage->fresh();
        });
    }

    public function delete(CrmPipelineStage $stage): void
    {
        DB::transaction(function () use ($stage): void {
            if (CrmDeal::query()->where('stage_id', $stage->id)->exists()) {
                throw ValidationException::withMessages(['stage' => ['This stage still has deals. Move them to another stage first.']]);
            }
            $this->assertKeepsEveryKind($stage, null);

            $stage->delete();
        });
    }

    /**
     * Set the column order: $ids is every stage id of the tenant, in the new order.
     *
     * @param list<int> $ids
     */
    public function reorder(Account $account, array $ids): void
    {
        DB::transaction(function () use ($account, $ids): void {
            $stages = CrmPipelineStage::query()->forAccount($account->id)->lockForUpdate()->get()->keyBy('id');

            $given = array_values(array_unique(array_map('intval', $ids)));
            if (count($given) !== $stages->count() || array_diff($given, $stages->keys()->all()) !== []) {
                throw ValidationException::withMessages(['ids' => ['Send every stage of the pipeline exactly once.']]);
            }

            foreach ($given as $index => $id) {
                $stage = $stages[$id];
                if ($stage->position !== $index + 1) {
                    $stage->forceFill(['position' => $index + 1])->save();
                }
            }
        });
    }

    /** Refuses a change that would leave no stage of some kind. $newKind null = the stage is being deleted. */
    private function assertKeepsEveryKind(CrmPipelineStage $stage, ?string $newKind): void
    {
        $remaining = CrmPipelineStage::query()->forAccount($stage->account_id)->whereKeyNot($stage->id)->pluck('kind')->all();
        if ($newKind !== null) {
            $remaining[] = $newKind;
        }

        foreach (CrmPipelineStage::KINDS as $kind) {
            if (! in_array($kind, $remaining, true)) {
                throw ValidationException::withMessages(['kind' => ["The pipeline needs at least one {$kind} stage."]]);
            }
        }
    }
}
