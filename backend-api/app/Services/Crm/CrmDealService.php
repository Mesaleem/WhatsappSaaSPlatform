<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmDeal;
use App\Models\CrmPipelineStage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — deals.
 *
 * NUMBERING: DEAL-0001 per tenant. The next sequence is read under a lock on the
 * tenant's accounts row (unique(account_id, sequence) is the backstop), so concurrent
 * creates never share a number.
 *
 * STAGE AND STATUS move together, only here: entering a won stage stamps won_at,
 * a lost stage stamps lost_at (+ optional reason), an open stage clears both. A won
 * deal is final; a lost deal may be reopened by moving it to an open stage. Each move
 * writes a timeline event.
 */
class CrmDealService
{
    public function __construct(
        private readonly CrmPipelineStageService $stages,
        private readonly CrmActivityService $activities,
    ) {
    }

    /**
     * @param array<string, mixed> $data name required; value, stage_id, links, owner, close date optional
     */
    public function create(Account $account, array $data, ?int $actorUserId = null): CrmDeal
    {
        $stage = isset($data['stage_id'])
            ? $this->stageOf($account, (int) $data['stage_id'])
            : $this->stages->firstOpen($account);

        $deal = $this->withNextNumber($account, function (int $sequence) use ($account, $data, $stage): CrmDeal {
            $deal = new CrmDeal(['account_id' => $account->id, 'sequence' => $sequence, 'number' => sprintf('DEAL-%04d', $sequence)]
                + $this->only($data, ['name', 'crm_lead_id', 'contact_id', 'crm_company_id', 'value', 'currency', 'expected_close_date', 'owner_user_id', 'source']));
            $deal->stage_id = $stage->id;
            $this->applyOutcome($deal, $stage, $data['lost_reason'] ?? null);
            $deal->save();

            return $deal;
        });

        $this->activities->logEvent($account->id, "Deal {$deal->number} created in {$stage->name}", $this->links($deal), $actorUserId);

        return $deal->fresh();
    }

    /** @param array<string, mixed> $data */
    public function update(CrmDeal $deal, array $data): CrmDeal
    {
        $deal->fill($this->only($data, ['name', 'contact_id', 'crm_company_id', 'value', 'currency', 'expected_close_date', 'owner_user_id']))->save();

        return $deal->fresh();
    }

    public function moveToStage(CrmDeal $deal, int $stageId, ?string $lostReason = null, ?int $actorUserId = null): CrmDeal
    {
        return DB::transaction(function () use ($deal, $stageId, $lostReason, $actorUserId): CrmDeal {
            $deal = CrmDeal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $stage = $this->stageOf($deal->account, $stageId);

            if ($deal->stage_id === $stage->id && $lostReason === null) {
                return $deal;
            }

            if ($deal->status === CrmDeal::STATUS_WON && $stage->kind !== CrmPipelineStage::KIND_WON) {
                throw ValidationException::withMessages(['stage_id' => ['A won deal is final. Create a new deal for new business.']]);
            }

            $from = $deal->stage?->name;
            $deal->stage_id = $stage->id;
            $this->applyOutcome($deal, $stage, $lostReason);
            $deal->save();

            $subject = match ($deal->status) {
                CrmDeal::STATUS_WON => "Deal {$deal->number} won",
                CrmDeal::STATUS_LOST => "Deal {$deal->number} lost".($deal->lost_reason ? ": {$deal->lost_reason}" : ''),
                default => "Deal {$deal->number} moved".($from ? " from {$from}" : '')." to {$stage->name}",
            };
            $this->activities->logEvent($deal->account_id, $subject, $this->links($deal), $actorUserId);

            return $deal->fresh();
        });
    }

    public function delete(CrmDeal $deal): void
    {
        $deal->delete();
    }

    /** Status, won_at, lost_at and lost_reason from the stage's kind. */
    private function applyOutcome(CrmDeal $deal, CrmPipelineStage $stage, ?string $lostReason): void
    {
        $deal->status = match ($stage->kind) {
            CrmPipelineStage::KIND_WON => CrmDeal::STATUS_WON,
            CrmPipelineStage::KIND_LOST => CrmDeal::STATUS_LOST,
            default => CrmDeal::STATUS_OPEN,
        };
        $deal->won_at = $deal->status === CrmDeal::STATUS_WON ? ($deal->won_at ?? now()) : null;
        $deal->lost_at = $deal->status === CrmDeal::STATUS_LOST ? now() : null;
        $deal->lost_reason = $deal->status === CrmDeal::STATUS_LOST ? ($lostReason ?? $deal->lost_reason) : null;
    }

    private function stageOf(Account $account, int $stageId): CrmPipelineStage
    {
        $stage = CrmPipelineStage::query()->forAccount($account->id)->find($stageId);

        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => ['The selected stage is not available.']]);
        }

        return $stage;
    }

    /**
     * Run $create with the tenant's next deal sequence, serialized per tenant.
     *
     * @param callable(int): CrmDeal $create
     */
    private function withNextNumber(Account $account, callable $create): CrmDeal
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($account, $create): CrmDeal {
                    Account::query()->whereKey($account->id)->lockForUpdate()->first();
                    $next = (int) CrmDeal::query()->forAccount($account->id)->max('sequence') + 1;

                    return $create($next);
                });
            } catch (QueryException $e) {
                $duplicate = str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry');
                if (! $duplicate || $attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** @return array<string, int|null> */
    private function links(CrmDeal $deal): array
    {
        return [
            'crm_deal_id' => $deal->id,
            'crm_lead_id' => $deal->crm_lead_id,
            'contact_id' => $deal->contact_id,
            'crm_company_id' => $deal->crm_company_id,
        ];
    }

    /** @param array<string, mixed> $data @param list<string> $keys @return array<string, mixed> */
    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }
}
