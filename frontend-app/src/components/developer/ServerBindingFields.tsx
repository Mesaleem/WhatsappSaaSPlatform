import { ShieldAlert } from 'lucide-react';
import { inputClass } from '../common/Card';
import type { ServerBindingPayload } from '../../types/developer';

/** The licence warning every key creation must show and have acknowledged. Mirrors config('api_binding.warning'). */
export const SERVER_BINDING_WARNING =
  'This API key is licensed to your purchased account and authorized server. Sharing this API key or using it from an unauthorized server is not permitted. Changing the authorized server requires approval.';

export interface ServerBindingValue {
  acknowledged: boolean;
  label: string;
  policy: 'SINGLE_IP' | 'IP_ALLOWLIST';
  ips: string;
}

export const emptyServerBinding: ServerBindingValue = { acknowledged: false, label: '', policy: 'SINGLE_IP', ips: '' };

export function parseIps(raw: string): string[] {
  return raw
    .split(/[\s,;]+/)
    .map((v) => v.trim())
    .filter(Boolean);
}

/** Returns field errors; an empty object means the value can be submitted (the backend stays authoritative). */
export function validateServerBinding(v: ServerBindingValue): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!v.acknowledged) errors.acknowledge_server_binding = 'You must acknowledge the licence terms to create an API key.';
  if (v.policy === 'IP_ALLOWLIST' && parseIps(v.ips).length === 0) errors.authorized_ips = 'Enter at least one IP address or range.';
  if (v.policy === 'SINGLE_IP' && parseIps(v.ips).length > 1) errors.authorized_ips = 'A single-IP key takes exactly one address.';
  return errors;
}

export function toServerBindingPayload(v: ServerBindingValue): ServerBindingPayload {
  const ips = parseIps(v.ips);
  return {
    acknowledge_server_binding: v.acknowledged,
    ...(v.label.trim() ? { server_label: v.label.trim() } : {}),
    ip_policy: v.policy,
    ...(ips.length ? { authorized_ips: ips } : {}),
  };
}

/** Warning + mandatory acknowledgement + the server the key is licensed to. */
export default function ServerBindingFields({
  value,
  onChange,
  errors = {},
  idPrefix = 'binding',
}: {
  value: ServerBindingValue;
  onChange: (next: ServerBindingValue) => void;
  errors?: Record<string, string>;
  idPrefix?: string;
}) {
  const set = <K extends keyof ServerBindingValue>(key: K, v: ServerBindingValue[K]) => onChange({ ...value, [key]: v });

  return (
    <div className="space-y-3">
      <div role="note" className="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        <ShieldAlert className="mt-0.5 h-4 w-4 flex-shrink-0" />
        <p>
          <span aria-hidden="true">⚠️ </span>
          {SERVER_BINDING_WARNING}
        </p>
      </div>

      <div>
        <label htmlFor={`${idPrefix}-label`} className="text-sm font-medium text-slate-700">
          Server label (optional)
        </label>
        <input id={`${idPrefix}-label`} value={value.label} maxLength={100} onChange={(e) => set('label', e.target.value)} placeholder="e.g. Production Server" className={inputClass} />
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor={`${idPrefix}-policy`} className="text-sm font-medium text-slate-700">
            IP restriction
          </label>
          <select id={`${idPrefix}-policy`} value={value.policy} onChange={(e) => set('policy', e.target.value as ServerBindingValue['policy'])} className={inputClass}>
            <option value="SINGLE_IP">Single server IP</option>
            <option value="IP_ALLOWLIST">IP allowlist</option>
          </select>
        </div>
        <div>
          <label htmlFor={`${idPrefix}-ips`} className="text-sm font-medium text-slate-700">
            {value.policy === 'SINGLE_IP' ? 'Server IP (optional)' : 'Allowed IPs / ranges'}
          </label>
          <input
            id={`${idPrefix}-ips`}
            value={value.ips}
            onChange={(e) => set('ips', e.target.value)}
            placeholder={value.policy === 'SINGLE_IP' ? 'Leave blank to lock to the first server that calls' : '203.0.113.0/28, 2001:db8::/48'}
            aria-invalid={Boolean(errors.authorized_ips)}
            className={inputClass}
          />
          {errors.authorized_ips && <p role="alert" className="mt-1 text-xs text-red-600">{errors.authorized_ips}</p>}
        </div>
      </div>

      <div>
        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={value.acknowledged}
            onChange={(e) => set('acknowledged', e.target.checked)}
            aria-invalid={Boolean(errors.acknowledge_server_binding)}
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600"
          />
          <span>I understand and agree that this key may only be used from my authorized server.</span>
        </label>
        {errors.acknowledge_server_binding && <p role="alert" className="mt-1 text-xs text-red-600">{errors.acknowledge_server_binding}</p>}
      </div>
    </div>
  );
}
