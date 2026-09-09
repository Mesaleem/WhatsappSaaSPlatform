import { useEffect, useRef, useState } from 'react';
import { io, type Socket } from 'socket.io-client';
import { AlertCircle, CheckCircle2, Loader2, RefreshCw, ScanLine, X } from 'lucide-react';
import { AUTH_TOKEN_KEY } from '../../core/api/axiosInstance';
import whatsappService from '../../services/whatsappService';
import type { ConnectionUpdatePayload, WhatsAppStatus } from '../../types/whatsapp';

const QR_ENGINE_WS_URL = (import.meta.env.VITE_QR_ENGINE_WS_URL as string | undefined) ?? 'http://localhost:4000';
// Baileys rotates the QR string roughly this often; this drives the visual
// countdown only — a fresh `qr` payload always resets it, whenever it arrives.
const QR_TTL_SECONDS = 60;

interface QRScannerModalProps {
  accountId: number;
  onClose: () => void;
  /** Called once the phone has scanned and the connection is confirmed open. */
  onConnected: () => void;
  /**
   * Super Admin WhatsApp Device Integration — override for how to
   * (re)start the session. Defaults to whatsappService.startSession(accountId)
   * (every per-tenant caller, unchanged). The Super Admin's own test
   * device is a reserved Account row invisible to ordinary tenant
   * resolution (see WhatsAppController::selfDeviceStatus()'s docblock),
   * so it can't use that generic per-tenant endpoint — its caller passes
   * whatsappService.selfDeviceStartSession() here instead. The Socket.IO
   * connection below still uses accountId either way; qr-engine-service
   * has no notion of this backend-only distinction.
   */
  startSession?: () => Promise<unknown>;
}

export default function QRScannerModal({ accountId, onClose, onConnected, startSession }: QRScannerModalProps) {
  const startSessionRequest = startSession ?? (() => whatsappService.startSession(accountId));
  const [status, setStatus] = useState<WhatsAppStatus>('connecting');
  const [qr, setQr] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(QR_TTL_SECONDS);
  const [socketError, setSocketError] = useState<string | null>(null);
  const socketRef = useRef<Socket | null>(null);

  const connectSocket = () => {
    const token = localStorage.getItem(AUTH_TOKEN_KEY);
    if (!token) {
      setSocketError('You are not signed in.');
      return;
    }

    setSocketError(null);
    const socket = io(QR_ENGINE_WS_URL, {
      auth: { accountId, token },
      transports: ['websocket', 'polling'],
    });
    socketRef.current = socket;

    socket.on('connect_error', (err: Error) => {
      setSocketError(
        err.message === 'forbidden'
          ? "You aren't authorized to manage this account's WhatsApp connection."
          : 'Could not reach the WhatsApp engine service.',
      );
    });

    socket.on('connection:update', (payload: ConnectionUpdatePayload) => {
      setStatus(payload.status);
      if (payload.qr) {
        setQr(payload.qr);
        setSecondsLeft(QR_TTL_SECONDS);
      }
      if (payload.status === 'connected') {
        setQr(null);
      }
      if (payload.error) {
        setSocketError('The WhatsApp engine hit an internal error. Try refreshing.');
      }
    });
  };

  // Open the socket and kick off (or resume) the Baileys session.
  useEffect(() => {
    connectSocket();
    startSessionRequest().catch(() => {
      setSocketError('Failed to start the WhatsApp session. Please try again.');
    });

    return () => {
      socketRef.current?.disconnect();
      socketRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accountId]);

  // Visual QR countdown — purely cosmetic, reset whenever a fresh QR arrives.
  useEffect(() => {
    if (status !== 'connecting' || !qr) return;
    const interval = setInterval(() => {
      setSecondsLeft((s) => Math.max(0, s - 1));
    }, 1000);
    return () => clearInterval(interval);
  }, [status, qr]);

  // Auto-close with a success beat once the phone has paired.
  useEffect(() => {
    if (status !== 'connected') return;
    const timeout = setTimeout(() => onConnected(), 1200);
    return () => clearTimeout(timeout);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  const handleRefresh = () => {
    setSocketError(null);
    setQr(null);
    setSecondsLeft(QR_TTL_SECONDS);
    startSessionRequest().catch(() => {
      setSocketError('Failed to refresh the QR code. Please try again.');
    });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-900">Connect WhatsApp</h2>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-6 flex min-h-[240px] flex-col items-center justify-center">
          {socketError ? (
            <div className="flex flex-col items-center gap-3 text-red-600">
              <AlertCircle className="h-8 w-8" />
              <p className="text-sm">{socketError}</p>
              <button
                onClick={handleRefresh}
                className="mt-1 flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <RefreshCw className="h-4 w-4" />
                Try again
              </button>
            </div>
          ) : status === 'connected' ? (
            <div className="flex flex-col items-center gap-3 text-emerald-600">
              <CheckCircle2 className="h-12 w-12" />
              <p className="text-sm font-medium text-slate-900">Connected!</p>
            </div>
          ) : qr ? (
            <div className="flex flex-col items-center gap-4">
              <img
                src={qr}
                alt="WhatsApp pairing QR code"
                className="h-52 w-52 rounded-lg border border-slate-200 p-2"
              />
              <div className="flex items-center gap-2 text-xs text-slate-500">
                <ScanLine className="h-3.5 w-3.5" />
                {secondsLeft > 0 ? `Refreshes in ${secondsLeft}s` : 'Refreshing…'}
              </div>
              <p className="max-w-[220px] text-xs text-slate-400">
                Open WhatsApp → Linked Devices → Link a Device, then scan this code.
              </p>
              <button
                onClick={handleRefresh}
                className="flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700"
              >
                <RefreshCw className="h-3.5 w-3.5" />
                Refresh QR
              </button>
            </div>
          ) : (
            <div className="flex flex-col items-center gap-3 text-slate-500">
              <Loader2 className="h-8 w-8 animate-spin" />
              <p className="text-sm">Waiting for a QR code…</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
