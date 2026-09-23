import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import DeveloperPage from './DeveloperPage';
import developerService from '../../services/developerService';
import type { ApiKey } from '../../types/developer';

/**
 * Developer API Key Management UI — API-key tab coverage.
 *
 * The service layer is mocked (not axios) because that is the seam this
 * page actually talks to: every call goes through developerService, and
 * mocking it keeps these tests about the UI's own behaviour — validation,
 * one-time reveal, confirmation, status mapping, credential hygiene —
 * rather than about axios configuration.
 *
 * Error cases are rejected with the plain `{response: {status, data,
 * headers}}` shape an AxiosError carries, which is exactly what
 * describeApiError() reads, so no axios instance is needed to exercise
 * the 401/403/404/409/422/429/5xx branches.
 */

vi.mock('../../services/developerService', () => ({
  default: {
    listApiKeys: vi.fn(),
    createApiKey: vi.fn(),
    revokeApiKey: vi.fn(),
    regenerateApiKeySecret: vi.fn(),
    listWebhooks: vi.fn(),
    createWebhook: vi.fn(),
    deleteWebhook: vi.fn(),
    testWebhook: vi.fn(),
    getDeliveries: vi.fn(),
  },
}));

vi.mock('../../services/accountService', () => ({
  default: { list: vi.fn().mockResolvedValue({ data: [] }) },
}));

// The page reads only isSuperAdmin() from auth; a tenant Admin (false) is
// the default subject here, since that is who the spec's UI is for.
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false }),
}));

const service = developerService as unknown as {
  listApiKeys: Mock;
  createApiKey: Mock;
  revokeApiKey: Mock;
  regenerateApiKeySecret: Mock;
  listWebhooks: Mock;
};

const PLAIN_KEY = 'wasaas_live_PLAINTEXTKEY0000000000000000000000000000';
const PLAIN_SECRET = 'wasaas_secret_PLAINTEXTSECRET00000000000000000000000000';

function makeKey(overrides: Partial<ApiKey> = {}): ApiKey {
  return {
    id: 1,
    account_id: 7,
    name: 'Production integration',
    key_prefix: 'wasaas_live_a1b2c3',
    secret_prefix: 'wasaas_secret_a1b2c',
    last_used_at: '2026-09-20T10:00:00.000000Z',
    expires_at: null,
    revoked_at: null,
    created_at: '2026-09-18T09:00:00.000000Z',
    updated_at: '2026-09-18T09:00:00.000000Z',
    ...overrides,
  };
}

/** An AxiosError-shaped rejection. */
function httpError(status: number, data: unknown = {}, headers: Record<string, string> = {}) {
  return { response: { status, data, headers }, isAxiosError: true };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <DeveloperPage />
    </MemoryRouter>,
  );
}

async function openCreateModal(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: /create api key/i }));
  return screen.findByRole('heading', { name: 'Create API key' });
}

/**
 * Status assertions are scoped to the table: the status FILTER is a
 * <select> whose options are also literally "Active"/"Expired"/"Revoked",
 * so an unscoped getByText matches both the filter option and the badge.
 */
function table() {
  return within(screen.getByRole('table'));
}

/**
 * jsdom exposes navigator.clipboard as a getter-only property, and
 * userEvent.setup() installs its own stub over it — so a writeText spy
 * has to be defined (not assigned) and installed AFTER setup().
 */
function stubClipboard(writeText: Mock) {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText },
    configurable: true,
    writable: true,
  });
}

/** The ConfirmModal / reveal dialog identified by its own heading, so an identically-named row button is never matched by mistake. */
function dialogByHeading(heading: string): HTMLElement {
  const node = screen.getByRole('heading', { name: heading }).closest('div.fixed');
  if (!node) throw new Error(`No dialog found for heading "${heading}"`);
  return node as HTMLElement;
}

beforeEach(() => {
  vi.clearAllMocks();
  service.listWebhooks.mockResolvedValue({ data: [], scope: 'account' });
  service.listApiKeys.mockResolvedValue({ data: [makeKey()], scope: 'account' });
});

