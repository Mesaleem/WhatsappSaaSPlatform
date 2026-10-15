import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Inbox, Loader2, MessageCircle, Send } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { SelectClientNotice } from '../../components/common/ActionGate';
import { useClientGate } from '../../components/common/actionGateHooks';
import { SearchInput } from '../../components/common/DataTableControls';
import DismissibleAlert from '../../components/common/DismissibleAlert';
import whatsappInboxService from '../../services/whatsappInboxService';
import { extractErrorMessage } from '../../utils/apiError';
import type { WaMessage, WaThread } from '../../types/whatsappInbox';

/**
 * WhatsApp Inbox (QA fixes, 2026-10-14) — every customer conversation on the
 * client's WhatsApp number(s): what the customer wrote and everything the
 * platform sent them (notifications, templates, chatbot, journeys, replies).
 * The client answers from here. On the Meta API a free-text reply is only
 * possible within 24 hours of the customer's last message; after that the
 * box explains to send a template from Send Notification.
 *
 * Pattern A layout. Refreshes every 15 seconds while open.
 */
const POLL_MS = 15000;

function formatTime(value: string | null): string {
  if (!value) return '';
  const d = new Date(value);
  const today = new Date();
  return d.toDateString() === today.toDateString()
    ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function displayName(t: WaThread): string {
  return t.contact_name ? `${t.contact_name}` : `+${t.contact_phone}`;
}

export default function WhatsAppInboxPage() {
  const { isSuperAdmin, isReadOnly } = useAuth();
  const { selectedAccountId } = useTenant();
  const noTenantSelected = isSuperAdmin() && selectedAccountId === null;
  const { openPicker, picker } = useClientGate();
  const readOnly = isReadOnly();

  const [threads, setThreads] = useState<WaThread[]>([]);
  const [search, setSearch] = useState('');
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [isLoadingThreads, setIsLoadingThreads] = useState(true);
  const [threadsError, setThreadsError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const [thread, setThread] = useState<WaThread | null>(null);
  const [messages, setMessages] = useState<WaMessage[]>([]);
  const [isLoadingMessages, setIsLoadingMessages] = useState(false);
  const [messagesError, setMessagesError] = useState<string | null>(null);

  const [draft, setDraft] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);

  const loadThreads = useCallback(
    (quiet = false) => {
      if (noTenantSelected) {
        setThreads([]);
        setIsLoadingThreads(false);
        return;
      }
      if (!quiet) setIsLoadingThreads(true);
      whatsappInboxService
        .threads({ search: search.trim() || undefined, unread: unreadOnly })
        .then((res) => {
          setThreads(res.data);
          setThreadsError(null);
          setSelectedId((prev) => prev ?? res.data[0]?.id ?? null);
        })
        .catch((err: unknown) => {
          if (!quiet) setThreadsError(extractErrorMessage(err, 'Failed to load conversations.'));
        })
        .finally(() => setIsLoadingThreads(false));
    },
    [noTenantSelected, search, unreadOnly],
  );

  const loadMessages = useCallback((id: number, quiet = false) => {
    if (!quiet) setIsLoadingMessages(true);
    whatsappInboxService
      .messages(id)
      .then((res) => {
        setThread(res.thread);
        setMessages(res.data);
        setMessagesError(null);
        setThreads((prev) => prev.map((t) => (t.id === id ? { ...t, unread_count: 0 } : t)));
      })
      .catch((err: unknown) => {
        if (!quiet) setMessagesError(extractErrorMessage(err, 'Failed to load this conversation.'));
      })
      .finally(() => setIsLoadingMessages(false));
  }, []);

  useEffect(() => {
    loadThreads();
  }, [loadThreads, selectedAccountId]);

  useEffect(() => {
    if (selectedId === null) {
      setThread(null);
      setMessages([]);
      return;
    }
    loadMessages(selectedId);
  }, [selectedId, loadMessages]);

  // Keep the inbox fresh while it is open.
  useEffect(() => {
    if (noTenantSelected) return undefined;
    const timer = window.setInterval(() => {
      loadThreads(true);
      if (selectedId !== null) loadMessages(selectedId, true);
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [noTenantSelected, loadThreads, loadMessages, selectedId]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView?.({ block: 'end' });
  }, [messages.length]);

  const handleSend = () => {
    if (selectedId === null || !draft.trim()) return;
    setIsSending(true);
    setSendError(null);
    whatsappInboxService
      .reply(selectedId, draft.trim())
      .then(() => {
        setDraft('');
        loadMessages(selectedId, true);
        loadThreads(true);
      })
      .catch((err: unknown) => setSendError(extractErrorMessage(err, 'The reply could not be sent.')))
      .finally(() => setIsSending(false));
  };

  const canReply = thread?.can_reply_freely !== false;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">WhatsApp Inbox</h1>
          <p className="mt-1 text-sm text-slate-500">Your customers&apos; WhatsApp messages and everything you sent them. Reply right here.</p>
        </div>

        {picker}
        {noTenantSelected ? (
          <SelectClientNotice message="WhatsApp conversations belong to a client. Select one to view its inbox." onSelect={() => openPicker('view the inbox')} />
        ) : (
          <div className="flex overflow-hidden rounded-2xl border border-slate-200 bg-white" style={{ height: 'calc(100vh - 220px)', minHeight: 420 }}>
            <div className={`${selectedId !== null ? 'hidden md:flex' : 'flex'} w-full flex-shrink-0 flex-col border-r border-slate-200 md:w-80`}>
              <div className="flex flex-col gap-2 border-b border-slate-200 px-3 py-2.5">
                <SearchInput value={search} onChange={setSearch} placeholder="Search name or number…" />
                <label className="flex items-center gap-2 text-xs text-slate-600">
                  <input type="checkbox" checked={unreadOnly} onChange={(e) => setUnreadOnly(e.target.checked)} className="h-3.5 w-3.5 rounded border-slate-300 text-emerald-700" />
                  Unread only
                </label>
              </div>
              <div className="flex-1 overflow-y-auto">
                {isLoadingThreads ? (
                  <div className="flex items-center justify-center py-10">
                    <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
                  </div>
                ) : threadsError ? (
                  <DismissibleAlert className="m-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                    {threadsError}
                  </DismissibleAlert>
                ) : threads.length === 0 ? (
                  <p className="px-4 py-8 text-center text-xs text-slate-500">
                    {search || unreadOnly ? 'No conversations match.' : 'No WhatsApp conversations yet. They appear here when a customer messages you or you send them something.'}
                  </p>
                ) : (
                  threads.map((t) => (
                    <button
                      key={t.id}
                      type="button"
                      onClick={() => setSelectedId(t.id)}
                      className={`flex w-full items-start gap-2.5 border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50 ${t.id === selectedId ? 'bg-emerald-50/60' : ''}`}
                      data-testid="wa-thread"
                    >
                      <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
                        <MessageCircle className="h-4 w-4" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <p className={`truncate text-sm ${t.unread_count > 0 ? 'font-semibold text-slate-900' : 'font-medium text-slate-800'}`}>{displayName(t)}</p>
                          <span className="flex-shrink-0 text-[10px] text-slate-400">{formatTime(t.last_message_at)}</span>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                          <p className="truncate text-xs text-slate-500">
                            {t.last_direction === 'out' ? 'You: ' : ''}
                            {t.last_message_preview}
                          </p>
                          {t.unread_count > 0 && (
                            <span className="flex-shrink-0 rounded-full bg-emerald-600 px-1.5 text-[10px] font-semibold text-white">{t.unread_count}</span>
                          )}
                        </div>
                      </div>
                    </button>
                  ))
                )}
              </div>
            </div>

            <div className={`${selectedId === null ? 'hidden md:flex' : 'flex'} flex-1 flex-col bg-slate-50`}>
              {selectedId === null || !thread ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 text-slate-400">
                  {isLoadingMessages ? <Loader2 className="h-5 w-5 animate-spin" /> : <Inbox className="h-8 w-8" />}
                  {!isLoadingMessages && <p className="text-sm">Select a conversation.</p>}
                </div>
              ) : (
                <>
                  <div className="flex items-center gap-2.5 border-b border-slate-200 bg-white px-4 py-3">
                    <button type="button" onClick={() => setSelectedId(null)} className="text-xs font-medium text-emerald-700 md:hidden">
                      ← Back
                    </button>
                    <div>
                      <p className="text-sm font-semibold text-slate-900">{displayName(thread)}</p>
                      <p className="text-xs text-slate-500">+{thread.contact_phone}</p>
                    </div>
                  </div>

                  <div className="flex-1 space-y-2 overflow-y-auto p-4" data-testid="wa-messages">
                    {messagesError ? (
                      <DismissibleAlert className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                        {messagesError}
                      </DismissibleAlert>
                    ) : (
                      messages.map((m) => (
                        <div key={m.id} className={`flex ${m.direction === 'out' ? 'justify-end' : 'justify-start'}`}>
                          <div
                            className={`max-w-[75%] whitespace-pre-wrap rounded-2xl px-3.5 py-2 text-sm shadow-sm ${
                              m.direction === 'out' ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-900'
                            }`}
                          >
                            {m.body}
                            <p className={`mt-1 text-[10px] ${m.direction === 'out' ? 'text-white/75' : 'text-slate-400'}`}>
                              {formatTime(m.created_at)}
                              {m.direction === 'out' && m.source && m.source !== 'inbox' ? ` · ${m.source.replace(/_/g, ' ')}` : ''}
                            </p>
                          </div>
                        </div>
                      ))
                    )}
                    <div ref={bottomRef} />
                  </div>

                  <div className="border-t border-slate-200 bg-white p-3">
                    {!canReply ? (
                      <p className="text-xs text-amber-800" data-testid="wa-window-closed">
                        More than 24 hours have passed since this customer last wrote, so WhatsApp only allows an approved template now.{' '}
                        <Link to="/alerts/send" className="font-semibold underline">
                          Send a template
                        </Link>
                      </p>
                    ) : (
                      <>
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
                            disabled={readOnly}
                            placeholder={readOnly ? 'Replies are disabled until your plan is renewed.' : 'Type a reply…'}
                            className="flex-1 resize-none rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50"
                            aria-label="Reply"
                          />
                          <button
                            type="button"
                            onClick={handleSend}
                            disabled={readOnly || isSending || !draft.trim()}
                            className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60"
                            aria-label="Send reply"
                          >
                            {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                          </button>
                        </div>
                      </>
                    )}
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
