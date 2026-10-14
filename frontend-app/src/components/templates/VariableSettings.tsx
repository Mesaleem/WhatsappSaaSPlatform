import { useEffect } from 'react';
import { ListChecks } from 'lucide-react';
import type { TemplateVariableSchemaField, TemplateVariableType } from '../../types/templates';
import { variablesIn } from './VariableInserter';

const inputClass =
  'w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

const TYPE_OPTIONS: { value: TemplateVariableType; label: string }[] = [
  { value: 'string', label: 'Text' },
  { value: 'number', label: 'Number' },
  { value: 'date', label: 'Date' },
  { value: 'select', label: 'Dropdown' },
];

/** "customer_name" → "Customer Name". */
export function variableLabel(key: string): string {
  return key
    .split('_')
    .filter(Boolean)
    .map((w) => w[0].toUpperCase() + w.slice(1))
    .join(' ');
}

/** The default for a new variable: text, required — the same default the server uses for a template without settings. */
export function defaultVariableSetting(key: string): TemplateVariableSchemaField {
  return { key, label: variableLabel(key), type: 'string', required: true };
}

/** First problem with the settings (a dropdown without options), or null. */
export function variableSettingsError(schema: TemplateVariableSchemaField[]): string | null {
  const bad = schema.find((f) => f.type === 'select' && (f.options ?? []).filter((o) => o.trim()).length === 0);
  return bad ? `"${bad.label || bad.key}" is a dropdown — add at least one option.` : null;
}

/**
 * "Required" checkbox for one variable: ticked = must be filled when sending, unticked = optional
 * (may be left empty). The state is spelled out next to it so nobody has to guess.
 */
export function RequiredToggle({
  field,
  onChange,
  lockRequired = false,
}: {
  field: TemplateVariableSchemaField;
  onChange: (required: boolean) => void;
  /** Meta (Cloud API) templates: Meta refuses an empty parameter, so every variable is required. */
  lockRequired?: boolean;
}) {
  return (
    <label
      className={`inline-flex items-center gap-2 text-sm ${lockRequired ? 'cursor-not-allowed' : 'cursor-pointer'}`}
      title={lockRequired ? 'Meta templates need a value for every variable.' : undefined}
    >
      <input
        type="checkbox"
        checked={field.required}
        disabled={lockRequired}
        onChange={(e) => onChange(e.target.checked)}
        aria-label={`${field.label || field.key} is required`}
        className="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-60"
      />
      {field.required ? (
        <span className="font-medium text-slate-800">
          Required <span className="text-red-500">*</span>
        </span>
      ) : (
        <span className="text-slate-500">Optional</span>
      )}
    </label>
  );
}

/**
 * Per-variable settings for a template being written: one row per {{variable}} with its label,
 * input type and a "Required" checkbox (dropdown options under the row). Kept in step with the
 * {{variables}} in the message — a new one gets the default (text, required), a removed one is
 * dropped, the rest keep what was set. A required variable must be filled when sending
 * (dashboard and Developer API); an optional one may be left empty and is then sent blank.
 */
export default function VariableSettings({
  body,
  value,
  onChange,
}: {
  body: string;
  value: TemplateVariableSchemaField[];
  onChange: (next: TemplateVariableSchemaField[]) => void;
}) {
  const keys = variablesIn(body).join('|');

  useEffect(() => {
    const byKey = new Map(value.map((f) => [f.key, f]));
    const next = (keys ? keys.split('|') : []).map((k) => byKey.get(k) ?? defaultVariableSetting(k));
    if (next.length !== value.length || next.some((f, i) => f !== value[i])) onChange(next);
    // Only the set of variables in the message drives this; edits to a row go through update().
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [keys]);

  if (value.length === 0) return null;

  const update = (key: string, patch: Partial<TemplateVariableSchemaField>) => onChange(value.map((f) => (f.key === key ? { ...f, ...patch } : f)));

  return (
    <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3" data-testid="variable-settings">
      <div className="flex items-center gap-1.5 text-sm font-medium text-slate-700">
        <ListChecks className="h-4 w-4 text-emerald-700" />
        Variable settings
      </div>
      <p className="mt-0.5 text-xs text-slate-500">
        Tick <strong>Required</strong> if a value must be given every time this template is sent (dashboard or API). Untick it to make the variable optional — it can then be left empty.
      </p>

      <div className="mt-2 hidden grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_7rem_7rem] gap-2 px-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:grid">
        <span>Variable</span>
        <span>Label</span>
        <span>Type</span>
        <span>Required?</span>
      </div>
      <div className="mt-1 space-y-2">
        {value.map((field) => (
          <div key={field.key} className="rounded-lg border border-slate-200 bg-white p-2" data-testid={`variable-setting-${field.key}`}>
            <div className="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_7rem_7rem]">
              <span className="truncate font-mono text-xs text-emerald-800" title={`{{${field.key}}}`}>{`{{${field.key}}}`}</span>
              <input
                value={field.label}
                onChange={(e) => update(field.key, { label: e.target.value })}
                maxLength={255}
                aria-label={`Label for ${field.key}`}
                placeholder="Label"
                className={inputClass}
              />
              <select
                value={field.type}
                onChange={(e) => {
                  const type = e.target.value as TemplateVariableType;
                  update(field.key, type === 'select' ? { type, options: field.options ?? [] } : { type, options: undefined });
                }}
                aria-label={`Type for ${field.key}`}
                className={inputClass}
              >
                {TYPE_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
              <RequiredToggle field={field} onChange={(required) => update(field.key, { required })} />
            </div>
            {field.type === 'select' && (
              <input
                defaultValue={(field.options ?? []).join(', ')}
                onBlur={(e) => update(field.key, { options: e.target.value.split(',').map((o) => o.trim()).filter(Boolean) })}
                aria-label={`Dropdown options for ${field.key}`}
                placeholder="Dropdown options, comma-separated — e.g. Paid, Pending, Overdue"
                className={`${inputClass} mt-2`}
              />
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
