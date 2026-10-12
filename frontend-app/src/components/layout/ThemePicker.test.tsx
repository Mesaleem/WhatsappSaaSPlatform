import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import ThemePicker from './ThemePicker';
import { ThemeProvider } from '../../core/theme/ThemeContext';

const html = document.documentElement;

function renderPicker(userId = 7) {
  return render(
    <ThemeProvider userId={userId}>
      <ThemePicker />
    </ThemeProvider>,
  );
}

describe('Dashboard theme picker', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => {
    html.removeAttribute('data-accent');
    html.removeAttribute('data-theme');
  });

  it('switches accent colour and dark mode, and remembers it per user', async () => {
    const user = userEvent.setup();
    const { unmount } = renderPicker();

    expect(html.hasAttribute('data-accent')).toBe(false); // default brand
    await user.click(screen.getByRole('button', { name: 'Theme and colours' }));
    await user.click(screen.getByRole('radio', { name: 'Blue' }));
    await user.click(screen.getByRole('radio', { name: /Dark/ }));

    expect(html.getAttribute('data-accent')).toBe('blue');
    expect(html.getAttribute('data-theme')).toBe('dark');
    expect(JSON.parse(localStorage.getItem('waphub.theme.7') ?? '{}')).toEqual({ accent: 'blue', mode: 'dark' });

    // Leaving the app shell restores the website's default look.
    unmount();
    expect(html.hasAttribute('data-accent')).toBe(false);
    expect(html.hasAttribute('data-theme')).toBe(false);

    // Coming back applies the saved choice again; another user keeps the default.
    const again = renderPicker(7);
    expect(html.getAttribute('data-accent')).toBe('blue');
    again.unmount();
    renderPicker(8);
    expect(html.hasAttribute('data-accent')).toBe(false);
  });

  it('choosing Emerald returns to the default brand', async () => {
    localStorage.setItem('waphub.theme.7', JSON.stringify({ accent: 'rose', mode: 'light' }));
    const user = userEvent.setup();
    renderPicker();
    expect(html.getAttribute('data-accent')).toBe('rose');

    await user.click(screen.getByRole('button', { name: 'Theme and colours' }));
    await user.click(screen.getByRole('radio', { name: 'Emerald' }));

    expect(html.hasAttribute('data-accent')).toBe(false);
    expect(html.hasAttribute('data-theme')).toBe(false);
  });

  it('ignores a corrupted saved value', () => {
    localStorage.setItem('waphub.theme.7', '{not json');
    renderPicker();
    expect(html.hasAttribute('data-accent')).toBe(false);
  });
});
