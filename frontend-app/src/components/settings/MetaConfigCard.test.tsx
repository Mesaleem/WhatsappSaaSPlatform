import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import MetaConfigCard from './MetaConfigCard';
import whatsappService from '../../services/whatsappService';
import type { MetaConfigResponse } from '../../types/whatsapp';

/**
 * Phase 4 Task 2 — Meta WhatsApp Connection UI.
 *
 * The card already existed; these tests are the coverage it never had,
 * focused on the behaviour this task is responsible for: per-field
 * validation, credential verification before save, the status-aware
 * error surface (401/403/404/409/422/429/5xx), connection-status display,
 * and the credential-hygiene guarantees (token cleared after save, never
 * in localStorage/sessionStorage/the URL).
 *
 * whatsappService is mocked because it is the seam the card talks to;
 * error cases are rejected with the plain `{response:{status,data}}` shape
 * an AxiosError carries, which is exactly what describeApiError() reads.
 */

vi.mock('../../services/whatsappService', () => ({
  default: {
    getMetaConfig: vi.fn(),
    saveMetaConfig: vi.fn(),
    testMetaConnection: vi.fn(),
    // QR-side methods — present so a stray call would be observable.
    getStatus: vi.fn(),
    startSession: vi.fn(),
    logout: vi.fn(),
  },
}));

let isAdmin = true;
let readOnly = false;

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    hasRole: (role: string) => (role === 'admin' ? isAdmin : false),
    isReadOnly: () => readOnly,
  }),
}));

const service = whatsappService as unknown as {
  getMetaConfig: Mock;
  saveMetaConfig: Mock;
  testMetaConnection: Mock;
  getStatus: Mock;
  startSession: Mock;
  logout: Mock;
};

const TOKEN = 'EAAG_super_secret_permanent_token_0123456789';
const PHONE_ID = '109876543210987';
const WABA_ID = '123456789012345';

function unconfigured(): MetaConfigResponse {
  return {
    configured: false,
    meta_phone_number_id: null,
    meta_waba_id: null,
    meta_access_token_masked: null,
    meta_webhook_verify_token: null,
    webhook_url: 'http://localhost/api/webhooks/meta',
    connection_status: 'disconnected',
  };
}

function configured(overrides: Partial<MetaConfigResponse> = {}): MetaConfigResponse {
  return {
    configured: true,
    meta_phone_number_id: PHONE_ID,
    meta_waba_id: WABA_ID,
    meta_access_token_masked: '••••••••••••••••••••6789',
    meta_webhook_verify_token: 'verify-token-abc',
    webhook_url: 'http://localhost/api/webhooks/meta',
    connection_status: 'connected',
    ...overrides,
  };
}

function httpError(status: number, data: unknown = {}) {
  return { response: { status, data, headers: {} }, isAxiosError: true };
}

async function fillForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/phone number id/i), PHONE_ID);
  await user.type(screen.getByLabelText(/whatsapp business account id/i), WABA_ID);
  await user.type(screen.getByLabelText(/permanent access token/i), TOKEN);
}

const saveButton = () => screen.getByRole('button', { name: /save credentials|update credentials/i });

beforeEach(() => {
  vi.clearAllMocks();
  isAdmin = true;
  readOnly = false;
  service.getMetaConfig.mockResolvedValue(unconfigured());
});

describe('successful Meta configuration', () => {
  it('saves the three credentials and reports the verified name', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'Meta credentials saved.',
      verified_name: 'Acme Support',
      display_phone_number: '+91 99999 99999',
    });

    render(<MetaConfigCard />);
    await screen.findByText(/meta cloud api not configured yet/i);

    await fillForm(user);
    await user.click(saveButton());

    await waitFor(() =>
      expect(service.saveMetaConfig).toHaveBeenCalledWith({
        meta_phone_number_id: PHONE_ID,
        meta_waba_id: WABA_ID,
        meta_access_token: TOKEN,
      }),
    );
    expect(await screen.findByText(/saved and verified as "acme support"/i)).toBeInTheDocument();
  });

  it('verifies credentials against Meta before saving, via Test Connection', async () => {
    const user = userEvent.setup();
    service.testMetaConnection.mockResolvedValue({
      success: true,
      verified_name: 'Acme Support',
      display_phone_number: '+91 99999 99999',
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);

    await user.click(screen.getByRole('button', { name: /test connection/i }));

    expect(await screen.findByText(/connection verified — acme support/i)).toBeInTheDocument();
    // Testing must never persist anything.
    expect(service.saveMetaConfig).not.toHaveBeenCalled();
  });

  it('does not submit twice when the save button is clicked repeatedly', async () => {
    const user = userEvent.setup();
    let resolveSave: (v: unknown) => void = () => {};
    service.saveMetaConfig.mockImplementation(() => new Promise((r) => { resolveSave = r; }));

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);

    const btn = saveButton();
    await user.click(btn);
    expect(btn).toBeDisabled();
    await user.click(btn).catch(() => undefined);

    resolveSave({ ...configured(), message: 'ok', verified_name: null, display_phone_number: null });
    await waitFor(() => expect(service.saveMetaConfig).toHaveBeenCalledTimes(1));
  });
});

