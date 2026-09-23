import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import TemplateManagerPage from './TemplateManagerPage';
import templateService from '../../services/templateService';
import accountService from '../../services/accountService';
import type { MessageTemplate, SaveMessageTemplatePayload } from '../../types/templates';

/**
 * Phase 4 Task 8 -- Meta Template Component Configuration UI.
 *
 * Task 7 shipped the `component` / `button_sub_type` / `button_index`
 * schema markers but left them API-only. These tests cover the
 * configuration controls added to the EXISTING Variable Configurator
 * inside TemplateManagerPage's TemplateModal -- no second editor was
 * built -- and the three guarantees that surround them:
 *
 *   1. the controls are Meta-only: a QR template's configurator, and the
 *      payload it produces, are exactly what they were before;
 *   2. a schema written before Task 7 (no `component` key at all) reads
 *      as Body and is saved back WITHOUT the key being invented;
 *   3. every structural rule is enforced client-side before a request is
 *      made, and the backend's own 422 still surfaces when it isn't.
 *
 * templateService/accountService are mocked because they are the seams
 * this page talks to; backend errors are rejected with the plain
 * {response:{status,data}} shape an AxiosError carries, which is what
 * extractErrorMessage() reads.
 */

vi.mock('../../services/templateService', () => ({
  default: {
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
    remove: vi.fn(),
    test: vi.fn(),
  },
}));

vi.mock('../../services/accountService', () => ({
  default: {
    list: vi.fn(),
  },
}));

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Super Admin', account: null },
    isSuperAdmin: () => true,
  }),
}));

const templates = templateService as unknown as {
  list: Mock;
  create: Mock;
  update: Mock;
  approve: Mock;
  reject: Mock;
  remove: Mock;
  test: Mock;
};

const accounts = accountService as unknown as { list: Mock };

const META_ACCOUNT = { id: 7, company_name: 'Meta Co' };

/** A template stored BEFORE Task 7: its schema carries no `component` key. */
function legacyTemplate(overrides: Partial<MessageTemplate> = {}): MessageTemplate {
  return {
    id: 11,
    template_code: 'ORDER_UPDATE',
    account_id: META_ACCOUNT.id,
    account: { id: META_ACCOUNT.id, company_name: META_ACCOUNT.company_name },
    industry_type: null,
    title: 'Order Update',
    template_body: 'Hello {{name}}, order {{order_id}} shipped.',
    variables_schema: [
      { key: 'name', label: 'Name', type: 'string', required: true },
      { key: 'order_id', label: 'Order', type: 'string', required: true },
    ],
    status: 'approved',
    is_super_admin_tested: true,
    tested_at: null,
    rejection_reason: null,
    header_type: 'text',
    language: 'en_US',
    category: 'UTILITY',
    meta_template_name: 'order_update',
    meta_template_status: 'APPROVED',
    header_media_url: null,
    creator: null,
    created_at: '2026-09-01T00:00:00Z',
    updated_at: '2026-09-01T00:00:00Z',
    ...overrides,
  };
}

/**
 * fireEvent.change rather than userEvent.type: the template body contains
 * `{{token}}`, and userEvent treats `{` as the start of a key descriptor.
 */
function setValue(element: HTMLElement, value: string): void {
  fireEvent.change(element, { target: { value } });
}

function titleInput(): HTMLElement {
  return screen.getByPlaceholderText('e.g. Dose Reminder');
}

function bodyInput(): HTMLElement {
  return screen.getByPlaceholderText(/your dose \{\{dose_name\}\}/);
}

function metaNameInput(): HTMLElement {
  return screen.getByLabelText('Meta template name');
}

/** Opens "New Template" and fills the minimum a Meta template needs. */
async function openNewMetaTemplate(body = 'Hello {{name}}.'): Promise<ReturnType<typeof userEvent.setup>> {
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: /New Template/i }));
  setValue(titleInput(), 'Order Update');
  setValue(bodyInput(), body);
  setValue(screen.getByLabelText('Language'), 'en_US');
  setValue(metaNameInput(), 'order_update');
  return user;
}

function submittedPayload(): SaveMessageTemplatePayload {
  expect(templates.create).toHaveBeenCalledTimes(1);
  return templates.create.mock.calls[0][0] as SaveMessageTemplatePayload;
}

function fieldNamed(payload: SaveMessageTemplatePayload, key: string) {
  const field = payload.variables_schema?.find((f) => f.key === key);
  expect(field).toBeDefined();
  return field!;
}