describe('API key list', () => {
  // 1
  it('renders the API key list with name, prefix, status and dates', async () => {
    service.listApiKeys.mockResolvedValue({
      data: [makeKey({ expires_at: '2027-01-01T00:00:00.000000Z' })],
      scope: 'account',
    });

    renderPage();

    expect(await screen.findByText('Production integration')).toBeInTheDocument();
    expect(table().getByText('wasaas_live_a1b2c3…')).toBeInTheDocument();
    expect(table().getByText('Active')).toBeInTheDocument();
    // Created / Last Used / Expires columns are all present.
    expect(screen.getByRole('columnheader', { name: 'Created' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Last Used' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Expires' })).toBeInTheDocument();
  });

  it('shows Revoked and Expired status from the key row itself', async () => {
    service.listApiKeys.mockResolvedValue({
      data: [
        makeKey({ id: 1, name: 'Revoked key', revoked_at: '2026-09-19T09:00:00.000000Z' }),
        makeKey({ id: 2, name: 'Expired key', expires_at: '2020-01-01T00:00:00.000000Z' }),
      ],
      scope: 'account',
    });

    renderPage();

    await screen.findByText('Revoked key');
    expect(table().getByText('Revoked')).toBeInTheDocument();
    expect(table().getByText('Expired')).toBeInTheDocument();
  });

  // 2
  it('renders an empty state when there are no API keys', async () => {
    service.listApiKeys.mockResolvedValue({ data: [], scope: 'account' });

    renderPage();

    expect(await screen.findByText('No API keys yet')).toBeInTheDocument();
    expect(screen.getByText(/create a key to let an external system/i)).toBeInTheDocument();
  });

  it('shows the X-API-KEY header usage hint', async () => {
    renderPage();

    expect(await screen.findByText('X-API-KEY')).toBeInTheDocument();
  });

  it('documents the CRM Developer API endpoints (Task 11)', async () => {
    renderPage();

    expect(await screen.findByText('POST /api/v1/crm/leads')).toBeInTheDocument();
    expect(screen.getByText('GET /api/v1/crm/leads/{id}')).toBeInTheDocument();
    expect(screen.getByText('PATCH …/{id}/status')).toBeInTheDocument();
    expect(screen.getByText('PATCH …/{id}/assignee')).toBeInTheDocument();
    expect(screen.getByText('POST|DELETE …/{id}/tags/{tag}')).toBeInTheDocument();
  });
});

describe('create API key', () => {
  // 3
  it('requires a name and does not call the API when it is empty', async () => {
    const user = userEvent.setup();
    renderPage();
    await openCreateModal(user);

    await user.click(screen.getByRole('button', { name: /generate key/i }));

    expect(await screen.findByText('API key name is required.')).toBeInTheDocument();
    expect(service.createApiKey).not.toHaveBeenCalled();
  });

  it('rejects a whitespace-only name without calling the API', async () => {
    const user = userEvent.setup();
    renderPage();
    await openCreateModal(user);

    await user.type(screen.getByLabelText(/^name/i), '   ');
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    expect(await screen.findByText('API key name is required.')).toBeInTheDocument();
    expect(service.createApiKey).not.toHaveBeenCalled();
  });

  it('clears the field error once the user starts typing', async () => {
    const user = userEvent.setup();
    renderPage();
    await openCreateModal(user);

    await user.click(screen.getByRole('button', { name: /generate key/i }));
    expect(await screen.findByText('API key name is required.')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^name/i), 'CRM');

    expect(screen.queryByText('API key name is required.')).not.toBeInTheDocument();
  });

  // 4 + 5
  it('creates a key and reveals the key and secret exactly once', async () => {
    const user = userEvent.setup();
    service.createApiKey.mockResolvedValue({
      message: 'API key created.',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey({ name: 'CRM integration' }),
    });

    renderPage();
    await openCreateModal(user);

    await user.type(screen.getByLabelText(/^name/i), 'CRM integration');
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    expect(await screen.findByRole('heading', { name: 'API key created' })).toBeInTheDocument();
    expect(screen.getByText(PLAIN_KEY)).toBeInTheDocument();
    expect(screen.getByText(PLAIN_SECRET)).toBeInTheDocument();
    // The "you will not see this again" warning is present for both.
    expect(screen.getAllByText(/will not be shown again/i).length).toBeGreaterThanOrEqual(2);

    expect(service.createApiKey).toHaveBeenCalledWith({ name: 'CRM integration', expires_at: null }, undefined);
  });

  it('does not submit twice when the button is clicked repeatedly', async () => {
    const user = userEvent.setup();
    let resolveCreate: (value: unknown) => void = () => {};
    service.createApiKey.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveCreate = resolve;
        }),
    );

    renderPage();
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'CRM');

    const submit = screen.getByRole('button', { name: /generate key/i });
    await user.click(submit);
    expect(submit).toBeDisabled();
    await user.click(submit).catch(() => undefined);

    resolveCreate({
      message: 'ok',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey(),
    });

    await waitFor(() => expect(service.createApiKey).toHaveBeenCalledTimes(1));
  });

  it('shows success feedback and refreshes the list after creation', async () => {
    const user = userEvent.setup();
    service.createApiKey.mockResolvedValue({
      message: 'ok',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey({ name: 'CRM integration' }),
    });

    renderPage();
    await waitFor(() => expect(service.listApiKeys).toHaveBeenCalledTimes(1));
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'CRM integration');
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    await user.click(await screen.findByRole('button', { name: 'Done' }));

    expect(await screen.findByRole('status')).toHaveTextContent('API key "CRM integration" was created.');
    // Reloaded after create and again after the dialog closed.
    expect(service.listApiKeys.mock.calls.length).toBeGreaterThan(1);
  });

  it('validates the expiry date against the backend after:now rule before submitting', async () => {
    const user = userEvent.setup();
    renderPage();
    await openCreateModal(user);

    await user.type(screen.getByLabelText(/^name/i), 'CRM');
    // Today is never acceptable: a date-only value is midnight, already past.
    // Set it directly rather than typing — the input carries min=tomorrow,
    // which is itself the first line of defence, so this exercises the
    // explicit check behind it.
    const today = new Date().toISOString().slice(0, 10);
    fireEvent.change(screen.getByLabelText(/expires at/i), { target: { value: today } });
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    expect(await screen.findByText('Expiry must be a future date.')).toBeInTheDocument();
    expect(service.createApiKey).not.toHaveBeenCalled();
  });
});

