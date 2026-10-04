import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import TemplateManagerPage from './TemplateManagerPage';
import templateService from '../../services/templateService';
import accountService from '../../services/accountService';
import type { MessageTemplate } from '../../types/templates';

vi.mock('../../services/templateService', () => ({
  default: { list: vi.fn(), create: vi.fn(), update: vi.fn(), approve: vi.fn(), reject: vi.fn(), remove: vi.fn(), test: vi.fn() },
}));
vi.mock('../../services/accountService', () => ({ default: { list: vi.fn() } }));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 5, name: 'Agent', account: { id: 10, account_type: 'agent', company_name: 'Reseller' } },
    isSuperAdmin: () => false,
  }),
}));

const templates = templateService as unknown as { list: Mock };
const accounts = accountService as unknown as { list: Mock };

const tpl = (id: number, title: string, account_id: number | null): MessageTemplate =>
  ({
    id, template_code: `T_${id}`, account_id, is_global: account_id === null, account: account_id ? { id: account_id, company_name: 'Sub Client' } : null,
    industry_type: null, title, template_body: 'Hello {{name}}.', variables_schema: null, status: 'approved', is_super_admin_tested: true, tested_at: null,
    rejection_reason: null, header_type: 'text', language: null, category: null, meta_template_name: null, meta_template_status: null, header_media_url: null, creator: null,
  }) as unknown as MessageTemplate;

beforeEach(() => {
  vi.clearAllMocks();
  accounts.list.mockResolvedValue({ data: [], current_page: 1, last_page: 1, total: 0 });
  templates.list.mockResolvedValue([tpl(1, 'Global Welcome', null), tpl(2, 'Sub Client Reminder', 11)]);
});

describe('TemplateManagerPage as an Agent with Global Templates', () => {
  it('lists global and own-tree templates together; global is read-only, own stays editable', async () => {
    render(<MemoryRouter><TemplateManagerPage /></MemoryRouter>);

    const globalRow = (await screen.findByText('Global Welcome')).closest('tr') as HTMLElement;
    const ownRow = screen.getByText('Sub Client Reminder').closest('tr') as HTMLElement;

    expect(within(globalRow).getByText('Global (every client)')).toBeInTheDocument();
    expect(within(globalRow).getByText('Read-only')).toBeInTheDocument();
    expect(within(globalRow).queryByRole('button', { name: /edit/i })).not.toBeInTheDocument();
    expect(within(globalRow).queryByRole('button', { name: /delete|approve|reject/i })).not.toBeInTheDocument();
    expect(within(ownRow).getByRole('button', { name: /edit/i })).toBeInTheDocument();
  });
});
