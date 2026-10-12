import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

/**
 * Dashboard appearance: accent colour + light/dark mode, chosen by each user
 * and remembered in this browser. Applied as attributes on <html>
 * (data-accent / data-theme), which switch the CSS variables declared in
 * tailwind.config.js — so every existing `emerald-*` / `slate-*` / `bg-white`
 * class follows the choice with no component changes.
 *
 * The provider wraps only the logged-in app shell (AppLayout): the attributes
 * are removed again when it unmounts, so the public website always shows the
 * WapHub brand in light mode.
 */

export const ACCENT_OPTIONS = [
  { key: 'emerald', label: 'Emerald', swatch: '#10b981' },
  { key: 'blue', label: 'Blue', swatch: '#3b82f6' },
  { key: 'indigo', label: 'Indigo', swatch: '#6366f1' },
  { key: 'violet', label: 'Violet', swatch: '#8b5cf6' },
  { key: 'rose', label: 'Rose', swatch: '#f43f5e' },
  { key: 'orange', label: 'Orange', swatch: '#f97316' },
  { key: 'teal', label: 'Teal', swatch: '#14b8a6' },
] as const;

export type Accent = (typeof ACCENT_OPTIONS)[number]['key'];
export type ThemeMode = 'light' | 'dark' | 'system';

interface ThemeValue {
  accent: Accent;
  mode: ThemeMode;
  /** The mode actually shown ('system' resolved). */
  resolvedMode: 'light' | 'dark';
  setAccent: (accent: Accent) => void;
  setMode: (mode: ThemeMode) => void;
}

const DEFAULTS = { accent: 'emerald' as Accent, mode: 'light' as ThemeMode };

const ThemeContext = createContext<ThemeValue | undefined>(undefined);

const storageKey = (userId?: number | string | null) => `waphub.theme.${userId ?? 'guest'}`;

function readSaved(userId?: number | string | null): { accent: Accent; mode: ThemeMode } {
  try {
    const raw = localStorage.getItem(storageKey(userId));
    if (!raw) return DEFAULTS;
    const parsed = JSON.parse(raw) as Partial<{ accent: string; mode: string }>;
    const accent = ACCENT_OPTIONS.some((a) => a.key === parsed.accent) ? (parsed.accent as Accent) : DEFAULTS.accent;
    const mode = parsed.mode === 'dark' || parsed.mode === 'system' || parsed.mode === 'light' ? parsed.mode : DEFAULTS.mode;
    return { accent, mode };
  } catch {
    return DEFAULTS;
  }
}

function systemPrefersDark(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function ThemeProvider({ userId, children }: { userId?: number | string | null; children: ReactNode }) {
  const [{ accent, mode }, setState] = useState(() => readSaved(userId));
  const [systemDark, setSystemDark] = useState(systemPrefersDark);

  // A different user on the same browser gets their own saved choice.
  useEffect(() => setState(readSaved(userId)), [userId]);

  useEffect(() => {
    if (mode !== 'system' || typeof window.matchMedia !== 'function') return;
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => setSystemDark(query.matches);
    query.addEventListener?.('change', onChange);
    return () => query.removeEventListener?.('change', onChange);
  }, [mode]);

  const resolvedMode: 'light' | 'dark' = mode === 'system' ? (systemDark ? 'dark' : 'light') : mode;

  useEffect(() => {
    const html = document.documentElement;
    if (accent === 'emerald') html.removeAttribute('data-accent');
    else html.setAttribute('data-accent', accent);
    if (resolvedMode === 'dark') html.setAttribute('data-theme', 'dark');
    else html.removeAttribute('data-theme');
  }, [accent, resolvedMode]);

  // Leaving the app shell (logout, public website) restores the default look.
  useEffect(
    () => () => {
      document.documentElement.removeAttribute('data-accent');
      document.documentElement.removeAttribute('data-theme');
    },
    [],
  );

  const save = useCallback(
    (next: { accent: Accent; mode: ThemeMode }) => {
      setState(next);
      try {
        localStorage.setItem(storageKey(userId), JSON.stringify(next));
      } catch {
        // storage unavailable: the choice lasts for this visit only
      }
    },
    [userId],
  );

  const value = useMemo<ThemeValue>(
    () => ({
      accent,
      mode,
      resolvedMode,
      setAccent: (a) => save({ accent: a, mode }),
      setMode: (m) => save({ accent, mode: m }),
    }),
    [accent, mode, resolvedMode, save],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error('useTheme must be used within a ThemeProvider');
  return ctx;
}

/** Same as useTheme(), or null outside the app shell (components shared with tests/website). */
export function useOptionalTheme(): ThemeValue | null {
  return useContext(ThemeContext) ?? null;
}
