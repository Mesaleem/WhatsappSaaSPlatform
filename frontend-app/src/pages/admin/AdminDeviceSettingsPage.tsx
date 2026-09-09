import { useCallback, useEffect, useState } from 'react';
import { QrCode, Radio, RefreshCw } from 'lucide-react';
import whatsappService from '../../services/whatsappService';
import QRScannerModal from '../../components/qr/QRScannerModal';
import { Card, TableCard } from '../../components/common/Card';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage } from '../../utils/apiError';
import type { AdminWhatsAppDevice, WhatsAppStatus } from '../../types/whatsapp';

const STATUS_BADGE: Record<WhatsAppStatus, string> = {
  connected: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  connecting: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  disconnected: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

const STATUS_LABEL: Record<WhatsAppStatus, string> = {
  connected: 'Connected',
  connecting: 'Connecting',
  disconnected: 'Disconnected',
};

function formatLastConnected(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/**
 * Super Admin WhatsApp Device Integration — one screen listing every
 * tenant's WhatsApp device (WhatsAppController::adminIndex()), so a Super
 * Admin can link, view status, disconnect, or reconnect any client's
 * device without navigating into that client's own account. Link/
 * Disconnect/Reconnect here reuse the SAME per-tenant
 * QRScannerModal/whatsappService.logout() a Client Admin already uses on
 * WhatsAppSetupPage — just with an explicit accountId override per row
 * (see whatsappService.ts and QRScannerModal.tsx's accountId-passthrough
 * fix) instead of the header tenant selector. See adminIndex()'s docblock
 * for the disclosed "every tenant's device" interpretation of this
 * feature's spec.
 */
/**
 * Super Admin WhatsApp Device Integration — the Super Admin's OWN
 * scannable WhatsApp connection, separate from every tenant row in the
 * table below. MessageTemplateController::test() (Template Manager's
 * "Send Template" test action) always fires through THIS device, never a
 * client's — mirrors WhatsAppSetupPage's connect/status/disconnect
 * pattern almost exactly, just targeting
 * whatsappService.selfDeviceStatus()/selfDeviceStartSession()/
 * selfDeviceLogout() instead of a client account_id.
 */
function SuperAdminTestDeviceCard() {
  const [accountId, setAccountId] = useState<number | null>(null);
  const [status, setStatus] = useState<WhatsAppStatus>('disconnected');
  const [isLoading, setIsLoading] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await whatsappService.selfDeviceStatus();
      setAccountId(res.account_id);
      setStatus(res.status);
    } catch {
      // Leave as-is — the card still renders, just possibly stale.
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const showToast = (message: string) => {
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  };

  const handleConnected = () => {
    setStatus('connected');
    setIsModalOpen(false);
    showToast('Your WhatsApp test device is connected.');
  };

  const handleLogout = async () => {
    setIsLoggingOut(true);
    try {
      await whatsappService.selfDeviceLogout();
      setStatus('disconnected');
      showToast('Your WhatsApp test device is disconnected.');
    } catch {
      showToast('Failed to disconnect. Please try again.');
    } finally {
      setIsLoggingOut(false);
    }
  };

  const STATUS_META: Record<WhatsAppStatus, { label: string; dot: string }> = {
    connected: { label: 'Connected', dot: 'bg-emerald-500' },
    disconnected: { label: 'Not connected — scan to test templates', dot: 'bg-red-500' },
    connecting: { label: 'Action needed — scan to connect', dot: 'bg-amber-400' },
  };
  const meta = STATUS_META[status];

  return (
    <Card className="mb-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
            <QrCode className="h-5 w-5" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-900">Your WhatsApp Test Device</p>
            <p className="mt-0.5 flex items-center gap-2 text-sm text-slate-500">
              <span className={`h-2 w-2 rounded-full ${meta.dot}`} />
              {isLoading ? 'Checking status…' : meta.label}
            </p>
          </div>
        </div>

        {status === 'connected' ? (
          <button
            type="button"
            onClick={() => void handleLogout()}
            disabled={isLoggingOut}
            className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
          >
            {isLoggingOut ? 'Disconnecting…' : 'Disconnect'}
          </button>
        ) : (
          <button
            type="button"
            onClick={() => setIsModalOpen(true)}
            disabled={isLoading || accountId === null}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            <Radio className="h-4 w-4" />
            {status === 'connecting' ? 'Reconnect' : 'Scan to Connect'}
          </button>
        )}
      </div>
      <p className="mt-3 text-xs text-slate-400">
        Template Manager's "Send Template" test action always sends through this device — not a client's.
      </p>

      {isModalOpen && accountId !== null && (
        <QRScannerModal
          accountId={accountId}
          onClose={() => setIsModalOpen(false)}
          onConnected={handleConnected}
          startSession={() => whatsappService.selfDeviceStartSession()}
        />
      )}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
          {toast}
        </div>
      )}
    </Card>
  );
}