describe('required-field validation', () => {
  it('shows a message against each empty field and calls no API', async () => {
    const user = userEvent.setup();
    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);

    await user.click(saveButton());

    expect(await screen.findByText('Phone Number ID is required.')).toBeInTheDocument();
    expect(screen.getByText('WhatsApp Business Account ID is required.')).toBeInTheDocument();
    expect(screen.getByText('Permanent Access Token is required.')).toBeInTheDocument();
    expect(service.saveMetaConfig).not.toHaveBeenCalled();
  });

  it('flags only the field that is actually missing', async () => {
    const user = userEvent.setup();
    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);

    await user.type(screen.getByLabelText(/phone number id/i), PHONE_ID);
    await user.type(screen.getByLabelText(/whatsapp business account id/i), WABA_ID);
    await user.click(saveButton());

    expect(await screen.findByText('Permanent Access Token is required.')).toBeInTheDocument();
    expect(screen.queryByText('Phone Number ID is required.')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/permanent access token/i)).toHaveAttribute('aria-invalid', 'true');
  });

  it('clears a field error as soon as the user types', async () => {
    const user = userEvent.setup();
    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);

    await user.click(saveButton());
    expect(await screen.findByText('Phone Number ID is required.')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/phone number id/i), '1');
    expect(screen.queryByText('Phone Number ID is required.')).not.toBeInTheDocument();
  });

  it('maps a backend 422 onto the matching fields', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockRejectedValue(
      httpError(422, {
        message: 'The given data was invalid.',
        errors: {
          meta_phone_number_id: ['The meta phone number id field is required.'],
          meta_waba_id: ['The meta waba id must be a string.'],
        },
      }),
    );

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    expect(await screen.findByText('The meta phone number id field is required.')).toBeInTheDocument();
    expect(screen.getByText('The meta waba id must be a string.')).toBeInTheDocument();
  });
});

describe('backend error handling', () => {
  it('surfaces invalid Meta credentials with Meta’s own reason', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockRejectedValue(
      httpError(422, {
        message: 'Could not verify these credentials with Meta. Nothing was saved.',
        error: 'Invalid OAuth access token.',
      }),
    );

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    const alert = await screen.findByText(/could not verify these credentials with meta/i);
    expect(alert).toHaveTextContent('Invalid OAuth access token.');
  });

  it('shows the 409 duplicate-number conflict message', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockRejectedValue(
      httpError(409, {
        message:
          'This WhatsApp phone number is already connected to another account on this platform. Disconnect it there first, or use a different phone number.',
        error_code: 'META_NUMBER_ALREADY_CONNECTED',
      }),
    );

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    expect(await screen.findByText(/already connected to another account/i)).toBeInTheDocument();
  });

  it('handles 403 for an unauthorized or inactive account', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockRejectedValue(
      httpError(403, { message: 'Your account is inactive. Contact support.' }),
    );

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    expect(await screen.findByText('Your account is inactive. Contact support.')).toBeInTheDocument();
  });

  it('handles 401 with a session message', async () => {
    service.getMetaConfig.mockRejectedValue(httpError(401, { message: 'Unauthenticated.' }));

    render(<MetaConfigCard />);

    expect(await screen.findByText('Your session has expired. Please sign in again.')).toBeInTheDocument();
  });

  it('handles 429 with a rate-limit message', async () => {
    service.getMetaConfig.mockRejectedValue(httpError(429, { message: 'Too Many Attempts.' }));

    render(<MetaConfigCard />);

    expect(
      await screen.findByText('Too many requests. Please wait a moment and try again.'),
    ).toBeInTheDocument();
  });

  it('never leaks a 5xx exception message', async () => {
    service.getMetaConfig.mockRejectedValue(
      httpError(500, {
        message:
          "SQLSTATE[42S02]: Base table or view not found: whatsapp_sessions in /var/www/app/Models/WhatsAppSession.php:31",
      }),
    );

    render(<MetaConfigCard />);

    const msg = await screen.findByText('Something went wrong. Please try again.');
    expect(msg).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/SQLSTATE/);
    expect(document.body.textContent).not.toMatch(/\/var\/www/);
  });
});

