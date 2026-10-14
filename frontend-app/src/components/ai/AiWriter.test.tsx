import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AiWriter from './AiWriter';
import RequestTemplateModal from '../templates/RequestTemplateModal';
import aiService from '../../services/aiService';

/** "Write with AI" for every client: write, use the draft, fill a template, and say clearly when credits ran out. */

vi.mock('../../services/aiService', () => ({ default: { requestTemplateDraft: vi.fn(), writeMessage: vi.fn(), leadFollowUp: vi.fn() } }));
vi.mock('../../services/templateService', () => ({ default: { submitRequest: vi.fn() } }));
const ai = aiService as unknown as Record<string, Mock>;

beforeEach(() => vi.clearAllMocks());

describe('AiWriter', () => {
  it('writes in the chosen language and hands the draft back with "Use this"', async () => {
    const user = userEvent.setup();
    const generate = vi.fn().mockResolvedValue('Namaste! Kal dukaan band rahegi.');
    const onUse = vi.fn();
    render(<AiWriter generate={generate} onUse={onUse} />);

    await user.click(screen.getByRole('button', { name: /write with ai/i }));
    await user.type(screen.getByLabelText(/what is the message for/i), 'Shop closed tomorrow');
    await user.selectOptions(screen.getByLabelText('Language'), 'hinglish');
    await user.click(screen.getByRole('button', { name: 'Write' }));

    expect(generate).toHaveBeenCalledWith({ prompt: 'Shop closed tomorrow', language: 'hinglish' });
    expect(await screen.findByText('Namaste! Kal dukaan band rahegi.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /use this/i }));
    expect(onUse).toHaveBeenCalledWith('Namaste! Kal dukaan band rahegi.');
  });

  it('asks for a purpose first, and explains when the AI credits ran out', async () => {
    const user = userEvent.setup();
    const generate = vi.fn().mockRejectedValue({ response: { status: 422, data: { message: 'Insufficient credits', error_code: 'INSUFFICIENT_CREDITS' } } });
    render(<AiWriter generate={generate} />);

    await user.click(screen.getByRole('button', { name: /write with ai/i }));
    await user.click(screen.getByRole('button', { name: 'Write' }));
    expect(screen.getByRole('alert')).toHaveTextContent(/few words/);
    expect(generate).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(/what is the message for/i), 'Diwali offer');
    await user.click(screen.getByRole('button', { name: 'Write' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/no AI credits left/i);
  });
});

describe('Request a Template with AI', () => {
  it('fills the title and message, keeping the variables already in the message', async () => {
    ai.requestTemplateDraft.mockResolvedValue({ provider: 'openai', title: 'Payment reminder', template_body: 'Hi {{customer_name}}, your payment is due.' });
    const user = userEvent.setup();
    render(<RequestTemplateModal onClose={vi.fn()} onSubmitted={vi.fn()} />);

    fireEvent.change(screen.getByLabelText(/message text/i), { target: { value: 'Hi {{customer_name}}' } });
    await user.click(screen.getByRole('button', { name: /write the template with ai/i }));
    await user.type(screen.getByLabelText(/what should this template say/i), 'Payment due reminder');
    await user.click(screen.getByRole('button', { name: 'Write' }));

    await waitFor(() => expect(screen.getByLabelText(/message text/i)).toHaveValue('Hi {{customer_name}}, your payment is due.'));
    expect(screen.getByLabelText(/title/i)).toHaveValue('Payment reminder');
    expect(ai.requestTemplateDraft).toHaveBeenCalledWith(expect.objectContaining({ purpose: 'Payment due reminder', variable_keys: ['customer_name'] }));
  });
});
