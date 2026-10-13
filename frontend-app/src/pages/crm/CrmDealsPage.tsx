import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { SearchInput } from '../../components/common/DataTableControls';
import DealFormModal from '../../components/crm/DealFormModal';
import DealStageControl from '../../components/crm/DealStageControl';
import StageManagerModal from '../../components/crm/StageManagerModal';
import { ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Spinner, Toast } from '../../components/crm/CrmUi';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { formatMoney, type CrmBoardColumn, type CrmStage } from '../../types/crm';

const KIND_ACCENT: Record<CrmStage['kind'], string> = {
  open: 'border-t-slate-300',
  won: 'border-t-emerald-500',
  lost: 'border-t-slate-400',
};

/**
 * CRM Sales Phase 1 — the deal pipeline (GET /api/crm/deals/board): one column per
 * stage with its total value. A deal moves with the stage selector on its card
 * (PATCH /crm/deals/{id}/stage), which keeps won / lost bookkeeping server-side.
 */
export default function CrmDealsPage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [search, setSearch] = useState('');
  const [version, setVersion] = useState(0);
  const [creating, setCreating] = useState(false);
  const [editingStages, setEditingStages] = useState(false);

  const board = useCrmQuery(
    needsClient ? null : `deals-board:${selectedAccountId ?? 'own'}:${search}:${version}`,
    () => crmSalesService.board(search),
    'Failed to load the pipeline.',
  );
  const columns: CrmBoardColumn[] = board.data ?? [];
  const stages: CrmStage[] = columns.map(({ id, name, position, probability, kind }) => ({ id, name, position, probability, kind }));
  const reload = (message?: string) => {
    setVersion((v) => v + 1);
    if (message) show(message);
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">CRM Deals</h1>
            <p className="mt-1 text-sm text-slate-500">Every sale in progress, by stage, with its value.</p>
          </div>
          {!needsClient && (
            <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setEditingStages(true)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            >
              Edit stages
            </button>
            <button
              type="button"
              onClick={() => setCreating(true)}
              disabled={readOnly || stages.length === 0}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Plus className="h-4 w-4" />
              New deal
            </button>
            </div>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM deals" />
        ) : (
          <>
            <SearchInput value={search} onChange={setSearch} placeholder="Search deal name or number…" />
            {board.error && <ErrorBanner message={board.error} />}
            {board.isLoading && columns.length === 0 ? (
              <Spinner />
            ) : (
              <div className="flex gap-4 overflow-x-auto pb-2" data-testid="crm-deals-board">
                {columns.map((col) => (
                  <section key={col.id} className={`flex w-72 flex-shrink-0 flex-col rounded-xl border border-t-4 border-slate-200 bg-slate-50 ${KIND_ACCENT[col.kind]}`} aria-label={col.name}>
                    <header className="border-b border-slate-200 px-3 py-2">
                      <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-slate-900">{col.name}</h2>
                        <span className="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-600">{col.deals_count}</span>
                      </div>
                      <p className="text-xs text-slate-500">
                        {formatMoney(col.total_value)}
                        {col.kind === 'open' && ` · ${col.probability}%`}
                      </p>
                    </header>
                    <div className="flex-1 space-y-2 p-2">
                      {col.deals.length === 0 ? (
                        <p className="px-1 py-4 text-center text-xs text-slate-400">No deals</p>
                      ) : (
                        col.deals.map((d) => (
                          <article key={d.id} className="space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm" data-testid={`crm-deal-card-${d.id}`}>
                            <Link to={`/crm/deals/${d.id}`} className="block">
                              <p className="text-xs font-medium text-slate-500">{d.number}</p>
                              <p className="text-sm font-semibold text-slate-900 hover:text-emerald-700">{d.name}</p>
                            </Link>
                            <p className="text-sm font-medium text-slate-800">{formatMoney(d.value, d.currency)}</p>
                            {(d.company || d.contact) && (
                              <p className="truncate text-xs text-slate-500">{d.company?.name ?? d.contact?.name ?? d.contact?.phone_number}</p>
                            )}
                            {d.expected_close_date && <p className="text-xs text-slate-500">Close by {d.expected_close_date}</p>}
                            <DealStageControl deal={d} stages={stages} disabled={readOnly} onMoved={(_, m) => reload(m)} onError={show} />
                          </article>
                        ))
                      )}
                      {col.deals_count > col.deals.length && (
                        <p className="px-1 text-center text-xs text-slate-500">Showing the newest {col.deals.length} of {col.deals_count}. Search to find others.</p>
                      )}
                    </div>
                  </section>
                ))}
              </div>
            )}
          </>
        )}
      </div>

      {creating && (
        <DealFormModal
          stages={stages}
          onCancel={() => setCreating(false)}
          onSaved={(_, message) => {
            setCreating(false);
            reload(message);
          }}
        />
      )}
      {editingStages && (
        <StageManagerModal
          onClose={(changed) => {
            setEditingStages(false);
            if (changed) reload('Stages updated.');
          }}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}