export default function AdminDeviceSettingsPage() {
  const [devices, setDevices] = useState<AdminWhatsAppDevice[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyAccountId, setBusyAccountId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [qrModalAccountId, setQrModalAccountId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const list = await whatsappService.adminListDevices();
      setDevices(list);
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to load WhatsApp devices.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleDisconnect = async (accountId: number) => {
    setActionError(null);
    setBusyAccountId(accountId);
    try {
      await whatsappService.logout(accountId);
      setDevices((prev) =>
        prev.map((d) => (d.account_id === accountId ? { ...d, status: 'disconnected' } : d)),
      );
    } catch (err) {
      setActionError(extractErrorMessage(err, 'Failed to disconnect this device.'));
    } finally {
      setBusyAccountId(null);
    }
  };

  const handleConnected = () => {
    setQrModalAccountId(null);
    void load();
  };

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">WhatsApp Device Settings</h1>
            <p className="mt-1 text-sm text-slate-500">
              Link, view status, disconnect, or reconnect any client's WhatsApp device.
            </p>
          </div>
          <button
            type="button"
            onClick={() => void load()}
            disabled={isLoading}
            className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
        </div>

        {error && (
          <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}
        {actionError && (
          <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {actionError}
          </div>
        )}

        <div className="mt-6">
          <SuperAdminTestDeviceCard />

          <TableCard>
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Client</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Last Connected</th>
                  <th className="px-4 py-3 text-right font-medium text-slate-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <TableSkeletonRows rows={5} columns={4} />
                ) : devices.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-10 text-center text-sm text-slate-500">
                      No client accounts yet.
                    </td>
                  </tr>
                ) : (
                  devices.map((device) => (
                    <tr key={device.account_id}>
                      <td className="px-4 py-3 font-medium text-slate-900">{device.company_name}</td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[device.status]}`}
                        >
                          {STATUS_LABEL[device.status]}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-slate-600">{formatLastConnected(device.last_connected_at)}</td>
                      <td className="px-4 py-3 text-right">
                        {device.status === 'connected' ? (
                          <button
                            type="button"
                            onClick={() => void handleDisconnect(device.account_id)}
                            disabled={busyAccountId === device.account_id}
                            className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
                          >
                            {busyAccountId === device.account_id ? 'Disconnecting…' : 'Disconnect'}
                          </button>
                        ) : (
                          <button
                            type="button"
                            onClick={() => setQrModalAccountId(device.account_id)}
                            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700"
                          >
                            <Radio className="h-3.5 w-3.5" />
                            {device.status === 'connecting' ? 'Reconnect' : 'Connect'}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </TableCard>
        </div>
      </div>

      {qrModalAccountId !== null && (
        <QRScannerModal
          accountId={qrModalAccountId}
          onClose={() => setQrModalAccountId(null)}
          onConnected={handleConnected}
        />
      )}
    </div>
  );
}