/** A rejected promise shaped exactly like an AxiosError from Laravel. */
function apiError(status: number, data: unknown) {
  return Object.assign(new Error('Request failed'), { response: { status, data } });
}

beforeEach(() => {
  vi.clearAllMocks();
  templates.list.mockResolvedValue([]);
  accounts.list.mockResolvedValue({ data: [META_ACCOUNT] });
  templates.create.mockResolvedValue({ message: 'Created.', data: legacyTemplate() });
  templates.update.mockResolvedValue({ message: 'Updated.', data: legacyTemplate() });
});

async function renderPage() {
  render(
    <MemoryRouter>
      <TemplateManagerPage />
    </MemoryRouter>,
  );
  await waitFor(() => expect(templates.list).toHaveBeenCalled());
}

// ====================================================================
// 1. Existing body variable / existing architecture reused
// ====================================================================
describe('existing variable configurator', () => {
  it('still renders one configurator row per {{token}}, with no Meta controls on a QR template', async () => {
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /New Template/i }));
    setValue(titleInput(), 'Dose Reminder');
    setValue(bodyInput(), 'Hi {{name}}, dose {{dose_name}}.');

    expect(screen.getByText('Variable Configurator')).toBeInTheDocument();
    expect(screen.getByText('{{name}}')).toBeInTheDocument();
    expect(screen.getByText('{{dose_name}}')).toBeInTheDocument();
    // The pre-existing controls are untouched.
    expect(screen.getAllByText('Field Label')).toHaveLength(2);
    expect(screen.getAllByText('Input Type')).toHaveLength(2);
    // ...and nothing Meta-specific is offered without a Meta template name.
    expect(screen.queryByLabelText('Meta component')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Button type')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Button index')).not.toBeInTheDocument();
  });

  it('a QR template is saved with no component keys at all', async () => {
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /New Template/i }));
    setValue(titleInput(), 'Dose Reminder');
    setValue(bodyInput(), 'Hi {{name}}.');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const payload = submittedPayload();
    expect(payload.meta_template_name).toBeNull();
    expect(payload.variables_schema).toEqual([
      { key: 'name', label: 'Name', type: 'string', required: true },
    ]);
  });
});

// ====================================================================
// 2. Component selector
// ====================================================================
describe('component selector', () => {
  it('appears once a Meta template name is set', async () => {
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /New Template/i }));
    setValue(titleInput(), 'Order Update');
    setValue(bodyInput(), 'Hello {{name}}.');

    expect(screen.queryByLabelText('Meta component')).not.toBeInTheDocument();

    setValue(metaNameInput(), 'order_update');

    expect(screen.getByLabelText('Meta component')).toBeInTheDocument();
  });

  it('offers Body, Header (text) and Button — and never Footer', async () => {
    await renderPage();
    await openNewMetaTemplate();

    const select = screen.getByLabelText('Meta component');
    const values = Array.from(select.querySelectorAll('option')).map((o) => o.value);

    expect(values).toEqual(['body', 'header', 'button']);
    expect(values).not.toContain('footer');
    expect(within(select).queryByText(/footer/i)).not.toBeInTheDocument();
  });

  it('defaults a field with no component key to Body', async () => {
    templates.list.mockResolvedValue([legacyTemplate()]);
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));

    const selects = screen.getAllByLabelText('Meta component') as HTMLSelectElement[];
    expect(selects).toHaveLength(2);
    expect(selects[0].value).toBe('body');
    expect(selects[1].value).toBe('body');
  });
});

// ====================================================================
// 3. Header text
// ====================================================================
describe('header text parameter', () => {
  it('saves the header component on the chosen variable only', async () => {
    await renderPage();
    const user = await openNewMetaTemplate('Hello {{name}}, order {{order_id}}.');

    const selects = screen.getAllByLabelText('Meta component');
    await user.selectOptions(selects[0], 'header');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const payload = submittedPayload();
    expect(fieldNamed(payload, 'name').component).toBe('header');
    expect(fieldNamed(payload, 'order_id')).not.toHaveProperty('component');
  });

  it('blocks a second header parameter before calling the API', async () => {
    await renderPage();
    const user = await openNewMetaTemplate('Hello {{name}}, order {{order_id}}.');

    const selects = screen.getAllByLabelText('Meta component');
    await user.selectOptions(selects[0], 'header');
    await user.selectOptions(selects[1], 'header');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/at most one header parameter/i)).toBeInTheDocument();
    expect(templates.create).not.toHaveBeenCalled();
  });

  it('blocks a text header on a template that already uses a media header', async () => {
    await renderPage();
    const user = await openNewMetaTemplate('Hello {{name}}.');

    await user.click(screen.getByRole('button', { name: /^Image/ }));
    await user.selectOptions(screen.getByLabelText('Meta component'), 'header');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/either media or text, never both/i)).toBeInTheDocument();
    expect(templates.create).not.toHaveBeenCalled();
  });
});

