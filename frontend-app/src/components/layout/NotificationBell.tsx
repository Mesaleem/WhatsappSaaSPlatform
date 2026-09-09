import { useCallback, useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import notificationService from '../../services/notificationService';
import type { InAppNotification } from '../../types/notifications';
import { indigo } from '../../theme/signalIndigo';

const POLL_INTERVAL_MS = 60_000;

function timeAgo(iso: string): string {
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

/**
 * Header notification bell — Advanced Broadcast Engine's in-app
 * notification inbox. Deliberately POLLING-ONLY (no websocket/SSE): this
 * environment has no verified real-time transport (Module 4's Baileys
 * qr-engine-service is the only other "live" piece of this platform, and
 * it's a separate Node service, not something this React app can assume
 * a socket to), and adding one now would be new, unverifiable
 * infrastructure. A 60s poll for the unread count is a disclosed,
 * deliberately modest tradeoff — good enough for "you have a new
 * announcement", not meant to feel like chat.
 */
export default function NotificationBell() {
  const [unreadCount, setUnreadCount] = useState(0);
  const [isOpen, setIsOpen] = useState(false);
  const [items, setItems] = useState<InAppNotification[]>([]);
  const [isLoadingItems, setIsLoadingItems] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const refreshCount = useCallback(() => {
    notificationService
      .unreadCount()
      .then(setUnreadCount)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    refreshCount();
    const timer = setInterval(refreshCount, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [refreshCount]);

  useEffect(() => {
    const onClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  const openPanel = () => {
    setIsOpen((v) => !v);
    if (!isOpen) {
      setIsLoadingItems(true);
      notificationService
        .listInbox(1, 8)
        .then((res) => setItems(res.data))
        .catch(() => setItems([]))
        .finally(() => setIsLoadingItems(false));
    }
  };

  const handleMarkRead = async (id: number) => {
    setItems((prev) => prev.map((n) => (n.id === id ? { ...n, is_read: true } : n)));
    try {
      await notificationService.markRead(id);
      refreshCount();
    } catch {
      // Best-effort UI state; a failed mark-read just leaves the badge stale until the next poll.
    }
  };

  const handleMarkAllRead = async () => {
    setItems((prev) => prev.map((n) => ({ ...n, is_read: true })));
    setUnreadCount(0);
    try {
      await notificationService.markAllRead();
    } catch {
      refreshCount();
    }
  };

  return (
    <div ref={containerRef} className="relative">
      <button
        onClick={openPanel}
        aria-label="Notifications"
        className="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
      >
        <Bell className="h-4 w-4" />
        {unreadCount > 0 && (
          <span className="absolute right-1 top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      {isOpen && (
        <div
          className="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl bg-white shadow-xl"
          style={{ border: `1px solid ${indigo.border}` }}
        >
          <div className="flex items-center justify-between border-b px-3 py-2.5" style={{ borderColor: indigo.border }}>
            <span className="text-sm font-semibold" style={{ color: indigo.ink }}>
              Notifications
            </span>
            {items.some((n) => !n.is_read) && (
              <button onClick={() => void handleMarkAllRead()} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {isLoadingItems ? (
              <div className="px-4 py-6 text-center text-sm text-slate-400">Loading…</div>
            ) : items.length === 0 ? (
              <div className="px-4 py-6 text-center text-sm text-slate-400">You're all caught up.</div>
            ) : (
              items.map((n) => (
                <button
                  key={n.id}
                  onClick={() => void handleMarkRead(n.id)}
                  className={`block w-full border-b px-3 py-2.5 text-left text-sm last:border-b-0 hover:bg-slate-50 ${
                    n.is_read ? '' : 'bg-indigo-50/50'
                  }`}
                  style={{ borderColor: indigo.border }}
                >
                  <div className="flex items-start gap-2">
                    {!n.is_read && <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-indigo-600" />}
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium text-slate-900">{n.title}</div>
                      <div className="mt-0.5 text-xs text-slate-400">{timeAgo(n.created_at)}</div>
                    </div>
                  </div>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