describe('one-time reveal', () => {
  async function createAndReveal(user: ReturnType<typeof userEvent.setup>) {
    service.createApiKey.mockResolvedValue({
      message: 'ok',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey(),
    });
    renderPage();
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'CRM');
    await user.click(screen.getByRole('button', { name: /generate key/i }));
    await screen.findByRole('heading', { name: 'API key created' });
  }

  // 6
  it('does not render the key or secret after the reveal is closed', async () => {
    const user = userEvent.setup();
    await createAndReveal(user);

    await user.click(screen.getByRole('button', { name: 'Done' }));

    await waitFor(() => expect(screen.queryByText(PLAIN_KEY)).not.toBeInTheDocument());
    expect(screen.queryByText(PLAIN_SECRET)).not.toBeInTheDocument();
  });

  it('cannot reconstruct the secret after a remount (nothing persisted it)', async () => {
    const user = userEvent.setup();
    await createAndReveal(user);
    await user.click(screen.getByRole('button', { name: 'Done' }));

    // Simulate a reload: fresh render, list returns the key that now exists.
    cleanupAndRerender();

    expect(await screen.findByText('Production integration')).toBeInTheDocument();
    expect(screen.queryByText(PLAIN_KEY)).not.toBeInTheDocument();
    expect(screen.queryByText(PLAIN_SECRET)).not.toBeInTheDocument();
  });

  // 7
  it('copies the API key to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    const user = userEvent.setup();
    stubClipboard(writeText);
    await createAndReveal(user);

    await user.click(screen.getByRole('button', { name: 'Copy API key' }));

    expect(writeText).toHaveBeenCalledWith(PLAIN_KEY);
    expect(await screen.findByText('Copied')).toBeInTheDocument();
  });

  // 8
  it('copies the API secret to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    const user = userEvent.setup();
    stubClipboard(writeText);
    await createAndReveal(user);

    await user.click(screen.getByRole('button', { name: 'Copy API secret' }));

    expect(writeText).toHaveBeenCalledWith(PLAIN_SECRET);
  });

  it('tells the user when the clipboard is unavailable instead of failing silently', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('denied'));
    const user = userEvent.setup();
    stubClipboard(writeText);
    await createAndReveal(user);

    await user.click(screen.getByRole('button', { name: 'Copy API key' }));

    expect(await screen.findByText(/could not copy automatically/i)).toBeInTheDocument();
  });
});

