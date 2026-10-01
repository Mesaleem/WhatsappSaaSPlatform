import educationService from '../../services/educationService';
import { useCrmQuery } from '../crm/crmHooks';
import FeeItemsPanel from './FeeItemsPanel';
import StudentFeesPanel from './StudentFeesPanel';

/**
 * Phase 11 Task 4 — the Fees tab: the fee catalogue and one student's charges / payments. Education's face of
 * the generic Billing & Collections core; every number shown is computed by the server.
 */
export default function FeesTab({
  accountKey,
  canManage,
  readOnly,
  onToast,
}: {
  accountKey: number | null;
  canManage: boolean;
  readOnly: boolean;
  onToast: (message: string) => void;
}) {
  // One read of the catalogue per client: the list shows it and the assignment form picks from it.
  const items = useCrmQuery(`fee-items:${accountKey ?? 'own'}`, () => educationService.listFeeItems({}, 1, 100), 'Failed to load fees.');

  return (
    <div className="space-y-8" data-testid="fees-tab">
      <FeeItemsPanel
        items={items.data?.data ?? []}
        isLoading={items.isLoading}
        error={items.error}
        canManage={canManage}
        readOnly={readOnly}
        onChanged={items.reload}
        onToast={onToast}
      />
      <StudentFeesPanel accountKey={accountKey} items={items.data?.data ?? []} canManage={canManage} readOnly={readOnly} onToast={onToast} />
    </div>
  );
}
