import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import StageManagerModal from '../../../components/crm/StageManagerModal';
import ContactConsentCard from '../../../components/crm/sales/ContactConsentCard';
import LinkedDocumentsCard from '../../../components/crm/sales/LinkedDocumentsCard';
import crmService from '../../../services/crmService';
import crmSalesService from '../../../services/crmSalesService';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { paginated } from '../../../test/crmFixtures';

/** CRM Sales verification follow-up: stage editing, contact consent, documents on a deal / company. */

vi.mock('../../../services/crmService', () => ({ default: { updateContact: vi.fn() } }));
vi.mock('../../../services/crmSalesService', () => ({
  default: { listStages: vi.fn(), reorderStages: vi.fn(), updateStage: vi.fn(), createStage: vi.fn(), deleteStage: vi.fn(), listCompanies: vi.fn() },
}));
vi.mock('../../../services/crmDocumentsService', () => ({ default: { listDocuments: vi.fn() } }));
const perms = { docs: true };
vi.mock('../../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, hasPermission: (p: string) => p !== 'manage-crm-documents' || perms.docs }),
}));

const crm = crmService as unknown as Record<string, Mock>;
const sales = crmSalesService as unknown as Record<string, Mock>;
const docs = crmDocumentsService as unknown as Record<string, Mock>;

const stages = [
  { id: 1, name: 'Qualification', position: 1, probability: 10, kind: 'open' },
  { id: 2, name: 'Proposal', position: 2, probability: 50, kind: 'open' },
  { id: 3, name: 'Won', position: 3, probability: 100, kind: 'won' },
  { id: 4, name: 'Lost', position: 4, probability: 0, kind: 'lost' },
];

beforeEach(() => {
  vi.clearAllMocks();
  perms.docs = true;
  sales.listStages.mockResolvedValue(stages);
  sales.listCompanies.mockResolvedValue(paginated([{ id: 9, name: 'Acme' }]));
  docs.listDocuments.mockResolvedValue(paginated([]));
});

describe('stage manager', () => {
  it('reorders by sending every stage id in the new order, and shows a refused change', async () => {
    sales.reorderStages.mockResolvedValue({ message: 'ok', data: stages });
    sales.deleteStage.mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { message: 'The pipeline needs at least one won stage.' } } });
    const user = userEvent.setup();
    render(<StageManagerModal onClose={() => undefined} />);

    await user.click(await screen.findByRole('button', { name: 'Move Proposal up' }));
    await waitFor(() => expect(sales.reorderStages).toHaveBeenCalledWith([2, 1, 3, 4]));

    await user.click(await screen.findByRole('button', { name: 'Delete Won' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/at least one won stage/i);
  });
});

describe('contact consent', () => {
  it('saves the company, role and opt-out', async () => {
    crm.updateContact.mockResolvedValue({ message: 'ok', data: { id: 3, name: 'Rahul', phone_number: '91', email: null, crm_leads_count: 1, created_at: null, updated_at: null, opted_out_at: '2026-10-14T00:00:00Z' } });
    const onSaved = vi.fn();
    const user = userEvent.setup();
    render(<ContactConsentCard contact={{ id: 3, name: 'Rahul', phone_number: '91', email: null, crm_leads_count: 1, created_at: null, updated_at: null }} readOnly={false} onSaved={onSaved} />);

    await screen.findByRole('option', { name: 'Acme' });
    await user.selectOptions(screen.getByLabelText('Company'), '9');
    await user.type(screen.getByLabelText('Designation'), 'Owner');
    await user.click(screen.getByLabelText('Opted out of all messages'));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(crm.updateContact).toHaveBeenCalledWith(3, { crm_company_id: 9, designation: 'Owner', preferred_channel: null, marketing_opt_in: false, opted_out: true }));
    expect(onSaved).toHaveBeenCalled();
  });
});

describe('documents on a deal', () => {
  it('lists the deal documents and links new ones pre-filled with the deal, contact and company', async () => {
    docs.listDocuments.mockResolvedValue(paginated([{ id: 7, type: 'quotation', status: 'sent', number: 'QUO/2026-27/0001', grand_total: '1180.00', is_overdue: false }]));
    render(
      <MemoryRouter>
        <LinkedDocumentsCard dealId={5} companyId={9} contactId={3} />
      </MemoryRouter>,
    );

    expect(await screen.findByText(/QUO\/2026-27\/0001/)).toBeInTheDocument();
    expect(docs.listDocuments).toHaveBeenCalledWith({ crm_deal_id: 5, per_page: 50 });
    const link = screen.getByRole('link', { name: 'Quotation' });
    expect(link.getAttribute('href')).toBe('/crm/documents/new?type=quotation&crm_deal_id=5&crm_company_id=9&contact_id=3');
  });

  it('is hidden without the documents permission', () => {
    perms.docs = false;
    const { container } = render(
      <MemoryRouter>
        <LinkedDocumentsCard dealId={5} />
      </MemoryRouter>,
    );
    expect(container.textContent).toBe('');
    expect(docs.listDocuments).not.toHaveBeenCalled();
    void within;
  });
});
