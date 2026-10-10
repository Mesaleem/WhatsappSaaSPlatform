import { useEffect, useState } from 'react';
import { Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo, activeGradient } from '../theme/signalIndigo';
import { extractErrorMessage } from '../utils/apiError';
import journeyApiConnectionService from '../services/journeyApiConnectionService';
import type { JourneyApiConnection, JourneyApiConnectionPair, SaveJourneyApiConnectionPayload } from '../types/journey';

/**
 * Phase 8 Task 15 — "Manage All APIs" for the Journey Builder. A named,
 * reusable API connection (base URL + headers + query) an `api` node can
 * pick from (via its "Saved API Connection" dropdown — see
 * JourneyNodeConfigForm's 'apiConnection' field type) instead of
 * re-entering the same endpoint/credentials in every node that calls it.
 *
 * Unlike ManageVariablesModal (purely local state, folded into the
 * journey's own graph_data on Save), this talks to a real backend
 * resource (JourneyApiConnectionController) — every create/update/delete
 * here persists immediately; there is no separate "Save" step, and
 * closing this modal never discards anything.
 *
 * Secret header/query values round-trip exactly like an `api` node's own
 * (P5-6 / JourneySecrets): a value the server already masked is shown as
 * a blank password field with "Saved — type to replace"; leaving it
 * blank keeps the stored secret, typing replaces it.
 */
function emptyPair(): JourneyApiConnectionPair {
  return { key: '', value: '' };
}

function PairListEditor({
  label,
  help,
  pairs,
  onChange,
}: {
  label: string;
  help?: string;
  pairs: JourneyApiConnectionPair[];
  onChange: (next: JourneyApiConnectionPair[]) => void;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs font-medium text-slate-700">{label}</label>
      <div className="space-y-1.5">
        {pairs.map((pair, index) => (
          <div key={index} className="flex gap-1.5">
            <input
              type="text"
              className={`${inputClass} !mt-0`}
              value={pair.key}
              placeholder="name"
              onChange={(e) => onChange(pairs.map((p, i) => (i === index ? { ...p, key: e.target.value } : p)))}
            />
            <input
              type={pair.masked ? 'password' : 'text'}
              className={`${inputClass} !mt-0`}
              value={pair.masked ? '' : pair.value ?? ''}
              placeholder={pair.masked ? 'Saved — type to replace' : 'value'}
              autoComplete="off"
              onChange={(e) => onChange(pairs.map((p, i) => (i === index ? { key: p.key, value: e.target.value } : p)))}
            />
            <button type="button" onClick={() => onChange(pairs.filter((_, i) => i !== index))} className="text-slate-400 hover:text-red-600" aria-label="Remove row">
              <Trash2 className="h-3.5 w-3.5" />
            </button>
          </div>
        ))}
        <button type="button" onClick={() => onChange([...pairs, emptyPair()])} className="text-xs font-semibold" style={{ color: indigo.accentSolid }}>
          <Plus className="mr-0.5 inline h-3 w-3" />
          Add row
        </button>
      </div>
      {help && (
        <p className="mt-1 text-[11px]" style={{ color: indigo.muted }}>
          {help}
        </p>
      )}
    </div>
  );
}