// ====================================================================
// 4. Button URL / quick reply / index
// ====================================================================
describe('button parameters', () => {
  it('reveals the button type and index controls and defaults them to URL / 0', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    expect(screen.queryByLabelText('Button type')).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');

    expect((screen.getByLabelText('Button type') as HTMLSelectElement).value).toBe('url');
    expect((screen.getByLabelText('Button index') as HTMLInputElement).value).toBe('0');
  });

  it('saves a URL button parameter', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(fieldNamed(submittedPayload(), 'name')).toMatchObject({
      component: 'button',
      button_sub_type: 'url',
      button_index: 0,
    });
  });

  it('saves a quick-reply button parameter', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    await user.selectOptions(screen.getByLabelText('Button type'), 'quick_reply');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(fieldNamed(submittedPayload(), 'name').button_sub_type).toBe('quick_reply');
  });

  it('saves an operator-chosen button index as a number', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    setValue(screen.getByLabelText('Button index'), '2');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(fieldNamed(submittedPayload(), 'name').button_index).toBe(2);
  });

  it('gives a second button the next free index instead of a duplicate', async () => {
    await renderPage();
    const user = await openNewMetaTemplate('Hello {{name}}, order {{order_id}}.');

    const selects = screen.getAllByLabelText('Meta component');
    await user.selectOptions(selects[0], 'button');
    await user.selectOptions(selects[1], 'button');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const payload = submittedPayload();
    expect(fieldNamed(payload, 'name').button_index).toBe(0);
    expect(fieldNamed(payload, 'order_id').button_index).toBe(1);
  });

  it('strips button metadata when the component is switched back to Body', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    const select = screen.getByLabelText('Meta component');
    await user.selectOptions(select, 'button');
    setValue(screen.getByLabelText('Button index'), '3');
    await user.selectOptions(select, 'body');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const field = fieldNamed(submittedPayload(), 'name');
    expect(field).not.toHaveProperty('component');
    expect(field).not.toHaveProperty('button_sub_type');
    expect(field).not.toHaveProperty('button_index');
  });

  it('strips button metadata when the component is switched to Header', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    const select = screen.getByLabelText('Meta component');
    await user.selectOptions(select, 'button');
    await user.selectOptions(select, 'header');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const field = fieldNamed(submittedPayload(), 'name');
    expect(field.component).toBe('header');
    expect(field).not.toHaveProperty('button_sub_type');
    expect(field).not.toHaveProperty('button_index');
  });
});

// ====================================================================
// 5. Invalid UI submissions
// ====================================================================
describe('client-side structural validation', () => {
  it('blocks a duplicate button index before calling the API', async () => {
    await renderPage();
    const user = await openNewMetaTemplate('Hello {{name}}, order {{order_id}}.');

    const selects = screen.getAllByLabelText('Meta component');
    await user.selectOptions(selects[0], 'button');
    await user.selectOptions(selects[1], 'button');
    const indexes = screen.getAllByLabelText('Button index');
    setValue(indexes[1], '0');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/Button index 0 is used by more than one variable/i)).toBeInTheDocument();
    expect(templates.create).not.toHaveBeenCalled();
  });

  it('blocks a button parameter whose index was cleared', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    setValue(screen.getByLabelText('Button index'), '');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/set its button index/i)).toBeInTheDocument();
    expect(templates.create).not.toHaveBeenCalled();
  });

  it('blocks an out-of-range button index', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    setValue(screen.getByLabelText('Button index'), '11');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/invalid button index/i)).toBeInTheDocument();
    expect(templates.create).not.toHaveBeenCalled();
  });
});

