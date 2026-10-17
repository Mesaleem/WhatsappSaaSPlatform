import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

vi.mock('socket.io-client', () => ({
  io: () => ({ on: vi.fn(), connect: vi.fn(), disconnect: vi.fn() }),
}));
vi.mock('../../services/whatsappService', () => ({
  default: { startSession: vi.fn(() => Promise.resolve({})) },
}));

import QRScannerModal from './QRScannerModal';
import { AUTH_TOKEN_KEY } from '../../core/api/axiosInstance';

function openPhoneTab() {
  fireEvent.click(screen.getByRole('tab', { name: /phone number/i }));
  return screen.getByRole('textbox') as HTMLInputElement;
}

describe('QRScannerModal phone number', () => {
  beforeEach(() => localStorage.setItem(AUTH_TOKEN_KEY, 'token'));

  it('pre-fills the number saved on the slot and keeps it editable', () => {
    render(<QRScannerModal accountId={1} numberId={1} suggestedPhone="917718967080" onClose={() => {}} onConnected={() => {}} />);
    const input = openPhoneTab();
    expect(input.value).toBe('917718967080');
    expect(input.readOnly).toBe(false);
    fireEvent.change(input, { target: { value: '918928100010' } });
    expect(input.value).toBe('918928100010');
  });

  it('starts empty when the slot has no number', () => {
    render(<QRScannerModal accountId={1} numberId={1} onClose={() => {}} onConnected={() => {}} />);
    expect(openPhoneTab().value).toBe('');
  });
});
