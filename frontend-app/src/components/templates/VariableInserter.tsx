import { useState, type RefObject } from 'react';
import { Braces, Check, Plus, X } from 'lucide-react';

/** {{name}} placeholders in a template body, in first-appearance order. */
export function variablesIn(body: string): string[] {
  const seen: string[] = [];
  for (const m of body.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)) {
    if (!seen.includes(m[1])) seen.push(m[1]);
  }
  return seen;
}

/** "Customer Name" → "customer_name". */
export function toVariableName(raw: string): string {
  return raw
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

/**
 * "+ Add variable" for a template body: asks for a name, then inserts {{name}} at the
 * cursor -- nobody has to type the double curly braces by hand. A name already in the
 * body is refused (each variable is created once); existing ones are listed and can be
 * inserted again with a click.
 */
export default function VariableInserter({
  value,
  onChange,
  textareaRef,
}: {
  value: string;
  onChange: (next: string) => void;
  textareaRef: RefObject<HTMLTextAreaElement | null>;
}) {
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const existing = variablesIn(value);

  const insertAtCursor = (key: string) => {
    const token = `{{${key}}}`;
    const el = textareaRef.current;
    const start = el ? el.selectionStart : value.length;
    const end = el ? el.selectionEnd : value.length;
    const next = value.slice(0, start) + token + value.slice(end);
    onChange(next);
    // Put the cursor right after the inserted variable.
    requestAnimationFrame(() => {
      if (!el) return;
      el.focus();
      const pos = start + token.length;
      el.setSelectionRange(pos, pos);
    });
  };

  const add = () => {
    const key = toVariableName(name);
    if (!key) {
      setError('Enter a name, for example customer_name.');
      return;
    }
    if (!/^[a-z]/.test(key)) {
      setError('The name must start with a letter.');
      return;
    }
    if (existing.includes(key)) {
      setError(`{{${key}}} is already in this message. Pick another name, or click it below to use it again.`);
      return;
    }
    insertAtCursor(key);
    setName('');
    setError(null);
    setAdding(false);
  };

  return (
    <div className="mt-2 rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-3 py-2" data-testid="variable-inserter">
      <div className="flex flex-wrap items-center gap-2">
        {!adding ? (
          <button
            type="button"
            onClick={() => setAdding(true)}
            className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
          >
            <Plus className="h-3.5 w-3.5" /> Add variable
          </button>
        ) : (
          <div className="flex flex-wrap items-center gap-1.5">
            <input
              autoFocus
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                setError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  add();
                }
                if (e.key === 'Escape') setAdding(false);
              }}
              placeholder="customer_name"
              aria-label="Variable name"
              className="w-44 rounded-lg border border-slate-300 bg-white px-2 py-1 font-mono text-xs outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"
            />
            <button type="button" onClick={add} className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
              <Check className="h-3.5 w-3.5" /> Insert
            </button>
            <button type="button" onClick={() => { setAdding(false); setError(null); }} className="rounded-lg p-1 text-slate-400 hover:text-slate-600" aria-label="Cancel">
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        )}
        {existing.map((v) => (
          <button
            key={v}
            type="button"
            onClick={() => insertAtCursor(v)}
            title={`Insert {{${v}}} again at the cursor`}
            className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] text-slate-600 ring-1 ring-slate-200 hover:text-emerald-700 hover:ring-emerald-300"
          >
            <Braces className="h-3 w-3" /> {v}
          </button>
        ))}
      </div>
      {error ? (
        <p className="mt-1.5 text-xs text-red-600" role="alert">{error}</p>
      ) : (
        <p className="mt-1.5 text-xs text-slate-500">
          {adding
            ? 'Type a name (small letters, numbers and _). It is inserted at the cursor as {{name}}.'
            : 'Add a variable where a customer-specific value goes (name, amount, date). You fill the value when sending.'}
        </p>
      )}
    </div>
  );
}