describe('connection status display', () => {
  it('shows Connected without exposing any credential', async () => {
    service.getMetaConfig.mockResolvedValue(configured());

    render(<MetaConfigCard />);

    const badge = await screen.findByTestId('meta-connection-status');
    expect(badge).toHaveTextContent('Connected');
    // Masked preview only — never the token itself.
    expect(screen.getByText('••••••••••••••••••••6789')).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(TOKEN);
    expect(document.body.textContent).not.toContain('EAAG');
  });

  it('shows Not connected when the backend reports disconnected', async () => {
    service.getMetaConfig.mockResolvedValue(configured({ connection_status: 'disconnected' }));

    render(<MetaConfigCard />);

    expect(await screen.findByTestId('meta-connection-status')).toHaveTextContent('Not connected');
  });

  it('reflects the status returned by the save response', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'Meta credentials saved.',
      verified_name: null,
      display_phone_number: null,
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    expect(await screen.findByTestId('meta-connection-status')).toHaveTextContent('Connected');
  });
});

describe('credential hygiene and tenant safety', () => {
  it('clears the token field after save and never shows it again', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'Meta credentials saved.',
      verified_name: null,
      display_phone_number: null,
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    await waitFor(() =>
      expect(screen.getByLabelText(/permanent access token/i)).toHaveValue(''),
    );
    expect(document.body.textContent).not.toContain(TOKEN);
  });

  it('never writes the token to localStorage, sessionStorage or the URL', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'ok',
      verified_name: null,
      display_phone_number: null,
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());
    await waitFor(() => expect(service.saveMetaConfig).toHaveBeenCalled());

    const dump = JSON.stringify({ ...localStorage, ...sessionStorage });
    expect(dump).not.toContain(TOKEN);
    expect(dump).not.toContain('EAAG');
    expect(window.location.search).not.toContain(TOKEN);
    expect(window.location.hash).not.toContain(TOKEN);
  });

  it('never sends an account identifier — tenancy is server-side only', async () => {
    const user = userEvent.setup();
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'ok',
      verified_name: null,
      display_phone_number: null,
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(saveButton());

    await waitFor(() => expect(service.saveMetaConfig).toHaveBeenCalled());
    const payload = service.saveMetaConfig.mock.calls[0][0];
    expect(Object.keys(payload).sort()).toEqual([
      'meta_access_token',
      'meta_phone_number_id',
      'meta_waba_id',
    ]);
  });

  it('hides the form entirely from a non-admin', async () => {
    isAdmin = false;

    render(<MetaConfigCard />);

    expect(
      await screen.findByText(/only an account admin can view or manage meta cloud api credentials/i),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText(/permanent access token/i)).not.toBeInTheDocument();
    expect(service.getMetaConfig).not.toHaveBeenCalled();
  });

  it('disables saving while the subscription is read-only', async () => {
    readOnly = true;

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);

    expect(saveButton()).toBeDisabled();
  });
});

describe('QR configuration regression', () => {
  it('touches only Meta endpoints — no QR session call is ever made', async () => {
    const user = userEvent.setup();
    service.testMetaConnection.mockResolvedValue({ success: true, verified_name: 'Acme' });
    service.saveMetaConfig.mockResolvedValue({
      ...configured(),
      message: 'ok',
      verified_name: null,
      display_phone_number: null,
    });

    render(<MetaConfigCard />);
    await screen.findByText(/not configured yet/i);
    await fillForm(user);
    await user.click(screen.getByRole('button', { name: /test connection/i }));
    await user.click(saveButton());
    await waitFor(() => expect(service.saveMetaConfig).toHaveBeenCalled());

    expect(service.getStatus).not.toHaveBeenCalled();
    expect(service.startSession).not.toHaveBeenCalled();
    expect(service.logout).not.toHaveBeenCalled();
  });
});
