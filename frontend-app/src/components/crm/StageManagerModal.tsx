import { useState } from 'react';
import { ArrowDown, ArrowUp, Loader2, Plus, Trash2, XCircle } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { useCrmQuery } from './crmHooks';
import { describeApiError } from '../../utils/apiError';
import type { CrmStage, CrmStageKind } from '../../types/crm';

const cell = 'rounded-md border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-emerald-500';

/**
 * CRM Sales Phase 1 — edit the deal pipeline: rename, probability, kind (open / won /
 * lost), add, delete and reorder. The server refuses what would break the pipeline
 * (deleting a stage that holds deals, removing the last won or lost stage) and that
 * message is shown as-is.
 */
export default function StageManagerModal({ onClose }: { onClose: (changed: boolean) => void }) {
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [changed, setChanged] = useState(false);
  const [newName, setNewName] = useState('');
  const query = useCrmQuery(`stage-manager:${version}`, () => crmSalesService.listStages(), 'Failed to load stages.');
  const stages = query.data ?? [];

  const run = (p: Promise<unknown>) => {
    setBusy(true);
    setError(null);
    p.then(() => {
      setChanged(true);
      setVersion((v) => v + 1);
    })
      .catch((e: unknown) => setError(describeApiError(e, 'That change was not allowed.').message))
      .finally(() => setBusy(false));
  };

  const move = (index: number, delta: number) => {
    const ids = stages.map((s) => s.id);
    const [moved] = ids.splice(index, 1);
    ids.splice(index + delta, 0, moved);
    run(crmSalesService.reorderStages(ids));
  };

  const save = (stage: CrmStage, patch: Partial<Pick<CrmStage, 'name' | 'probability' | 'kind'>>) => run(crmSalesService.updateStage(stage.id, patch));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="stages-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="crm-stage-manager">
        <div className="flex items-center justify-between">
          <h3 id="stages-title" className="text-base font-semibold text-slate-900">
            Deal stages
          </h3>
          <button type="button" onClick={() => onClose(changed)} aria-label="Close" className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-xs text-slate-500">A deal in a "won" or "lost" stage is marked won or lost. Keep at least one stage of each kind.</p>
        {error && <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}

        <ul className="mt-4 space-y-2">
          {stages.map((s, i) => (
            <li key={`${s.id}:${version}`} className="flex flex-wrap items-center gap-2">
              <input aria-label={`Stage ${i + 1} name`} defaultValue={s.name} maxLength={100} onBlur={(e) => e.target.value.trim() && e.target.value.trim() !== s.name && save(s, { name: e.target.value.trim() })} className={`${cell} min-w-0 flex-1`} />
              <input aria-label={`Stage ${i + 1} probability`} type="number" min={0} max={100} defaultValue={s.probability} onBlur={(e) => Number(e.target.value) !== s.probability && save(s, { probability: Math.max(0, Math.min(100, Number(e.target.value))) })} className={`${cell} w-20`} />
              <select aria-label={`Stage ${i + 1} kind`} value={s.kind} onChange={(e) => save(s, { kind: e.target.value as CrmStageKind })} className={cell}>
                <option value="open">Open</option>
                <option value="won">Won</option>
                <option value="lost">Lost</option>
              </select>
              <button type="button" onClick={() => move(i, -1)} disabled={busy || i === 0} aria-label={`Move ${s.name} up`} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30">
                <ArrowUp className="h-4 w-4" />
              </button>
              <button type="button" onClick={() => move(i, 1)} disabled={busy || i === stages.length - 1} aria-label={`Move ${s.name} down`} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30">
                <ArrowDown className="h-4 w-4" />
              </button>
              <button type="button" onClick={() => run(crmSalesService.deleteStage(s.id))} disabled={busy} aria-label={`Delete ${s.name}`} className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30">
                <Trash2 className="h-4 w-4" />
              </button>
            </li>
          ))}
        </ul>

        <div className="mt-4 flex gap-2">
          <input value={newName} onChange={(e) => setNewName(e.target.value)} maxLength={100} placeholder="New stage name" aria-label="New stage name" className={`${cell} flex-1`} />
          <button
            type="button"
            disabled={busy || !newName.trim()}
            onClick={() => {
              run(crmSalesService.createStage({ name: newName.trim(), kind: 'open' }));
              setNewName('');
            }}
            className="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white disabled:bg-slate-300"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />} Add stage
          </button>
        </div>
      </div>
    </div>
  );
}
