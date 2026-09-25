import { useCallback, useEffect, useState } from 'react';
import { MessageCircle, Radio } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import whatsappService from '../../services/whatsappService';
import QRScannerModal from '../../components/qr/QRScannerModal';
import MetaConfigCard from '../../components/settings/MetaConfigCard';
import type { WhatsAppStatus } from '../../types/whatsapp';

const STATUS_META: Record<WhatsAppStatus, { label: string; dot: string }> = {
  connected: { label: 'Connected', dot: 'bg-emerald-500' },
  disconnected: { label: 'Disconnected', dot: 'bg-red-500' },
  connecting: { label: 'Action needed — scan to connect', dot: 'bg-amber-400' },
};

export default function WhatsAppSetupPage() {
  const { user } = useAuth();
  const { selectedAccountId, selectedAccount } = useTenant();
  // Agent acting on a Sub-Client (or Super Admin on a client): every call
  // below AND the QRScannerModal socket must address the SAME account.
  // Previously the socket joined the caller's own account room while the
  // axios interceptor silently sent ?account_id=<selected> to
  // start-session, so the QR was broadcast to a room nobody was in.
  const effectiveAccountId = selectedAccountId ?? user?.account_id ?? null;
  const engineType =
    (selectedAccountId
      ? selectedAccount?.current_subscription?.engine_type
      : user?.account?.current_subscription?.engine_type) ?? null;

  const [status, setStatus] = useState<WhatsAppStatus>('disconnected');
  const [isLoadingStatus, setIsLoadingStatus] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const loadStatus = useCallback(async () => {
    setIsLoadingStatus(true);
    try {
      const res = await whatsappService.status(effectiveAccountId ?? undefined);
      setStatus(res.status);
    } catch {
      // Leave status as-is — the page still renders, just possibly stale.
    } finally {
      setIsLoadingStatus(false);
    }
  }, [effectiveAccountId]);

  useEffect(() => {
    if (engineType === 'qr') {
      void loadStatus();
    } else {
      setIsLoadingStatus(false);
    }
  }, [engineType, loadStatus]);

  const showToast = (message: string) => {
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  };

  const handleConnected = () => {
    setStatus('connected');
    setIsModalOpen(false);
    showToast('WhatsApp connected successfully.');
  };

  const handleLogout = async () => {
    setIsLoggingOut(true);
    try {
      await whatsappService.logout(effectiveAccountId ?? undefined);
      setStatus('disconnected');
      showToast('WhatsApp disconnected.');
    } catch {
      showToast('Failed to disconnect. Please try again.');
    } finally {
      setIsLoggingOut(false);
    }
  };

  const meta = STATUS_META[status];

  return (
    <div className="p-6">
      <div className="w-full">
        <h1 className="text-xl font-semibold text-slate-900">WhatsApp Setup</h1>
        <p className="mt-1 text-sm text-slate-500">Manage how this account connects to WhatsApp.</p>

        <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                <MessageCircle className="h-5 w-5" />
              </div>
              <div>
                <p className="text-sm font-semibold text-slate-900">Engine</p>
                <p className="text-sm text-slate-500">
                  {engineType === 'meta'
                    ? 'Meta Cloud API (Official)'
                    : engineType === 'qr'
                      ? 'QR (Baileys, Unofficial)'
                      : 'Not configured'}
                </p>
              </div>
            </div>
            {engineType && (
              <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium uppercase tracking-wide text-slate-600">
                {engineType}
              </span>
            )}
          </div>

          {engineType === 'meta' && <MetaConfigCard />}

          {engineType === 'qr' && (
            <div className="mt-6 border-t border-slate-200 pt-6">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span className={`h-2.5 w-2.5 rounded-full ${meta.dot}`} />
                  <span className="text-sm font-medium text-slate-900">
                    {isLoadingStatus ? 'Checking status…' : meta.label}
                  </span>
                </div>

                {status === 'connected' ? (
                  <button
                    onClick={() => void handleLogout()}
                    disabled={isLoggingOut}
                    className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
                  >
                    {isLoggingOut ? 'Disconnecting…' : 'Disconnect'}
                  </button>
                ) : (
                  <button
                    onClick={() => setIsModalOpen(true)}
                    className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                  >
                    <Radio className="h-4 w-4" />
                    Connect WhatsApp
                  </button>
                )}
              </div>
            </div>
          )}

          {!engineType && !isLoadingStatus && (
            <p className="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
              No subscription/engine is configured for this account yet.
            </p>
          )}
        </div>
      </div>

      {isModalOpen && effectiveAccountId !== null && (
        <QRScannerModal
          accountId={effectiveAccountId}
          onClose={() => setIsModalOpen(false)}
          onConnected={handleConnected}
        />
      )}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
