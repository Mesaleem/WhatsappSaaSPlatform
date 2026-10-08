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
import senderNumberService from '../../services/senderNumberService';
import type { SenderNumber } from '../../types/senderNumber';
import type { AvailableNativeGroup, ContactGroup, ContactGroupContactInput, ContactGroupMemberRow, ContactGroupType } from '../../types/contactGroup';
import type { ApiErrorResponse } from '../../types/auth';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Card, TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import ModuleAddonCard from '../../components/common/ModuleAddonCard';
import PlanLockedAction from '../../components/common/PlanLockedAction';
import KeepGroupsModal from '../../components/groups/KeepGroupsModal';
import DismissibleAlert from '../../components/common/DismissibleAlert';

/** Same pattern as MessageLogsPage.tsx's extractMessage() — surfaces the backend's real error (e.g. GROUP_MODULE_DISABLED's exact copy) instead of a fixed generic string. */
function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — Contact Groups manager.
 *
 * [Re-scoped 2026-10-07, disclosed]: internal segment groups (a DB broadcast list — each member
 * gets their own DM) are now a free, always-on baseline feature for any user holding send-messages
 * — `moduleEnabled`/`hasModule('contact_groups')` below is therefore always true now (the backend's
 * Account::effectiveModules() always includes it), making the `!moduleEnabled` locked-state branch
 * below permanently dead code; left in place rather than torn out, since it's harmless and this
 * page's own history shows module gating has moved before. A Native WhatsApp Group remains the
 * chargeable, plan-limited half of this page — gated by the `whatsapp_groups` capability and a
 * numeric term allowance (see ContactGroupController's own re-scoping docblock), neither of which
 * `moduleEnabled` reflects any more.
 */
export default function ContactGroupsPage() {
  const { hasModule } = useAuth();
  const moduleEnabled = hasModule('contact_groups');

  const [groups, setGroups] = useState<ContactGroup[]>([]);
  const [usage, setUsage] = useState<{ used: number; limit: number | null; selection_required: boolean } | null>(null);
  const [choosingGroups, setChoosingGroups] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);

  // Replaces the browser-native confirm() popup for Delete/Recreate with
  // a modal styled like every other dialog in this app -- see
  // ConfirmModal's own docblock. Only one of these two actions can be
  // pending confirmation at once, so a single slot (rather than two
  // separate ContactGroup|null states) is enough.
  const [pendingConfirm, setPendingConfirm] = useState<{ kind: 'delete' | 'recreate'; group: ContactGroup } | null>(null);
  const [isConfirming, setIsConfirming] = useState(false);

  // "Manage Members" -- view/add/remove a group's members. Internal segment groups only for
  // add/remove; a Native WhatsApp Group's real member list is shown read-only (see
  // ManageMembersModal's own docblock).
  const [managingGroup, setManagingGroup] = useState<ContactGroup | null>(null);

  const load = useCallback(async () => {
    if (!moduleEnabled) {
      setIsLoading(false);
      return;
    }
    setIsLoading(true);
    setError(null);
    try {
      const res = await contactGroupsService.listWithUsage();
      setGroups(res.data);
      setUsage(res.usage);
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

  const handleDelete = (group: ContactGroup) => {
    if (group.is_default) return;
    setPendingConfirm({ kind: 'delete', group });
  };

  /**
   * [Disclosed]: this ALWAYS creates a brand-new WhatsApp group — it can
   * never confirm the old one is actually gone (see
   * ContactGroupController::recreate()'s docblock). The confirmation
   * modal exists specifically to surface that duplicate-group risk
   * before the user commits to it, since a "Sync failed" badge can come
   * from a transient send error, not only an actual deletion.
   */
  const handleRecreate = (group: ContactGroup) => {
    setPendingConfirm({ kind: 'recreate', group });
  };

  const handleConfirmed = async () => {
    if (!pendingConfirm) return;
    const { kind, group } = pendingConfirm;
    setIsConfirming(true);
    try {
      if (kind === 'delete') {
        await contactGroupsService.remove(group.id);
      } else {
        await contactGroupsService.recreate(group.id);
      }
      setPendingConfirm(null);
      void load();
    } catch (err) {
      setError(extractMessage(err, kind === 'delete' ? 'Failed to delete this group.' : 'Failed to recreate this group.'));
      setPendingConfirm(null);
    } finally {
      setIsConfirming(false);
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

      {/* Native WhatsApp Groups is the chargeable, plan-limited half of this feature (internal
          segment groups are free and unlimited — see ContactGroupController's own re-scoping
          docblock); this card/usage banner is about native groups only. */}
      <ModuleAddonCard module="contact_groups" enabled={usage?.limit != null} unitLabel="group chats" />

      {usage && usage.limit !== null && (
        <p className="mb-4 text-sm text-slate-600" data-testid="group-usage">
          <strong className="text-slate-900">
            {usage.used} of {usage.limit}
          </strong>{' '}
          native WhatsApp groups used.
          {usage.used >= usage.limit && (
            <span className="ml-1 text-amber-700">All native groups in your plan are in use. Upgrade to add more.</span>
          )}
        </p>
      )}

      {usage?.selection_required && usage.limit !== null && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <p>
            Your plan now includes {usage.limit} native WhatsApp {usage.limit === 1 ? 'group' : 'groups'}. Choose which ones stay open for this term.
          </p>
          <button
            type="button"
            onClick={() => setChoosingGroups(true)}
            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
          >
            Choose groups
          </button>
        </div>
      )}

      {choosingGroups && usage?.limit != null && (
        <KeepGroupsModal
          groups={groups.filter((g) => !g.is_default && g.group_type === 'native_wa_group')}
          limit={usage.limit}
          onClose={() => setChoosingGroups(false)}
          onSaved={() => {
            setChoosingGroups(false);
            void load();
          }}
        />
      )}

      {!moduleEnabled ? (
        <LockedCard />
      ) : (
        <>
          {error && (
            <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</DismissibleAlert>
          )}

          <TableCard>
            <table className="w-full min-w-[820px] divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-3">Name</th>
                  <th className="px-4 py-3">Group Code</th>
                  <th className="px-4 py-3">Members</th>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">WhatsApp Group</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                      Loading…
                    </td>
                  </tr>
                ) : groups.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
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
                      <td className="px-4 py-3">
                        <GroupCodeCell group={group} />
                      </td>
                      <td className="px-4 py-3 text-slate-600">{group.members_count}</td>
                      <td className="px-4 py-3">
                        <GroupTypeBadge type={group.group_type} />
                      </td>
                      <td className="px-4 py-3">
                        {group.group_type === 'native_wa_group' ? (
                          <NativeGroupCell group={group} onRecreate={() => handleRecreate(group)} />
                        ) : (
                          <span className="text-xs text-slate-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={() => setManagingGroup(group)}
                          title="Manage members"
                          className="rounded-lg p-1.5 text-slate-400 hover:bg-indigo-50 hover:text-indigo-600"
                        >
                          <Users className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => handleDelete(group)}
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
          // Native WhatsApp Groups are excluded here -- their membership is WhatsApp-only now (see
          // ContactGroupController::addContacts()'s re-scoping docblock); there is no group in this
          // list a native group's row could ever be imported into successfully.
          groups={groups.filter((g) => g.group_type === 'internal_segment')}
          onClose={() => setShowImport(false)}
          onImported={() => {
            setShowImport(false);
            void load();
          }}
        />
      )}

      {pendingConfirm && (
        <ConfirmModal
          title={pendingConfirm.kind === 'delete' ? 'Delete contact group' : 'Recreate WhatsApp group'}
          message={
            pendingConfirm.kind === 'delete' ? (
              pendingConfirm.group.group_type === 'native_wa_group' ? (
                <>
                  Delete "{pendingConfirm.group.name}"? This removes it from this app only — the real WhatsApp group
                  on your phone is <strong>NOT</strong> affected.
                </>
              ) : (
                <>Delete "{pendingConfirm.group.name}"? This cannot be undone.</>
              )
            ) : (
              <>
                Recreate "{pendingConfirm.group.name}"? This creates a brand-new WhatsApp group with the same
                members. If the old group still exists on WhatsApp, it will <strong>NOT</strong> be deleted — you may
                end up with two groups.
              </>
            )
          }
          confirmLabel={pendingConfirm.kind === 'delete' ? 'Delete' : 'Recreate'}
          variant={pendingConfirm.kind === 'delete' ? 'danger' : 'default'}
          isLoading={isConfirming}
          onConfirm={() => void handleConfirmed()}
          onCancel={() => setPendingConfirm(null)}
        />
      )}

      {managingGroup && (
        <ManageMembersModal
          group={managingGroup}
          onClose={() => setManagingGroup(null)}
          onChanged={() => void load()}
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

/** Group Code cell — mirrors TemplateManagerPage's template_code copy-pill pattern for the Developer API's group_code identifier. */
function GroupCodeCell({ group }: { group: ContactGroup }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    if (!group.group_code) return;
    void navigator.clipboard.writeText(group.group_code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  if (!group.group_code) {
    return <span className="text-xs text-slate-400">—</span>;
  }

  return (
    <button
      type="button"
      onClick={handleCopy}
      title="Copy group_code"
      className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-mono text-[11px] text-slate-700 hover:bg-slate-200"
    >
      {group.group_code}
      {copied ? <Check className="h-3 w-3 text-emerald-600" /> : <Copy className="h-3 w-3 text-slate-400" />}
    </button>
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
  // Which linked number a Custom Contact Group belongs to; it is sent only from that number.
  const [numbers, setNumbers] = useState<SenderNumber[]>([]);
  const [numberId, setNumberId] = useState<number | ''>('');

  useEffect(() => {
    senderNumberService
      .list()
      .then((list) => {
        setNumbers(list);
        setNumberId((prev) => prev || (list.find((n) => n.is_default) ?? list[0])?.id || '');
      })
      .catch(() => undefined);
  }, []);
  const [rawContacts, setRawContacts] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // "Select an existing group" extension: only meaningful once
  // groupType === 'native_wa_group' — a plain internal_segment list has
  // no "already exists on WhatsApp" counterpart to pick from.
  const [nativeSource, setNativeSource] = useState<'new' | 'existing'>('new');
  const [availableGroups, setAvailableGroups] = useState<AvailableNativeGroup[]>([]);
  const [isLoadingAvailable, setIsLoadingAvailable] = useState(false);
  const [availableError, setAvailableError] = useState<string | null>(null);
  // True when the plan does not include native groups (the server's CAPABILITY_NOT_ENTITLED refusal).
  const [availableLocked, setAvailableLocked] = useState(false);
  const [selectedJid, setSelectedJid] = useState('');

  const loadAvailableGroups = useCallback(async () => {
    setIsLoadingAvailable(true);
    setAvailableError(null);
    setAvailableLocked(false);
    try {
      const data = await contactGroupsService.availableNative();
      setAvailableGroups(data);
    } catch (err) {
      const code = (err as { response?: { data?: { error_code?: string } } })?.response?.data?.error_code;
      if (code === 'CAPABILITY_NOT_ENTITLED') {
        setAvailableLocked(true);
      } else {
        setAvailableError(extractMessage(err, 'Could not list your existing WhatsApp groups.'));
      }
    } finally {
      setIsLoadingAvailable(false);
    }
  }, []);

  const isExistingMode = groupType === 'native_wa_group' && nativeSource === 'existing';

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();

    if (isExistingMode) {
      if (!selectedJid) {
        setError('Pick one of your existing WhatsApp groups to import.');
        return;
      }
      setIsSaving(true);
      setError(null);
      try {
        await contactGroupsService.importNative({ group_jid: selectedJid, name: name.trim() || undefined });
        onCreated();
      } catch (err) {
        setError(extractMessage(err, 'Could not import this WhatsApp group.'));
      } finally {
        setIsSaving(false);
      }
      return;
    }

    if (!name.trim()) {
      setError('Group name is required.');
      return;
    }

    // Internal segment: starting members are optional (an empty textarea just means "start empty",
    // the same as before this field existed). Native: required -- WhatsApp has no empty-group concept.
    const parsedContacts = parseContactLines(rawContacts);
    const contacts = parsedContacts.length > 0 ? parsedContacts : undefined;
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
        ...(groupType === 'internal_segment' && numberId ? { whatsapp_number_id: numberId } : {}),
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
              Name {!isExistingMode && <span className="text-red-500">*</span>}
              {isExistingMode && (
                <span className="text-xs font-normal text-slate-400"> (optional — defaults to the WhatsApp group's own name)</span>
              )}
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

          {groupType === 'internal_segment' && (
            <div>
              <label htmlFor="create-group-number" className="text-sm font-medium text-slate-700">
                WhatsApp number
              </label>
              {numbers.length === 0 ? (
                <p className="mt-1 text-xs text-amber-700">
                  No connected WhatsApp number. Connect one in WhatsApp Setup to pick a number for this group.
                </p>
              ) : (
                <>
                  <select
                    id="create-group-number"
                    value={numberId}
                    onChange={(e) => setNumberId(e.target.value ? Number(e.target.value) : '')}
                    className={inputClass}
                  >
                    {numbers.map((n) => (
                      <option key={n.id} value={n.id}>
                        {n.phone_number}
                        {n.is_default ? ' (default)' : ''}
                      </option>
                    ))}
                  </select>
                  <p className="mt-1 text-xs text-slate-500">This group is sent only from the chosen number.</p>
                </>
              )}
            </div>
          )}

          {groupType === 'internal_segment' && (
            <div>
              <label className="text-sm font-medium text-slate-700">
                Starting members <span className="text-xs font-normal text-slate-400">(optional — one per line: phone number, optional name)</span>
              </label>
              <textarea
                value={rawContacts}
                onChange={(e) => setRawContacts(e.target.value)}
                rows={5}
                placeholder={'919876543210, Vendor 1\n+91 91234 56789, Vendor 2'}
                className={`${inputClass} font-mono`}
              />
              <p className="mt-1 text-xs text-slate-500">
                Leave this empty to start with no members — add them later with "Manage Members".
              </p>
            </div>
          )}

          {groupType === 'native_wa_group' && (
            <>
              <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Requires an active WhatsApp connection on the QR (Baileys) engine. The official Meta Cloud API cannot
                create or message WhatsApp groups.
              </p>

              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  onClick={() => setNativeSource('new')}
                  className={`rounded-lg border px-3 py-2 text-left text-sm ${
                    nativeSource === 'new'
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                      : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-medium">Create New Group</div>
                  <div className="text-xs text-slate-500">Make a brand-new WhatsApp group from scratch.</div>
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setNativeSource('existing');
                    setSelectedJid('');
                    // Fetched from this click (the event that causes the
                    // mode change), not a useEffect keyed on nativeSource
                    // -- avoids a synchronous setState-in-effect chain for
                    // what's really a direct response to a user action.
                    void loadAvailableGroups();
                  }}
                  className={`rounded-lg border px-3 py-2 text-left text-sm ${
                    nativeSource === 'existing'
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                      : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-medium">Select Existing Group</div>
                  <div className="text-xs text-slate-500">Already have this group on WhatsApp? Import it instead.</div>
                </button>
              </div>

              {nativeSource === 'new' ? (
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
              ) : (
                <div>
                  <div className="flex items-center justify-between">
                    <label className="text-sm font-medium text-slate-700">
                      Your WhatsApp groups <span className="text-red-500">*</span>
                    </label>
                    <button
                      type="button"
                      onClick={() => void loadAvailableGroups()}
                      disabled={isLoadingAvailable}
                      className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-50"
                    >
                      <RefreshCw className={`h-3 w-3 ${isLoadingAvailable ? 'animate-spin' : ''}`} />
                      Refresh
                    </button>
                  </div>

                  {isLoadingAvailable ? (
                    <p className="mt-2 flex items-center gap-2 text-sm text-slate-500">
                      <Loader2 className="h-4 w-4 animate-spin" /> Looking up your WhatsApp groups…
                    </p>
                  ) : availableLocked ? (
                    <PlanLockedAction module="whatsapp_groups" featureLabel="Native WhatsApp Groups" />
                  ) : availableError ? (
                    <DismissibleAlert as="p" className="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                      {availableError}
                    </DismissibleAlert>
                  ) : availableGroups.length === 0 ? (
                    <p className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                      No importable WhatsApp groups found — either you have none yet, or every group your connected
                      number belongs to has already been imported here.
                    </p>
                  ) : (
                    <div className="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-1.5">
                      {availableGroups.map((g) => (
                        <button
                          type="button"
                          key={g.jid}
                          onClick={() => setSelectedJid(g.jid)}
                          className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm ${
                            selectedJid === g.jid ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50'
                          }`}
                        >
                          <span className="font-medium">{g.subject}</span>
                          <span className="text-xs text-slate-400">{g.participants_count} members</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}
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
              disabled={isSaving || (isExistingMode && (isLoadingAvailable || availableGroups.length === 0))}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isExistingMode ? 'Import Group' : 'Create Group'}
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

/** `groups` passed in must already exclude Native WhatsApp Groups -- see this component's one caller. */
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
                <option key={g.id} value={g.id} disabled={!!g.locked_at}>
                  {g.name}
                  {g.is_default ? ' (Default)' : ''}
                  {g.locked_at ? ' (Locked for this term)' : ''}
                </option>
              ))}
            </select>
          </div>

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

/**
 * "Manage Members" -- opened per group from the table's new Users icon button. An internal segment
 * group is fully editable here (add via a textarea, same parseContactLines() convention as
 * everywhere else; remove one at a time). A Native WhatsApp Group's member list is shown read-only
 * -- its real membership only ever changes from WhatsApp itself, on the tenant's own phone, exactly
 * like any other WhatsApp group (see ContactGroupController::addContacts()/removeContact()'s own
 * re-scoping docblocks); this view exists so the tenant can still SEE who's in it.
 */
function ManageMembersModal({
  group,
  onClose,
  onChanged,
}: {
  group: ContactGroup;
  onClose: () => void;
  onChanged: () => void;
}) {
  const isNative = group.group_type === 'native_wa_group';

  const [members, setMembers] = useState<ContactGroupMemberRow[] | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const [rawContacts, setRawContacts] = useState('');
  const [isAdding, setIsAdding] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);

  const [removingId, setRemovingId] = useState<number | null>(null);

  const load = useCallback(
    (targetPage: number) => {
      setMembers(null);
      setError(null);
      contactGroupsService
        .listMembers(group.id, targetPage)
        .then((res) => {
          setMembers(res.data);
          setPage(res.meta.current_page);
          setLastPage(res.meta.last_page);
        })
        .catch((err: unknown) => setError(extractMessage(err, 'Failed to load members.')));
    },
    [group.id],
  );

  useEffect(() => {
    load(1);
  }, [load]);

  const handleAdd = async () => {
    const contacts = parseContactLines(rawContacts);
    if (contacts.length === 0) {
      setAddError('Enter at least one phone number.');
      return;
    }
    setIsAdding(true);
    setAddError(null);
    try {
      await contactGroupsService.addContacts(group.id, contacts);
      setRawContacts('');
      load(1);
      onChanged();
    } catch (err) {
      setAddError(extractMessage(err, 'Could not add these members.'));
    } finally {
      setIsAdding(false);
    }
  };

  const handleRemove = async (member: ContactGroupMemberRow) => {
    setRemovingId(member.id);
    setError(null);
    try {
      await contactGroupsService.removeMember(group.id, member.id);
      load(page);
      onChanged();
    } catch (err) {
      setError(extractMessage(err, 'Could not remove this member.'));
    } finally {
      setRemovingId(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
      <div className="my-8 flex max-h-[85vh] w-full max-w-lg flex-col rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Members — {group.name}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        {isNative && (
          <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            This is a real WhatsApp group — add or remove members from WhatsApp itself, on your phone. This list
            just shows who's in it.
          </p>
        )}

        {!isNative && (
          <div className="mt-4">
            <label className="text-sm font-medium text-slate-700">
              Add members <span className="text-xs font-normal text-slate-400">(one per line: phone number, optional name)</span>
            </label>
            <textarea
              value={rawContacts}
              onChange={(e) => setRawContacts(e.target.value)}
              rows={3}
              placeholder={'919876543210, Vendor 1'}
              className={`${inputClass} font-mono`}
            />
            {addError && <p className="mt-1 text-sm text-red-600">{addError}</p>}
            <div className="mt-2 flex justify-end">
              <button
                type="button"
                onClick={() => void handleAdd()}
                disabled={isAdding}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
              >
                {isAdding && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                Add
              </button>
            </div>
          </div>
        )}

        <div className="mt-4 flex-1 overflow-y-auto border-t border-slate-100 pt-3">
          {members === null && !error && (
            <div className="flex items-center gap-2 py-6 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading…
            </div>
          )}

          {error && <p className="text-sm text-red-600">{error}</p>}

          {members !== null && members.length === 0 && (
            <p className="py-6 text-center text-sm text-slate-400">No members yet.</p>
          )}

          {members !== null && members.length > 0 && (
            <ul className="divide-y divide-slate-100">
              {members.map((m) => (
                <li key={m.id} className="flex items-center justify-between gap-2 py-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm text-slate-900">{m.name || m.phone_number}</p>
                    {m.name && <p className="truncate text-xs text-slate-400">{m.phone_number}</p>}
                  </div>
                  {!isNative && (
                    <button
                      type="button"
                      onClick={() => void handleRemove(m)}
                      disabled={removingId === m.id}
                      title="Remove member"
                      className="flex-shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                    >
                      {removingId === m.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {members !== null && lastPage > 1 && (
          <div className="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => load(page - 1)}
              className="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              Previous
            </button>
            <span className="text-xs text-slate-500">Page {page} of {lastPage}</span>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => load(page + 1)}
              className="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              Next
            </button>
          </div>
        )}

        <div className="mt-4 flex justify-end">
          <button onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
