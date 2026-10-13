import { useState } from 'react';
import crmSalesService from '../../services/crmSalesService';
import type { CrmDeal, CrmStage } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';
import PromptModal from '../common/PromptModal';
import { READ_ONLY_TITLE } from './CrmUi';

/**
 * CRM Sales Phase 1 — move a deal to another stage (PATCH /crm/deals/{id}/stage). A
 * lost stage asks for the optional reason. A won deal is final: the control is locked
 * (the server refuses it too).
 */
export default function DealStageControl({
  deal,
  stages,
  disabled,
  onMoved,
  onError,
}: {
  deal: CrmDeal;
  stages: CrmStage[];
  disabled?: boolean;
  onMoved: (deal: CrmDeal, message: string) => void;
  onError: (message: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [askLost, setAskLost] = useState<CrmStage | null>(null);
  const won = deal.status === 'won';

  const move = (stage: CrmStage, reason?: string) => {
    setBusy(true);
    crmSalesService
      .moveDeal(deal.id, stage.id, reason?.trim() || null)
      .then((res) => onMoved(res.data, res.message))
      .catch((err: unknown) => onError(describeApiError(err, 'Could not move the deal.').message))
      .finally(() => {
        setBusy(false);
        setAskLost(null);
      });
  };

  return (
    <>
      <select
        aria-label={`Move ${deal.number}`}
        value={deal.stage_id}
        disabled={disabled || busy || won}
        title={disabled ? READ_ONLY_TITLE : won ? 'A won deal is final. Create a new deal for new business.' : undefined}
        onClick={(e) => e.stopPropagation()}
        onChange={(e) => {
          const stage = stages.find((s) => s.id === Number(e.target.value));
          if (!stage || stage.id === deal.stage_id) return;
          if (stage.kind === 'lost') setAskLost(stage);
          else move(stage);
        }}
        className="w-full rounded-lg border border-slate-300 bg-white py-1 pl-2 pr-7 text-xs text-slate-700 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {stages.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name}
          </option>
        ))}
      </select>
      {askLost && (
        <PromptModal
          title={`Mark ${deal.number} as lost`}
          message="Optionally record why the deal was lost (budget, competitor, no response…)."
          label="Reason (optional)"
          confirmLabel="Mark as lost"
          isLoading={busy}
          onSubmit={(reason) => move(askLost, reason)}
          onCancel={() => setAskLost(null)}
        />
      )}
    </>
  );
}
