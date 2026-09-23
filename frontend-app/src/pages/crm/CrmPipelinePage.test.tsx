import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmPipelinePage from './CrmPipelinePage';
import crmService from '../../services/crmService';
import { apiError, makeLead, makeTag, paginated, pipelineColumns } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 8. The Kanban is a view of GET /api/crm/pipeline:
 * the four canonical columns with server totals, server pagination per
 * column, the shared filters sent to the server, status moved only
 * through the dedicated status endpoint.
 */

vi.mock('../../services/crmService', () => ({
  default: { pipeline: vi.fn(), assignees: vi.fn(), listTags: vi.fn(), changeStatus: vi.fn() },
}));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => false }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function renderAt(url = '/crm/pipeline') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/pipeline" element={<CrmPipelinePage />} />
      </Routes>
    </MemoryRouter>,
  );
}

const newLead = makeLead({ id: 1, tags: [{ id: 3, name: 'Hot' }], assigned_user_id: 7, assigned_user: { id: 7, name: 'Asha' } });
const contacted = makeLead({ id: 2, status: 'contacted', source: 'whatsapp' });

beforeEach(() => {
  crm.pipeline.mockResolvedValue(pipelineColumns({ new: [newLead], contacted: [contacted] }, { new: 25, contacted: 1 }));
  crm.assignees.mockResolvedValue([{ id: 7, name: 'Asha' }]);
  crm.listTags.mockResolvedValue(paginated([makeTag({ id: 3, name: 'Hot' })]));
});

describe('pipeline', () => {
  it('renders exactly the four canonical columns, in order, with server counts', async () => {
    renderAt();
    await screen.findByTestId('crm-pipeline');

    const headings = within(screen.getByTestId('crm-pipeline')).getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(headings).toEqual(['New', 'Contacted', 'Converted', 'Not Converted']);
    expect(screen.getByTestId('crm-column-total-new')).toHaveTextContent('25');
    expect(screen.getByTestId('crm-column-total-contacted')).toHaveTextContent('1');
    expect(screen.getByTestId('crm-column-total-converted')).toHaveTextContent('0');
  });

  it('renders cards from the response with contact, source, assignee and tags', async () => {
    renderAt();
    const card = await screen.findByTestId('crm-card-1');

    expect(within(card).getByText('Contact 1')).toBeTruthy();
    expect(within(card).getByText('Asha')).toBeTruthy();
    expect(within(card).getByText('Hot')).toBeTruthy();
    expect(within(screen.getByTestId('crm-card-2')).getByText('WhatsApp')).toBeTruthy();
    expect(within(screen.getByTestId('crm-card-2')).getByText('Unassigned')).toBeTruthy();
  });

  it('shows empty columns rather than hiding them', async () => {
    renderAt();
    await screen.findByTestId('crm-pipeline');

    expect(within(screen.getByTestId('crm-column-converted')).getByText('No leads')).toBeTruthy();
    expect(within(screen.getByTestId('crm-column-not_converted')).getByText('No leads')).toBeTruthy();
  });

  it('shows an overall empty message when nothing matches', async () => {
    crm.pipeline.mockResolvedValue(pipelineColumns());
    renderAt('/crm/pipeline?source=api');

    expect(await screen.findByTestId('crm-pipeline-empty')).toHaveTextContent('No leads match these filters.');
  });

  it('loads the next page of ONE column from the server', async () => {
    const user = userEvent.setup();
    const more = makeLead({ id: 30 });
    crm.pipeline
      .mockResolvedValueOnce(pipelineColumns({ new: [newLead] }, { new: 25 }))
      .mockResolvedValueOnce(pipelineColumns({ new: [more] }, { new: 25 }, 2).filter((c) => c.status === 'new'));
    renderAt();
    await screen.findByTestId('crm-card-1');

    await user.click(screen.getByRole('button', { name: /load more/i }));

    expect(crm.pipeline).toHaveBeenLastCalledWith({ status: 'new' }, 2, 20);
    expect(await screen.findByTestId('crm-card-30')).toBeTruthy();
    expect(screen.getByTestId('crm-card-1')).toBeTruthy();
  });

  it('sends the shared filters (not status) to the server', async () => {
    renderAt('/crm/pipeline?q=ada&status=converted&source=whatsapp&assignee=7&tags=3');
    await screen.findByTestId('crm-pipeline');

    expect(crm.pipeline).toHaveBeenCalledWith({ search: 'ada', source: 'whatsapp', assigned_user_id: '7', tag_ids: [3] }, 1, 20);
    expect(screen.queryByRole('combobox', { name: /all statuses/i })).toBeNull();
  });

  it('changing a filter re-queries the server', async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByTestId('crm-pipeline');

    await user.selectOptions(screen.getByLabelText('Filter by assignee'), 'none');

    await waitFor(() => expect(crm.pipeline).toHaveBeenLastCalledWith({ assigned_user_id: 'none' }, 1, 20));
  });

  it('moves a card via the status endpoint and re-reads the pipeline', async () => {
    const user = userEvent.setup();
    crm.changeStatus.mockResolvedValue({ message: 'Lead status updated.', data: { ...newLead, status: 'converted' } });
    renderAt();
    const card = await screen.findByTestId('crm-card-1');

    await user.selectOptions(within(card).getByLabelText('Change status'), 'converted');

    expect(crm.changeStatus).toHaveBeenCalledWith(1, 'converted', undefined);
    await waitFor(() => expect(crm.pipeline).toHaveBeenCalledTimes(2));
  });

  it('shows the server error', async () => {
    crm.pipeline.mockRejectedValue(apiError(422, { message: 'The selected tag is not available.', errors: { tag_id: ['The selected tag is not available.'] } }));
    renderAt('/crm/pipeline?tags=999');

    expect(await screen.findByRole('alert')).toHaveTextContent('The selected tag is not available.');
  });
});
