import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import RequestTemplateModal from './RequestTemplateModal';
import templateService from '../../services/templateService';

/**
 * A client requesting a template sets each {{variable}} as Required or Optional (and its label and
 * type); the settings are sent as variables_schema, so sending later asks for exactly those.
 */

vi.mock('../../services/templateService', () => ({ default: { submitRequest: vi.fn() } }));
const svc = templateService as unknown as Record<string, Mock>;

beforeEach(() => {
  vi.clearAllMocks();
  svc.submitRequest.mockResolvedValue({ message: 'Template request submitted.' });
});

describe('request template: variable settings', () => {
  it('sends Required / Optional per variable', async () => {
    const user = userEvent.setup();
    const onSubmitted = vi.fn();
    render(<RequestTemplateModal onClose={vi.fn()} onSubmitted={onSubmitted} />);

    await user.type(screen.getByLabelText(/title/i), 'Payment due');
    fireEvent.change(screen.getByLabelText(/message text/i), { target: { value: 'Hi {{customer_name}}, {{amount}} is due. {{note}}' } });

    // Every new variable starts ticked as Required; unticking shows "Optional".
    const note = await screen.findByTestId('variable-setting-note');
    const box = within(note).getByRole('checkbox', { name: /note is required/i });
    expect(box).toBeChecked();
    await user.click(box);
    expect(box).not.toBeChecked();
    expect(within(note).getByText('Optional')).toBeInTheDocument();
    await user.selectOptions(within(screen.getByTestId('variable-setting-amount')).getByRole('combobox'), 'number');

    await user.click(screen.getByRole('button', { name: /submit request/i }));

    await waitFor(() => expect(svc.submitRequest).toHaveBeenCalled());
    expect(svc.submitRequest.mock.calls[0][0].variables_schema).toEqual([
      { key: 'customer_name', label: 'Customer Name', type: 'string', required: true },
      { key: 'amount', label: 'Amount', type: 'number', required: true },
      { key: 'note', label: 'Note', type: 'string', required: false },
    ]);
    expect(onSubmitted).toHaveBeenCalledWith('Template request submitted.');
  });

  it('refuses a dropdown without options before sending', async () => {
    const user = userEvent.setup();
    render(<RequestTemplateModal onClose={vi.fn()} onSubmitted={vi.fn()} />);

    await user.type(screen.getByLabelText(/title/i), 'Status');
    fireEvent.change(screen.getByLabelText(/message text/i), { target: { value: 'Your order is {{status}}' } });
    await user.selectOptions(within(await screen.findByTestId('variable-setting-status')).getByRole('combobox'), 'select');
    await user.click(screen.getByRole('button', { name: /submit request/i }));

    expect(await screen.findByText(/"Status" is a dropdown — add at least one option/)).toBeInTheDocument();
    expect(svc.submitRequest).not.toHaveBeenCalled();
  });
});
