import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { AxiosError } from 'axios';
import {
  AlertTriangle,
  Check,
  Clock,
  Contact,
  Copy,
  Loader2,
  Lock,
  Plus,
  RefreshCw,
  ShieldCheck,
  Trash2,
  Upload,
  Users,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import contactGroupsService from '../../services/contactGroupsService';
import type { ContactGroup, ContactGroupContactInput, ContactGroupType } from '../../types/contactGroup';
import type { ApiErrorResponse } from '../../types/auth';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Card, TableCard, inputClass } from '../../components/common/Card';

/** Same pattern as MessageLogsPage.tsx's extractMessage() — surfaces the backend's real error (e.g. GROUP_MODULE_DISABLED's exact copy) instead of a fixed generic string. */
function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — Custom Contact Groups manager.
 *
 * [Disclosed, deliberate, carried over unchanged from Phase 2]: this
 * route and its AppLayout sidebar item are reachable by any user
 * holding send-messages, regardless of the contact_groups module —
 * unlike every other module-gated nav item in this app (AppLayout's
 * `requiresModule` hides the item entirely rather than rendering a
 * locked state). That's a deliberate deviation: hiding this nav item the
 * same way message_logs/chatbot do would make the locked upsell card
 * below unreachable through normal navigation, which defeats its
 * purpose as a paid-addon upsell. The actual data — list, create,
 * add-contacts, delete — stays fully enforced server-side either way
 * (module.guard:contact_groups in routes/api.php is completely
 * independent of what this page chooses to render).
 */
