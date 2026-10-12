import { useEffect, useRef, useState } from 'react';
import { Check, Monitor, Moon, Palette, Sun } from 'lucide-react';
import { ACCENT_OPTIONS, useTheme, type ThemeMode } from '../../core/theme/ThemeContext';

const MODES: { key: ThemeMode; label: string; icon: typeof Sun }[] = [
  { key: 'light', label: 'Light', icon: Sun },
  { key: 'dark', label: 'Dark', icon: Moon },
  { key: 'system', label: 'System', icon: Monitor },
];

/** Header button: pick the dashboard's accent colour and light / dark / system mode. */
export default function ThemePicker() {
  const { accent, mode, setAccent, setMode } = useTheme();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Theme and colours"
        aria-expanded={open}
        className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
      >
        <Palette className="h-5 w-5" />
      </button>

      {open && (
        <div role="dialog" aria-label="Appearance" className="absolute right-0 z-50 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
          <p className="text-sm font-semibold text-slate-900">Appearance</p>
          <p className="mt-0.5 text-xs text-slate-500">Saved for you on this browser.</p>

          <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Mode</p>
          <div className="mt-2 grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1" role="radiogroup" aria-label="Mode">
            {MODES.map((m) => {
              const on = mode === m.key;
              return (
                <button
                  key={m.key}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => setMode(m.key)}
                  className={`flex items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-semibold transition ${
                    on ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  <m.icon className="h-3.5 w-3.5" /> {m.label}
                </button>
              );
            })}
          </div>

          <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Accent colour</p>
          <div className="mt-2 grid grid-cols-7 gap-2" role="radiogroup" aria-label="Accent colour">
            {ACCENT_OPTIONS.map((a) => {
              const on = accent === a.key;
              return (
                <button
                  key={a.key}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  aria-label={a.label}
                  title={a.label}
                  onClick={() => setAccent(a.key)}
                  className={`flex h-8 w-8 items-center justify-center rounded-full text-white ring-offset-2 ring-offset-white transition hover:scale-110 ${on ? 'ring-2 ring-slate-400' : ''}`}
                  style={{ background: a.swatch }}
                >
                  {on && <Check className="h-4 w-4" strokeWidth={3} />}
                </button>
              );
            })}
          </div>

          <div className="mt-4 rounded-xl border border-slate-200 p-3">
            <p className="text-xs text-slate-500">Preview</p>
            <div className="mt-2 flex items-center gap-2">
              <span className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Button</span>
              <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Badge</span>
              <span className="text-xs font-semibold text-emerald-700">Link</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