describe('backend error handling', () => {
  // 9
  it('maps 422 field errors onto the matching form fields', async () => {
    const user = userEvent.setup();
    service.createApiKey.mockRejectedValue(
      httpError(422, {
        message: 'The given data was invalid.',
        errors: {
          name: ['The name has already been taken.'],
          expires_at: ['The expires at must be a date after now.'],
        },
      }),
    );

    renderPage();
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'Duplicate');
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    expect(await screen.findByText('The name has already been taken.')).toBeInTheDocument();
    expect(screen.getByText('The expires at must be a date after now.')).toBeInTheDocument();
    // Inputs are flagged for assistive tech too.
    expect(screen.getByLabelText(/^name/i)).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText(/expires at/i)).toHaveAttribute('aria-invalid', 'true');
  });

  // 10
  it('handles 401 with a session message', async () => {
    service.listApiKeys.mockRejectedValue(httpError(401, { message: 'Unauthenticated.' }));

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('Your session has expired. Please sign in again.');
  });

  // 11
  it('handles 403 by showing the permission message', async () => {
    service.listApiKeys.mockRejectedValue(
      httpError(403, { message: 'You do not have permission to manage API keys.' }),
    );

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('You do not have permission to manage API keys.');
  });

  // 12
  it('handles 404 on revoke', async () => {
    const user = userEvent.setup();
    service.revokeApiKey.mockRejectedValue(httpError(404, { message: 'API key not found.' }));

    renderPage();
    await user.click(await screen.findByRole('button', { name: /revoke/i }));
    await user.click(within(dialogByHeading('Revoke API key')).getByRole('button', { name: 'Revoke' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('API key not found.');
  });

  // 13
  it('handles 409 conflict on revoke', async () => {
    const user = userEvent.setup();
    service.revokeApiKey.mockRejectedValue(httpError(409, {}));

    renderPage();
    await user.click(await screen.findByRole('button', { name: /revoke/i }));
    await user.click(within(dialogByHeading('Revoke API key')).getByRole('button', { name: 'Revoke' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/conflicts with the current state/i);
  });

  // 14
  it('handles 429 with a rate-limit message', async () => {
    service.listApiKeys.mockRejectedValue(httpError(429, { message: 'Too Many Attempts.' }));

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many requests. Please wait a moment and try again.',
    );
  });

  // 15
  it('never leaks a 5xx exception message and shows a safe generic error', async () => {
    service.listApiKeys.mockRejectedValue(
      httpError(500, {
        message: 'SQLSTATE[42S02]: Base table or view not found: api_keys in /var/www/app/Models/ApiKey.php:31',
      }),
    );

    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong. Please try again.');
    expect(alert.textContent).not.toMatch(/SQLSTATE/);
    expect(alert.textContent).not.toMatch(/\/var\/www/);
  });

  it('includes a request id on a 5xx when the response carries one', async () => {
    service.listApiKeys.mockRejectedValue(
      httpError(500, { message: 'Server Error' }, { 'x-request-id': 'req_abc123' }),
    );

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('req_abc123');
  });

  it('reports a network failure without a status', async () => {
    service.listApiKeys.mockRejectedValue(new Error('Network Error'));

    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent(/could not reach the server/i);
  });
});

describe('revoke', () => {
  // 16
  it('asks for confirmation before revoking', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /revoke/i }));

    expect(screen.getByRole('heading', { name: 'Revoke API key' })).toBeInTheDocument();
    expect(screen.getByText(/any integration using it will stop working/i)).toBeInTheDocument();
    expect(service.revokeApiKey).not.toHaveBeenCalled();
  });

  it('does not revoke when the confirmation is cancelled', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /revoke/i }));
    await user.click(within(dialogByHeading('Revoke API key')).getByRole('button', { name: 'Cancel' }));

    expect(service.revokeApiKey).not.toHaveBeenCalled();
  });

  // 17
  it('updates the status and shows success feedback after a successful revoke', async () => {
    const user = userEvent.setup();
    service.revokeApiKey.mockResolvedValue({ message: 'API key revoked.', api_key: makeKey({ revoked_at: 'now' }) });
    service.listApiKeys
      .mockResolvedValueOnce({ data: [makeKey()], scope: 'account' })
      .mockResolvedValue({ data: [makeKey({ revoked_at: '2026-09-22T00:00:00.000000Z' })], scope: 'account' });

    renderPage();
    await user.click(await screen.findByRole('button', { name: /revoke/i }));
    await user.click(within(dialogByHeading('Revoke API key')).getByRole('button', { name: 'Revoke' }));

    await waitFor(() => expect(table().getByText('Revoked')).toBeInTheDocument());
    expect(await screen.findByRole('status')).toHaveTextContent('has been revoked');
    // A revoked key offers no further actions.
    expect(table().queryByRole('button', { name: /^revoke$/i })).not.toBeInTheDocument();
  });
});

