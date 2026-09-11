import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertCircle, Building2, Camera, Inbox, Loader2, MessageSquare, Send, Target } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import inboxService from '../../services/inboxService';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import { INBOX_PLATFORM_LABELS } from '../../types/inbox';
import type { InboxMessage, InboxPlatform, InboxThread } from '../../types/inbox';
import { ClearFiltersButton, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';

/**
 * Global Table Filters refactor — Social Inbox had no filter controls at
 * all before this (a two-pane thread list, not a paginated table). Added
 * a minimal search (participant name / snippet) + platform filter over
 * the thread list, following the same shared-component pattern as every
 * other tabular view, so "Clear Filters" behaves identically everywhere.
 */
const INBOX_PLATFORM_OPTIONS = Object.entries(INBOX_PLATFORM_LABELS).map(([value, label]) => ({ value, label }));

const PLATFORM_ICON: Record<InboxPlatform, typeof MessageSquare> = {
  facebook: MessageSquare,
  instagram: Camera,
  lead: Target,
};

const PLATFORM_TINT: Record<InboxPlatform, { bg: string; fg: string }> = {
  facebook: { bg: '#E3F0FF', fg: '#2563EB' },
  instagram: { bg: '#FCE7F6', fg: '#C0269C' },
  lead: { bg: '#FEF3D9', fg: '#B45309' },
};

function formatTime(value: string | null): string {
  if (!value) return '';
  return new Date(value).toLocaleString();
}

function PlatformBadge({ platform }: { platform: InboxPlatform }) {
  const Icon = PLATFORM_ICON[platform];
  const tint = PLATFORM_TINT[platform];
  return (
    <span
      className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg"
      style={{ background: tint.bg, color: tint.fg }}
      title={INBOX_PLATFORM_LABELS[platform]}
    >
      <Icon className="h-3.5 w-3.5" />
    </span>
  );
}

export default function SocialInboxPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

  const [threads, setThreads] = useState<InboxThread[]>([]);
  const [isLoadingThreads, setIsLoadingThreads] = useState(true);
  const [threadsError, setThreadsError] = useState<string | null>(null);
  const [selectedThreadId, setSelectedThreadId] = useState<string | null>(null);

  const [messages, setMessages] = useState<InboxMessage[]>([]);
  const [isLoadingMessages, setIsLoadingMessages] = useState(false);
  const [messagesError, setMessagesError] = useState<string | null>(null);

  const [draft, setDraft] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);

  const [threadSearch, setThreadSearch] = useState('');
  const [platformFilter, setPlatformFilter] = useState('');

  const loadThreads = useCallback(() => {
    if (noTenantSelected) {
      setThreads([]);
      setIsLoadingThreads(false);
      return;
    }

    setIsLoadingThreads(true);
    setThreadsError(null);
    inboxService
      .listThreads()
      .then((data) => {
        setThreads(data);
        setSelectedThreadId((prev) => prev ?? data[0]?.id ?? null);
      })
      .catch((err: unknown) => setThreadsError(extractErrorMessage(err, 'Failed to load conversations.')))
      .finally(() => setIsLoadingThreads(false));
  }, [noTenantSelected]);

  useEffect(() => {
    loadThreads();
  }, [loadThreads, selectedAccountId]);

  useEffect(() => {
    if (!selectedThreadId) {
      setMessages([]);
      return;
    }

    setIsLoadingMessages(true);
    setMessagesError(null);
    inboxService
      .getMessages(selectedThreadId)
      .then(setMessages)
      .catch((err: unknown) => setMessagesError(extractErrorMessage(err, 'Failed to load this conversation.')))
      .finally(() => setIsLoadingMessages(false));
  }, [selectedThreadId]);

  const selectedThread = useMemo(() => threads.find((t) => t.id === selectedThreadId) ?? null, [threads, selectedThreadId]);

  const filteredThreads = useMemo(() => {
    const term = threadSearch.trim().toLowerCase();
    return threads.filter((t) => {
      if (platformFilter && t.platform !== platformFilter) return false;
      if (term && !t.participant_name.toLowerCase().includes(term) && !t.snippet.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [threads, threadSearch, platformFilter]);

  const hasActiveThreadFilters = threadSearch !== '' || platformFilter !== '';
  const clearThreadFilters = () => {
    setThreadSearch('');
    setPlatformFilter('');
  };

  const handleSend = () => {
    if (!selectedThreadId || !draft.trim()) return;

    setIsSending(true);
    setSendError(null);
    inboxService
      .send({ thread_id: selectedThreadId, message: draft.trim() })
      .then(() => {
        setMessages((prev) => [
          ...prev,
          { id: `local-${Date.now()}`, from_me: true, text: draft.trim(), created_at: new Date().toISOString() },
        ]);
        setDraft('');
      })
      .catch((err: unknown) => setSendError(extractErrorMessage(err, 'Failed to send the message.')))
      .finally(() => setIsSending(false));
  };

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6">
          <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
            Social Inbox
          </h1>
          <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
            Facebook DMs, Instagram DMs, and Lead form inquiries — all in one place.
          </p>
        </div>

        {noTenantSelected ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to view their inbox.
          </div>
        ) : (
          <div className="flex overflow-hidden rounded-2xl border" style={{ borderColor: indigo.border, height: 'calc(100vh - 220px)', minHeight: 420 }}>
            <div className="flex w-72 flex-shrink-0 flex-col border-r bg-white" style={{ borderColor: indigo.border }}>
              <div className="border-b px-4 py-3 text-xs font-semibold uppercase tracking-wide" style={{ borderColor: indigo.border, color: indigo.muted }}>
                Active Threads
              </div>
              <div className="flex flex-col gap-2 border-b px-3 py-2.5" style={{ borderColor: indigo.border }}>
                <SearchInput value={threadSearch} onChange={setThreadSearch} placeholder="Search threads…" />
                <div className="flex items-center gap-2">
                  <StatusFilterSelect value={platformFilter} onChange={setPlatformFilter} options={INBOX_PLATFORM_OPTIONS} allLabel="All platforms" />
                  <ClearFiltersButton active={hasActiveThreadFilters} onClear={clearThreadFilters} />
                </div>
              </div>
              <div className="flex-1 overflow-y-auto">
                {isLoadingThreads ? (
                  <div className="flex items-center justify-center py-10">
                    <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                  </div>
                ) : threadsError ? (
                  <div className="m-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                    {threadsError}
                  </div>
                ) : filteredThreads.length === 0 ? (
                  <p className="px-4 py-8 text-center text-xs" style={{ color: indigo.muted }}>
                    {threads.length === 0 ? 'No conversations yet.' : 'No conversations match the current filters.'}
                  </p>
                ) : (
                  filteredThreads.map((thread) => (
                    <button
                      key={thread.id}
                      type="button"
                      onClick={() => setSelectedThreadId(thread.id)}
                      className={`flex w-full items-start gap-2.5 border-b px-4 py-3 text-left hover:bg-slate-50 ${
                        thread.id === selectedThreadId ? 'bg-indigo-50/60' : ''
                      }`}
                      style={{ borderColor: indigo.border }}
                    >
                      <PlatformBadge platform={thread.platform} />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <p className="truncate text-sm font-medium" style={{ color: indigo.ink }}>
                            {thread.participant_name}
                          </p>
                          {thread.unread && <span className="h-2 w-2 flex-shrink-0 rounded-full bg-indigo-500" />}
                        </div>
                        <p className="truncate text-xs" style={{ color: indigo.muted }}>
                          {thread.snippet}
                        </p>
                      </div>
                    </button>
                  ))
                )}
              </div>
            </div>

            <div className="flex flex-1 flex-col bg-slate-50">
              {!selectedThread ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2" style={{ color: indigo.muted }}>
                  <Inbox className="h-8 w-8" />
                  <p className="text-sm">Select a conversation to view messages.</p>
                </div>
              ) : (
                <>
                  <div className="flex items-center gap-2.5 border-b bg-white px-4 py-3" style={{ borderColor: indigo.border }}>
                    <PlatformBadge platform={selectedThread.platform} />
                    <div>
                      <p className="text-sm font-semibold" style={{ color: indigo.ink }}>
                        {selectedThread.participant_name}
                      </p>
                      <p className="text-xs" style={{ color: indigo.muted }}>
                        {INBOX_PLATFORM_LABELS[selectedThread.platform]}
                      </p>
                    </div>
                  </div>

                  <div className="flex-1 space-y-3 overflow-y-auto p-4">
                    {isLoadingMessages ? (
                      <div className="flex items-center justify-center py-10">
                        <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                      </div>
                    ) : messagesError ? (
                      <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                        {messagesError}
                      </div>
                    ) : (
                      messages.map((m) => (
                        <div key={m.id} className={`flex ${m.from_me ? 'justify-end' : 'justify-start'}`}>
                          <div
                            className="max-w-[70%] whitespace-pre-wrap rounded-2xl px-3.5 py-2 text-sm shadow-sm"
                            style={
                              m.from_me
                                ? { background: activeGradient, color: '#fff' }
                                : { background: '#fff', color: indigo.ink, border: `1px solid ${indigo.border}` }
                            }
                          >
                            {m.text}
                            <p className={`mt-1 text-[10px] ${m.from_me ? 'text-white/70' : ''}`} style={m.from_me ? undefined : { color: indigo.muted }}>
                              {formatTime(m.created_at)}
                            </p>
                          </div>
                        </div>
                      ))
                    )}
                  </div>

                  <div className="border-t bg-white p-3" style={{ borderColor: indigo.border }}>
                    {sendError && <p className="mb-2 text-xs text-red-600">{sendError}</p>}
                    <div className="flex items-end gap-2">
                      <textarea
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            handleSend();
                          }
                        }}
                        rows={2}
                        placeholder="Type a reply…"
                        className="flex-1 resize-none rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                      />
                      <button
                        type="button"
                        onClick={handleSend}
                        disabled={isSending || !draft.trim()}
                        className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-white disabled:opacity-60"
                        style={{ background: activeGradient }}
                        aria-label="Send"
                      >
                        {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                      </button>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