export default function ContactGroupsPage() {
  const { hasModule } = useAuth();
  const moduleEnabled = hasModule('contact_groups');

  const [groups, setGroups] = useState<ContactGroup[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);

  const load = useCallback(async () => {
    if (!moduleEnabled) {
      setIsLoading(false);
      return;
    }
    setIsLoading(true);
    setError(null);
    try {
      const data = await contactGroupsService.list();
      setGroups(data);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load contact groups.'));
    } finally {
      setIsLoading(false);
    }
  }, [moduleEnabled]);

  useEffect(() => {
    void load();
  }, [load]);

  // Native WhatsApp Group Re-Architecture: a 'pending' group's
  // sync_status resolves asynchronously (CreateNativeWhatsAppGroupJob),
  // with no push/socket channel telling this page when that happens —
  // unlike the QR pairing modal's Socket.IO connection, there was no
  // request to add a second realtime channel here. A short client-side
  // poll while ANY group on this page is still 'pending' is a minimal,
  // disclosed stand-in: it stops itself the moment nothing is pending,
  // so it never polls indefinitely or while this page has no native
  // groups at all.
  useEffect(() => {
    const hasPending = groups.some((g) => g.group_type === 'native_wa_group' && g.sync_status === 'pending');
    if (!hasPending) return;
    const timer = setInterval(() => void load(), 5000);
    return () => clearInterval(timer);
  }, [groups, load]);

  const handleDelete = async (group: ContactGroup) => {
    if (group.is_default) return;
    const confirmMessage =
      group.group_type === 'native_wa_group'
        ? `Delete "${group.name}"? This removes it from this app only — the real WhatsApp group on your phone is NOT affected.`
        : `Delete "${group.name}"? This cannot be undone.`;
    if (!confirm(confirmMessage)) return;
    try {
      await contactGroupsService.remove(group.id);
      void load();
    } catch (err) {
      setError(extractMessage(err, 'Failed to delete this group.'));
    }
  };

  /**
   * [Disclosed]: this ALWAYS creates a brand-new WhatsApp group — it can
   * never confirm the old one is actually gone (see
   * ContactGroupController::recreate()'s docblock). The confirm() prompt
   * below exists specifically to surface that duplicate-group risk
   * before the user commits to it, since a "Sync failed" badge can come
   * from a transient send error, not only an actual deletion.
   */
  const handleRecreate = async (group: ContactGroup) => {
    const confirmMessage = `Recreate "${group.name}"? This creates a brand-new WhatsApp group with the same members. If the old group still exists on WhatsApp, it will NOT be deleted — you may end up with two groups.`;
    if (!confirm(confirmMessage)) return;
    try {
      await contactGroupsService.recreate(group.id);
      void load();
    } catch (err) {
      setError(extractMessage(err, 'Failed to recreate this group.'));
    }
  };

  return (
    // Full-width layout — matches MessageLogsPage.tsx's own
    // PageShell override (max-w-6xl's default cap left a large empty
    // gutter next to this page's table, unlike the other data-table
    // screens).
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Contact}
        title="Contact Groups"
        subtitle="Organize recipients into named lists — or a real, native WhatsApp group — for group WhatsApp sends."
        actions={
          moduleEnabled ? (
            <div className="flex items-center gap-2">
              <button
                onClick={() => void load()}
                className="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 hover:bg-slate-50"
                aria-label="Refresh"
                title="Refresh"
              >
                <RefreshCw className="h-4 w-4" />
              </button>
              {hasModule('contact_groups') && (
                <button
                  onClick={() => setShowImport(true)}
                  className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                  <Upload className="h-4 w-4" />
                  Import Contacts
                </button>
              )}
              {hasModule('contact_groups') && (
                <button
                  onClick={() => setShowCreate(true)}
                  className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                >
                  <Plus className="h-4 w-4" />
                  Create Group
                </button>
              )}
            </div>
          ) : undefined
        }
      />

      {!moduleEnabled ? (
        <LockedCard />
      ) : (
        <>
          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
          )}

          <TableCard>
            <table className="w-full min-w-[820px] divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-3">Name</th>
                  <th className="px-4 py-3">Members</th>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">WhatsApp Group</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-400">
                      Loading…
                    </td>
                  </tr>
                ) : groups.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-10 text-center text-slate-400">
                      No contact groups yet.
                    </td>
                  </tr>
                ) : (
                  groups.map((group) => (
                    <tr key={group.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 font-medium text-slate-900">
                        {group.name}
                        {group.is_default && (
                          <span className="ml-2 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">
                            Default
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{group.members_count}</td>
                      <td className="px-4 py-3">
                        <GroupTypeBadge type={group.group_type} />
                      </td>
                      <td className="px-4 py-3">
                        {group.group_type === 'native_wa_group' ? (
                          <NativeGroupCell group={group} onRecreate={() => void handleRecreate(group)} />
                        ) : (
                          <span className="text-xs text-slate-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={() => void handleDelete(group)}
                          disabled={group.is_default}
                          title={group.is_default ? 'The default group cannot be deleted.' : 'Delete group'}
                          className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </TableCard>
        </>
      )}

      {showCreate && (
        <CreateGroupModal
          onClose={() => setShowCreate(false)}
          onCreated={() => {
            setShowCreate(false);
            void load();
          }}
        />
      )}

      {showImport && (
        <ImportContactsModal
          groups={groups}
          onClose={() => setShowImport(false)}
          onImported={() => {
            setShowImport(false);
            void load();
          }}
        />
      )}
    </PageShell>
  );
}

/** Exact copy per spec: "Custom Contact Groups are locked under your current plan. Contact Admin to upgrade." */
function LockedCard() {
  return (
    <Card className="flex flex-col items-center px-6 py-16 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
        <Lock className="h-6 w-6 text-amber-600" />
      </div>
      <h2 className="mt-4 text-base font-semibold text-slate-900">Premium feature</h2>
      <p className="mt-2 max-w-md text-sm text-slate-500">
        Custom Contact Groups are locked under your current plan. Contact Admin to upgrade.
      </p>
    </Card>
  );
}

function GroupTypeBadge({ type }: { type: ContactGroupType }) {
  if (type === 'native_wa_group') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
        <Users className="h-3 w-3" />
        Native WhatsApp Group
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
      Internal Segment
    </span>
  );
}

/** Sync status badge + JID/invite-link display for a native_wa_group row. */
function NativeGroupCell({ group, onRecreate }: { group: ContactGroup; onRecreate: () => void }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    if (!group.invite_link) return;
    void navigator.clipboard.writeText(group.invite_link).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  if (group.sync_status === 'pending') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
        <Clock className="h-3 w-3 animate-pulse" />
        Syncing…
      </span>
    );
  }

  if (group.sync_status === 'failed') {
    return (
      <div className="flex items-center gap-2">
        <span
          className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20"
          title={group.sync_error ?? 'Sync failed.'}
        >
          <AlertTriangle className="h-3 w-3" />
          Sync failed
        </span>
        <button
          onClick={onRecreate}
          className="flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
        >
          <RefreshCw className="h-3 w-3" />
          Recreate
        </button>
      </div>
    );
  }

  // sync_status === 'synced'
  return (
    <div className="flex items-center gap-2">
      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
        <ShieldCheck className="h-3 w-3" />
        Synced
      </span>
      {group.wa_group_jid && (
        <span className="font-mono text-xs text-slate-400" title={group.wa_group_jid}>
          {group.wa_group_jid.slice(0, 14)}…
        </span>
      )}
      {group.invite_link && (
        <button
          onClick={handleCopy}
          title="Copy invite link"
          className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
        >
          {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
        </button>
      )}
    </div>
  );
}

function CreateGroupModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState('');
  const [groupType, setGroupType] = useState<ContactGroupType>('internal_segment');
  const [rawContacts, setRawContacts] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setError('Group name is required.');
      return;
    }

    const contacts = groupType === 'native_wa_group' ? parseContactLines(rawContacts) : undefined;
    if (groupType === 'native_wa_group' && (!contacts || contacts.length === 0)) {
      setError('A Native WhatsApp Group needs at least one starting member — WhatsApp itself has no concept of an empty group.');
      return;
    }

    setIsSaving(true);
    setError(null);
    try {
      await contactGroupsService.create({
        name: name.trim(),
        group_type: groupType,
        contacts,
      });
      onCreated();
    } catch (err) {
      setError(extractMessage(err, 'Could not create this group.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
      <div className="my-8 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">New contact group</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">
              Name <span className="text-red-500">*</span>
            </label>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Vendor Group A"
              className={inputClass}
              autoFocus
            />
          </div>

          <div>
            <label className="text-sm font-medium text-slate-700">Group type</label>
            <div className="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <button
                type="button"
                onClick={() => setGroupType('internal_segment')}
                className={`rounded-lg border px-3 py-2 text-left text-sm ${
                  groupType === 'internal_segment'
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                    : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                }`}
              >
                <div className="font-medium">Internal Segment</div>
                <div className="text-xs text-slate-500">A list you send to — each member gets their own DM.</div>
              </button>
              <button
                type="button"
                onClick={() => setGroupType('native_wa_group')}
                className={`rounded-lg border px-3 py-2 text-left text-sm ${
                  groupType === 'native_wa_group'
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                    : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                }`}
              >
                <div className="font-medium">Native WhatsApp Group</div>
                <div className="text-xs text-slate-500">A real WhatsApp group — one message, one group chat.</div>
              </button>
            </div>
          </div>

          {groupType === 'native_wa_group' && (
            <>
              <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Requires an active WhatsApp connection on the QR (Baileys) engine. The official Meta Cloud API cannot
                create or message WhatsApp groups.
              </p>
              <div>
                <label className="text-sm font-medium text-slate-700">
                  Starting members <span className="text-red-500">*</span>{' '}
                  <span className="text-xs font-normal text-slate-400">(one per line: phone number, optional name)</span>
                </label>
                <textarea
                  value={rawContacts}
                  onChange={(e) => setRawContacts(e.target.value)}
                  rows={6}
                  placeholder={'919876543210, Vendor 1\n+91 91234 56789, Vendor 2'}
                  className={`${inputClass} font-mono`}
                />
              </div>
            </>
          )}

          {error && <p className="text-sm text-red-600">{error}</p>}

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
              Create Group
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * One line per contact: "phone_number" or "phone_number, name". Parsed
 * client-side — the server normalizes/dedupes the phone number itself
 * (ContactGroupController::addContacts()/store(), via the same
 * PhoneNumberNormalizer the rest of this app already uses), so this
 * parser only needs to split lines and the first comma, not validate or
 * normalize formatting.
 */
function parseContactLines(raw: string): ContactGroupContactInput[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
    .map((line) => {
      const commaIndex = line.indexOf(',');
      if (commaIndex === -1) {
        return { phone_number: line };
      }
      const phone = line.slice(0, commaIndex).trim();
      const name = line.slice(commaIndex + 1).trim();
      return { phone_number: phone, name: name || undefined };
    })
    .filter((c) => c.phone_number.length > 0);
}

function ImportContactsModal({
  groups,
  onClose,
  onImported,
}: {
  groups: ContactGroup[];
  onClose: () => void;
  onImported: () => void;
}) {
  const defaultGroupId = groups.find((g) => g.is_default)?.id ?? groups[0]?.id ?? '';
  const [groupId, setGroupId] = useState<number | ''>(defaultGroupId);
  const [raw, setRaw] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selectedGroup = groups.find((g) => g.id === groupId);
  const isNativePending = selectedGroup?.group_type === 'native_wa_group' && selectedGroup.sync_status !== 'synced';

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!groupId) {
      setError('Select a group first.');
      return;
    }
    const contacts = parseContactLines(raw);
    if (contacts.length === 0) {
      setError('Enter at least one phone number.');
      return;
    }
    setIsSaving(true);
    setError(null);
    try {
      await contactGroupsService.addContacts(Number(groupId), contacts);
      onImported();
    } catch (err) {
      setError(extractMessage(err, 'Could not import these contacts.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
      <div className="my-8 w-full max-w-xl rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Import contacts</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">
              Group <span className="text-red-500">*</span>
            </label>
            <select
              value={groupId}
              onChange={(e) => setGroupId(e.target.value ? Number(e.target.value) : '')}
              className={inputClass}
            >
              <option value="">Select a group…</option>
              {groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                  {g.is_default ? ' (Default)' : ''}
                  {g.group_type === 'native_wa_group' ? ' (Native WA Group)' : ''}
                </option>
              ))}
            </select>
          </div>

          {isNativePending && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
              This WhatsApp group {selectedGroup?.sync_status === 'failed' ? 'failed to sync' : 'is still syncing'} —
              contacts you add now are saved here, but won't reach the real WhatsApp group until it's
              {selectedGroup?.sync_status === 'failed' ? ' recreated.' : ' synced.'}
            </p>
          )}

          <div>
            <label className="text-sm font-medium text-slate-700">
              Contacts <span className="text-red-500">*</span>{' '}
              <span className="text-xs font-normal text-slate-400">(one per line: phone number, optional name)</span>
            </label>
            <textarea
              value={raw}
              onChange={(e) => setRaw(e.target.value)}
              rows={8}
              placeholder={'919876543210, Vendor 1\n+91 91234 56789, Vendor 2'}
              className={`${inputClass} font-mono`}
            />
          </div>

          {error && <p className="text-sm text-red-600">{error}</p>}

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
              Import
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
