import { useEffect, useRef, useState, type FormEvent } from 'react';
import { io, type Socket } from 'socket.io-client';
import { AlertCircle, CheckCircle2, Loader2, RefreshCw, ScanLine, X } from 'lucide-react';
import { AUTH_TOKEN_KEY } from '../../core/api/axiosInstance';
import whatsappService from '../../services/whatsappService';
import type { ConnectionUpdatePayload, WhatsAppStatus } from '../../types/whatsapp';

const QR_ENGINE_WS_URL = (import.meta.env.VITE_QR_ENGINE_WS_URL as string | undefined) ?? 'http://localhost:4000';
// Baileys rotates the QR string roughly this often; this drives the visual
// countdown only — a fresh `qr` payload always resets it, whenever it arrives.
const QR_TTL_SECONDS = 60;
// Digits only, country code first, no leading zero (E.164 without the +).
const PHONE_PATTERN = /^[1-9][0-9]{7,14}$/;
// If the live stream has produced no event this long, the engine is most likely
// unreachable from this browser (wrong VITE_QR_ENGINE_WS_URL, sleeping host, CORS).
const STREAM_SILENCE_MS = 10000;
// Upper bound for waiting on a pairing code. The engine itself gives up at 45 s.
const PAIRING_WAIT_MS = 60000;
// Retries for a transient handshake failure before the modal reports it.
const SOCKET_RETRIES = 4;
const SOCKET_RETRY_MS = 2000;

type LoginMode = 'qr' | 'phone';

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
   *
   * Passing this also turns OFF the phone-number option: the Super Admin's
   * test device is QR-only, because its start endpoint takes no number.
   */
  startSession?: () => Promise<unknown>;
  /**
   * The WhatsApp number slot this modal connects. The live stream only delivers
   * events for a slot, so it is sent with the connection. Omitted for the Super
   * Admin's test device, which has no slot.
   */
  numberId?: number | null;
}

function formatPairingCode(code: string): string {
  return code.length === 8 ? `${code.slice(0, 4)}-${code.slice(4)}` : code;
}

function extractErrorMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  return data?.errors?.phone_number?.[0] ?? data?.message ?? fallback;
}