describe('regenerate secret', () => {
  // 18
  it('asks for confirmation before regenerating an existing secret', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /regenerate secret/i }));

    expect(screen.getByRole('heading', { name: 'Regenerate secret' })).toBeInTheDocument();
    expect(service.regenerateApiKeySecret).not.toHaveBeenCalled();
  });

  // 19
  it('shows the new secret once after a successful regeneration', async () => {
    const user = userEvent.setup();
    const NEW_SECRET = 'wasaas_secret_ROTATED000000000000000000000000000000';
    service.regenerateApiKeySecret.mockResolvedValue({
      message: 'ok',
      plain_text_secret: NEW_SECRET,
      api_key: makeKey(),
    });

    renderPage();
    await user.click(await screen.findByRole('button', { name: /regenerate secret/i }));
    await user.click(within(dialogByHeading('Regenerate secret')).getByRole('button', { name: 'Regenerate' }));

    expect(await screen.findByRole('heading', { name: 'API secret generated' })).toBeInTheDocument();
    expect(screen.getByText(NEW_SECRET)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Done' }));

    await waitFor(() => expect(screen.queryByText(NEW_SECRET)).not.toBeInTheDocument());
    expect(await screen.findByRole('status')).toHaveTextContent('A new API secret was generated');
  });

  it('surfaces the backend 422 when the key is already revoked', async () => {
    const user = userEvent.setup();
    service.regenerateApiKeySecret.mockRejectedValue(
      httpError(422, {
        message: 'This API key has been revoked and cannot be given a new secret. Create a new key instead.',
      }),
    );

    renderPage();
    await user.click(await screen.findByRole('button', { name: /regenerate secret/i }));
    await user.click(within(dialogByHeading('Regenerate secret')).getByRole('button', { name: 'Regenerate' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('This API key has been revoked');
  });
});

describe('credential hygiene and tenant safety', () => {
  it('never renders a key hash or secret hash, and shows prefixes only', async () => {
    renderPage();

    await screen.findByText('Production integration');
    const body = document.body.textContent ?? '';
    expect(body).not.toMatch(/key_hash/);
    expect(body).not.toMatch(/secret_hash/);
    // Only the prefix form appears, never a full-length credential.
    expect(body).toContain('wasaas_live_a1b2c3…');
  });

  it('does not persist a revealed credential in localStorage, sessionStorage, the URL, or the console', async () => {
    const user = userEvent.setup();
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubClipboard(vi.fn().mockResolvedValue(undefined));

    service.createApiKey.mockResolvedValue({
      message: 'ok',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey(),
    });

    renderPage();
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'CRM');
    await user.click(screen.getByRole('button', { name: /generate key/i }));
    await screen.findByRole('heading', { name: 'API key created' });
    await user.click(screen.getByRole('button', { name: 'Copy API key' }));

    const storageDump = JSON.stringify({ ...localStorage, ...sessionStorage });
    expect(storageDump).not.toContain(PLAIN_KEY);
    expect(storageDump).not.toContain(PLAIN_SECRET);
    expect(window.location.search).not.toContain(PLAIN_KEY);
    expect(window.location.hash).not.toContain(PLAIN_SECRET);

    const logged = [...logSpy.mock.calls, ...errorSpy.mock.calls].flat().join(' ');
    expect(logged).not.toContain(PLAIN_KEY);
    expect(logged).not.toContain(PLAIN_SECRET);
  });

  it('never sends account_id for a tenant admin — ownership comes from the token', async () => {
    const user = userEvent.setup();
    service.createApiKey.mockResolvedValue({
      message: 'ok',
      plain_text_key: PLAIN_KEY,
      plain_text_secret: PLAIN_SECRET,
      api_key: makeKey(),
    });

    renderPage();
    await openCreateModal(user);
    await user.type(screen.getByLabelText(/^name/i), 'CRM');
    await user.click(screen.getByRole('button', { name: /generate key/i }));

    await waitFor(() => expect(service.createApiKey).toHaveBeenCalled());
    const [payload, accountId] = service.createApiKey.mock.calls[0];
    expect(accountId).toBeUndefined();
    expect(Object.keys(payload)).toEqual(['name', 'expires_at']);
    // The form never offers an account/tenant field to a tenant admin.
    expect(screen.queryByLabelText(/client account/i)).not.toBeInTheDocument();
  });
});

/** Unmounts everything and renders the page again — a stand-in for a browser reload. */
function cleanupAndRerender() {
  document.body.innerHTML = '';
  renderPage();
}
