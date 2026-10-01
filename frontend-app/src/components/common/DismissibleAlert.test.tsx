import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import DismissibleAlert from './DismissibleAlert';
import { ErrorBanner } from '../crm/CrmUi';

describe('DismissibleAlert', () => {
  it('keeps the original element and attributes and closes with the cross', async () => {
    const user = userEvent.setup();
    render(<DismissibleAlert role="alert" className="box">Something failed</DismissibleAlert>);
    expect(screen.getByRole('alert')).toHaveClass('box');
    expect(screen.getByText('Something failed')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close message' }));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('comes back when a different message replaces the dismissed one', async () => {
    const user = userEvent.setup();
    const { rerender } = render(<DismissibleAlert role="alert">First error</DismissibleAlert>);
    await user.click(screen.getByTestId('alert-dismiss'));
    expect(screen.queryByText('First error')).not.toBeInTheDocument();
    rerender(<DismissibleAlert role="alert">Second error</DismissibleAlert>);
    expect(screen.getByText('Second error')).toBeInTheDocument();
  });

  it('calls onDismiss and works as a paragraph', async () => {
    const user = userEvent.setup();
    let calls = 0;
    render(<DismissibleAlert as="p" role="alert" onDismiss={() => { calls += 1; }}>Bad input</DismissibleAlert>);
    expect(screen.getByRole('alert').tagName).toBe('P');
    await user.click(screen.getByTestId('alert-dismiss'));
    expect(calls).toBe(1);
  });

  it('the shared ErrorBanner is dismissible', async () => {
    const user = userEvent.setup();
    render(<ErrorBanner message="Failed to load" />);
    await user.click(screen.getByRole('button', { name: 'Close message' }));
    expect(screen.queryByText('Failed to load')).not.toBeInTheDocument();
  });
});