export default function QRScannerModal({ accountId, onClose, onConnected, startSession, numberId }: QRScannerModalProps) {
  const startSessionRequest = startSession ?? (() => whatsappService.startSession(accountId, undefined, numberId));
  const phoneLoginAvailable = !startSession;

  const [mode, setMode] = useState<LoginMode>('qr');
  const [status, setStatus] = useState<WhatsAppStatus>('connecting');
  const [qr, setQr] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(QR_TTL_SECONDS);
  const [socketError, setSocketError] = useState<string | null>(null);
  const socketRef = useRef<Socket | null>(null);

  // Phone-number login state.
  const [phoneInput, setPhoneInput] = useState('');
  const [phoneDigits, setPhoneDigits] = useState<string | null>(null);
  const [pairingCode, setPairingCode] = useState<string | null>(null);
  const [phoneBusy, setPhoneBusy] = useState(false);
  const [phoneError, setPhoneError] = useState<string | null>(null);
  const [streamSilent, setStreamSilent] = useState(false);
  const silenceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const retriesRef = useRef(0);

  const markStreamAlive = () => {
    if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    setStreamSilent(false);
  };

  const connectSocket = () => {
    const token = localStorage.getItem(AUTH_TOKEN_KEY);
    if (!token) {
      setSocketError('You are not signed in.');
      return;
    }

    setSocketError(null);
    setStreamSilent(false);
    if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    silenceTimerRef.current = setTimeout(() => setStreamSilent(true), STREAM_SILENCE_MS);

    const socket = io(QR_ENGINE_WS_URL, {
      auth: { accountId, token, ...(numberId ? { sessionId: numberId } : {}) },
      transports: ['websocket', 'polling'],
    });
    socketRef.current = socket;

    // A successful (re)connect clears any earlier transient error.
    socket.on('connect', () => {
      markStreamAlive();
      setSocketError(null);
      retriesRef.current = 0;
    });

    socket.on('connect_error', (err: Error) => {
      markStreamAlive();
      if (err.message === 'forbidden') {
        setSocketError("You aren't authorized to manage this account's WhatsApp connection.");
        return;
      }
      // The engine checks the session with the backend during the handshake. A
      // slow or failed check is not a dead engine, so retry a few times before
      // reporting it. Socket.IO does not retry middleware errors on its own.
      if (retriesRef.current < SOCKET_RETRIES) {
        retriesRef.current += 1;
        setTimeout(() => {
          if (socketRef.current === socket) socket.connect();
        }, SOCKET_RETRY_MS);
        return;
      }
      setSocketError('Could not reach the WhatsApp engine service.');
    });

    socket.on('connection:update', (payload: ConnectionUpdatePayload) => {
      markStreamAlive();
      setStatus(payload.status);
      if (payload.qr) {
        setQr(payload.qr);
        setSecondsLeft(QR_TTL_SECONDS);
      }
      if (payload.pairing_code) {
        setPairingCode(payload.pairing_code);
        setPhoneBusy(false);
        setPhoneError(null);
      }
      if (payload.status === 'connected') {
        setQr(null);
        setPairingCode(null);
      }
      if (payload.error === 'pairing_code_timeout') {
        setPhoneBusy(false);
        setPhoneError(
          'WhatsApp did not return a code for that number. Check that it includes the country code and is a WhatsApp account, then try again.',
        );
        return;
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
      if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
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

  const requestPairingCode = (digits: string) => {
    setPhoneDigits(digits);
    setPairingCode(null);
    setPhoneError(null);
    setPhoneBusy(true);
    whatsappService
      .startSession(accountId, digits, numberId)
      .then((res) => {
        // An already-connected account gets no code; say so instead of waiting.
        if ((res as { status?: string })?.status === 'connected') {
          setPhoneBusy(false);
          setStatus('connected');
        }
      })
      .catch((err: unknown) => {
        setPhoneBusy(false);
        setPhoneError(extractErrorMessage(err, 'Could not request a pairing code. Please try again.'));
      });
  };

  // Stop the spinner if no code arrives. The engine's own 45 s timeout normally
  // answers first; this covers a dead stream.
  useEffect(() => {
    if (!phoneBusy) return;
    const timer = setTimeout(() => {
      setPhoneBusy(false);
      setPhoneError('No code arrived from the WhatsApp engine. Check the connection and try again.');
    }, PAIRING_WAIT_MS);
    return () => clearTimeout(timer);
  }, [phoneBusy]);

  const handlePhoneSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const digits = phoneInput.replace(/\D/g, '');
    if (digits.length === 10) {
      // A 10-digit number is almost always missing its country code; WhatsApp
      // cannot find an account for it, so no code would ever come back.
      setPhoneError('This number is missing its country code. Add it in front, for example 91 for India: 91' + digits + '.');
      return;
    }
    if (!PHONE_PATTERN.test(digits)) {
      setPhoneError('Enter the full number with country code and no + or spaces, for example 919876543210.');
      return;
    }
    requestPairingCode(digits);
  };

  // Back to the number box. The typed number is kept so a fix is quick. Any
  // code already shown is dropped, and a new request replaces it engine-side.
  const backToNumberEntry = () => {
    setPairingCode(null);
    setPhoneBusy(false);
    setPhoneError(null);
  };

  const switchMode = (next: LoginMode) => {
    setMode(next);
    setPhoneError(null);
    // Choosing the Phone tab always lands on the number box, even mid-code.
    if (next === 'phone') backToNumberEntry();
  };

  const phoneStep = pairingCode ? 'code' : phoneBusy ? 'requesting' : 'number';

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

        {phoneLoginAvailable && (
          <div role="tablist" className="mt-4 grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-xs font-medium">
            <button
              role="tab"
              aria-selected={mode === 'qr'}
              onClick={() => switchMode('qr')}
              className={`rounded-md px-2 py-1.5 ${mode === 'qr' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
            >
              Scan QR code
            </button>
            <button
              role="tab"
              aria-selected={mode === 'phone'}
              onClick={() => switchMode('phone')}
              className={`rounded-md px-2 py-1.5 ${mode === 'phone' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
            >
              Phone number
            </button>
          </div>
        )}

        <div className="mt-6 flex min-h-[240px] flex-col items-center justify-center">
          {socketError && mode === 'qr' ? (
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
          ) : mode === 'phone' ? (
            <div className="flex w-full flex-col items-center gap-4">
              {phoneStep === 'code' && pairingCode ? (
                <>
                  <p className="text-xs text-slate-500">Enter this code on your phone</p>
                  <p className="font-mono text-3xl font-semibold tracking-[0.2em] text-slate-900">
                    {formatPairingCode(pairingCode)}
                  </p>
                  <ol className="max-w-[260px] list-decimal space-y-1 pl-4 text-left text-xs text-slate-500">
                    <li>Open WhatsApp → Settings → Linked devices</li>
                    <li>Tap Link a device → Link with phone number instead</li>
                    <li>Type the code above</li>
                  </ol>
                  <div className="flex items-center gap-4">
                    <button
                      onClick={() => phoneDigits && requestPairingCode(phoneDigits)}
                      className="flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700"
                    >
                      <RefreshCw className="h-3.5 w-3.5" />
                      Get a new code
                    </button>
                    <button
                      onClick={backToNumberEntry}
                      className="text-xs font-medium text-slate-500 hover:text-slate-700"
                    >
                      Use a different number
                    </button>
                  </div>
                </>
              ) : phoneStep === 'requesting' ? (
                <div className="flex flex-col items-center gap-3 text-slate-500">
                  <Loader2 className="h-8 w-8 animate-spin" />
                  <p className="text-sm">Requesting a code from WhatsApp…</p>
                </div>
              ) : (
                <form onSubmit={handlePhoneSubmit} className="flex w-full flex-col gap-3 text-left">
                  <label htmlFor="wa-phone" className="text-xs font-medium text-slate-700">
                    WhatsApp number
                  </label>
                  <input
                    id="wa-phone"
                    type="tel"
                    inputMode="numeric"
                    autoComplete="tel"
                    value={phoneInput}
                    onChange={(e) => setPhoneInput(e.target.value)}
                    placeholder="919876543210"
                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                  />
                  <p className="text-xs text-slate-400">
                    Full number with country code, no + or spaces. Example: 91 for India, then the 10-digit number.
                  </p>
                  <button
                    type="submit"
                    disabled={!phoneInput.replace(/\D/g, '')}
                    className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Get pairing code
                  </button>
                </form>
              )}

              {phoneError && <p className="text-xs text-red-600">{phoneError}</p>}
              {socketError && !phoneError && <p className="text-xs text-red-600">{socketError}</p>}
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
          ) : streamSilent ? (
            <div className="flex max-w-[260px] flex-col items-center gap-3 text-amber-700">
              <AlertCircle className="h-8 w-8" />
              <p className="text-sm font-medium">The WhatsApp engine is not responding.</p>
              <p className="break-all text-xs text-slate-500">Engine: {QR_ENGINE_WS_URL}</p>
              <p className="text-xs text-slate-500">
                Check that this URL is reachable from this browser and is the engine running this account.
              </p>
              <button
                onClick={handleRefresh}
                className="mt-1 flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <RefreshCw className="h-4 w-4" />
                Try again
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