export default function ManageApiConnectionsModal({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
  const [connections, setConnections] = useState<JourneyApiConnection[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);
  const [draftName, setDraftName] = useState('');
  const [draftBaseUrl, setDraftBaseUrl] = useState('');
  const [draftHeaders, setDraftHeaders] = useState<JourneyApiConnectionPair[]>([]);
  const [draftQuery, setDraftQuery] = useState<JourneyApiConnectionPair[]>([]);
  const [draftNotes, setDraftNotes] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const load = () => {
    setLoadError(null);
    journeyApiConnectionService
      .list()
      .then(setConnections)
      .catch((err: unknown) => setLoadError(extractErrorMessage(err, 'Failed to load API connections.')));
  };

  useEffect(load, []);

  const resetForm = () => {
    setEditingId(null);
    setDraftName('');
    setDraftBaseUrl('');
    setDraftHeaders([]);
    setDraftQuery([]);
    setDraftNotes('');
    setFormError(null);
  };

  const startCreate = () => {
    resetForm();
    setEditingId('new');
  };

  const startEdit = (c: JourneyApiConnection) => {
    setEditingId(c.id);
    setDraftName(c.name);
    setDraftBaseUrl(c.base_url ?? '');
    setDraftHeaders(c.headers.length > 0 ? c.headers : []);
    setDraftQuery(c.query.length > 0 ? c.query : []);
    setDraftNotes(c.notes ?? '');
    setFormError(null);
  };

  const save = async () => {
    if (!draftName.trim()) {
      setFormError('Give this connection a name.');
      return;
    }

    const payload: SaveJourneyApiConnectionPayload = {
      name: draftName.trim(),
      base_url: draftBaseUrl.trim() || null,
      headers: draftHeaders.filter((p) => p.key.trim() !== ''),
      query: draftQuery.filter((p) => p.key.trim() !== ''),
      notes: draftNotes.trim() || null,
    };

    setIsSaving(true);
    setFormError(null);
    try {
      if (editingId === 'new' || editingId === null) {
        await journeyApiConnectionService.create(payload);
      } else {
        await journeyApiConnectionService.update(editingId, payload);
      }
      resetForm();
      load();
      onChanged();
    } catch (err) {
      setFormError(extractErrorMessage(err, 'Failed to save this API connection.'));
    } finally {
      setIsSaving(false);
    }
  };

  const remove = async (c: JourneyApiConnection) => {
    if (!window.confirm(`Delete the API connection "${c.name}"? Any node still referencing it will need a new one.`)) {
      return;
    }

    await journeyApiConnectionService.remove(c.id).catch(() => undefined);
    load();
    onChanged();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onMouseDown={(e) => e.stopPropagation()}>
      <div className="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl">
        <div className="flex items-center justify-between gap-2 border-b px-6 py-4" style={{ borderColor: indigo.border }}>
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Manage API Connections
          </h2>
          <button type="button" onClick={onClose} className="rounded-md p-1.5 text-slate-500 hover:bg-slate-50" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-6 py-4">
          <p className="mb-3 text-xs" style={{ color: indigo.muted }}>
            A saved connection's base URL and headers/query can be picked by name from any API Request node — no need to
            retype them each time. Credential-looking values (Authorization, API keys, tokens) are encrypted and never
            shown again once saved.
          </p>

          {loadError && <div className="mb-3 text-xs font-medium text-red-600">{loadError}</div>}

          {connections === null && !loadError && (
            <div className="flex items-center gap-2 text-xs" style={{ color: indigo.muted }}>
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Loading…
            </div>
          )}

          {connections !== null && connections.length === 0 && editingId === null && (
            <p className="text-xs" style={{ color: indigo.muted }}>
              No API connections yet.
            </p>
          )}

          {connections !== null && connections.length > 0 && (
            <div className="mb-3 divide-y rounded-lg border" style={{ borderColor: indigo.border }}>
              {connections.map((c) => (
                <div key={c.id} className="flex items-center justify-between gap-2 px-3 py-2">
                  <div>
                    <div className="text-xs font-semibold" style={{ color: indigo.ink }}>
                      {c.name}
                    </div>
                    {c.base_url && (
                      <div className="text-[11px]" style={{ color: indigo.muted }}>
                        {c.base_url}
                      </div>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    <button type="button" onClick={() => startEdit(c)} className="text-slate-400 hover:text-indigo-600" aria-label={`Edit ${c.name}`}>
                      <Pencil className="h-3.5 w-3.5" />
                    </button>
                    <button type="button" onClick={() => void remove(c)} className="text-slate-400 hover:text-red-600" aria-label={`Delete ${c.name}`}>
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}

          {editingId === null ? (
            <button
              type="button"
              onClick={startCreate}
              className="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold"
              style={{ borderColor: indigo.border, color: indigo.ink }}
            >
              <Plus className="h-3.5 w-3.5" />
              New API Connection
            </button>
          ) : (
            <div className="space-y-3 rounded-lg border p-3" style={{ borderColor: indigo.border }}>
              <label className="block text-xs font-medium text-slate-700">
                Name
                <input type="text" className={inputClass} value={draftName} onChange={(e) => setDraftName(e.target.value)} placeholder="e.g. CRM Lookup" />
              </label>
              <label className="block text-xs font-medium text-slate-700">
                Base URL
                <input type="text" className={inputClass} value={draftBaseUrl} onChange={(e) => setDraftBaseUrl(e.target.value)} placeholder="https://api.example.com/v1" />
              </label>
              <PairListEditor label="Headers" help="e.g. Authorization, X-Api-Key — these are encrypted." pairs={draftHeaders} onChange={setDraftHeaders} />
              <PairListEditor label="Query parameters" help="e.g. api_key, token — these are encrypted too." pairs={draftQuery} onChange={setDraftQuery} />
              <label className="block text-xs font-medium text-slate-700">
                Notes
                <textarea className={inputClass} rows={2} value={draftNotes} onChange={(e) => setDraftNotes(e.target.value)} />
              </label>
              {formError && <div className="text-xs font-medium text-red-600">{formError}</div>}
              <div className="flex items-center justify-end gap-2">
                <button type="button" onClick={resetForm} className="rounded-lg border px-3 py-2 text-xs font-semibold" style={{ borderColor: indigo.border, color: indigo.ink }}>
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={() => void save()}
                  disabled={isSaving}
                  className="flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-semibold text-white disabled:opacity-60"
                  style={{ background: activeGradient }}
                >
                  {isSaving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
                  {isSaving ? 'Saving…' : 'Save Connection'}
                </button>
              </div>
            </div>
          )}
        </div>

        <div className="flex items-center justify-end border-t px-6 py-4" style={{ borderColor: indigo.border }}>
          <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-xs font-semibold text-white" style={{ background: activeGradient }}>
            Done
          </button>
        </div>
      </div>
    </div>
  );
}