// ====================================================================
// 6. Backend 422s still surface
// ====================================================================
describe('backend validation errors', () => {
  it('surfaces a field-level 422 on an invalid component', async () => {
    templates.create.mockRejectedValue(
      apiError(422, {
        message: 'The given data was invalid.',
        errors: { 'variables_schema.0.component': ['The selected variables_schema.0.component is invalid.'] },
      }),
    );
    await renderPage();
    const user = await openNewMetaTemplate();
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(
      await screen.findByText(/The selected variables_schema.0.component is invalid./i),
    ).toBeInTheDocument();
  });

  it("surfaces the backend's duplicate-button-index 422 message", async () => {
    templates.create.mockRejectedValue(
      apiError(422, { message: 'Button index 0 is already used by another variable.' }),
    );
    await renderPage();
    const user = await openNewMetaTemplate();
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/Button index 0 is already used by another variable./i)).toBeInTheDocument();
  });

  it("surfaces the backend's missing-button-type 422 message", async () => {
    templates.create.mockRejectedValue(
      apiError(422, { message: '"Tracking" is a button parameter, so it needs a button type (URL or Quick Reply).' }),
    );
    await renderPage();
    const user = await openNewMetaTemplate();
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    expect(await screen.findByText(/needs a button type/i)).toBeInTheDocument();
  });
});

// ====================================================================
// 7. Backward compatibility with pre-Task-7 templates
// ====================================================================
describe('existing template backward compatibility', () => {
  it('saves an untouched legacy schema back without inventing a component key', async () => {
    templates.list.mockResolvedValue([legacyTemplate()]);
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await user.click(screen.getByRole('button', { name: 'Save Changes' }));

    await waitFor(() => expect(templates.update).toHaveBeenCalledTimes(1));
    const payload = templates.update.mock.calls[0][1] as SaveMessageTemplatePayload;

    expect(payload.variables_schema).toEqual([
      { key: 'name', label: 'Name', type: 'string', required: true },
      { key: 'order_id', label: 'Order', type: 'string', required: true },
    ]);
  });

  it('round-trips component markers already stored on a template', async () => {
    templates.list.mockResolvedValue([
      legacyTemplate({
        variables_schema: [
          { key: 'name', label: 'Name', type: 'string', required: true },
          {
            key: 'order_id',
            label: 'Order',
            type: 'string',
            required: true,
            component: 'button',
            button_sub_type: 'quick_reply',
            button_index: 2,
          },
        ],
      }),
    ]);
    await renderPage();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));

    const selects = screen.getAllByLabelText('Meta component') as HTMLSelectElement[];
    expect(selects[1].value).toBe('button');
    expect((screen.getByLabelText('Button type') as HTMLSelectElement).value).toBe('quick_reply');
    expect((screen.getByLabelText('Button index') as HTMLInputElement).value).toBe('2');

    await user.click(screen.getByRole('button', { name: 'Save Changes' }));

    await waitFor(() => expect(templates.update).toHaveBeenCalledTimes(1));
    const payload = templates.update.mock.calls[0][1] as SaveMessageTemplatePayload;
    expect(payload.variables_schema?.[1]).toMatchObject({
      component: 'button',
      button_sub_type: 'quick_reply',
      button_index: 2,
    });
  });

  it('reuses the existing header type / media URL controls for a media header', async () => {
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.click(screen.getByRole('button', { name: /^Document/ }));
    setValue(screen.getByPlaceholderText('https://example.com/files/invoice.pdf'), 'https://cdn.example.com/a.pdf');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const payload = submittedPayload();
    expect(payload.header_type).toBe('document');
    expect(payload.header_media_url).toBe('https://cdn.example.com/a.pdf');
    // A media header consumes no schema variable.
    expect(fieldNamed(payload, 'name')).not.toHaveProperty('component');
  });
});

// ====================================================================
// 8. Credential hygiene
// ====================================================================
describe('credential hygiene', () => {
  it('never renders or submits any Meta credential', async () => {
    const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    await renderPage();
    const user = await openNewMetaTemplate();

    await user.selectOptions(screen.getByLabelText('Meta component'), 'button');
    await user.click(screen.getByRole('button', { name: 'Create Template' }));

    const payload = submittedPayload();
    const serialized = JSON.stringify(payload);
    for (const forbidden of [
      'meta_access_token',
      'meta_waba_id',
      'meta_phone_number_id',
      'meta_webhook_verify_token',
      'app_secret',
    ]) {
      expect(serialized).not.toContain(forbidden);
      expect(document.body.textContent).not.toContain(forbidden);
    }

    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(consoleSpy).not.toHaveBeenCalled();
  });
});
