import { useCallback, useEffect, useState } from 'react';
import { Lock, Plus, RefreshCw, Star, Trash2, X } from 'lucide-react';
import whatsappService from '../../services/whatsappService';
import QRScannerModal from '../qr/QRScannerModal';
import type { AddonInvoice, WhatsAppNumberRow, WhatsAppNumberStatus } from '../../types/whatsapp';

interface WhatsAppNumbersCardProps {
  accountId: number;
  /** Called after a number connects, so the page can refresh its own status. */
  onConnected?: () => void;
  /** Changes whenever the page's connection changes (connect or disconnect); the list reloads. */
  reloadKey?: number;
}

const STATUS_LABEL: Record<WhatsAppNumberStatus, { label: string; tone: string }> = {
  linked: { label: 'Linked', tone: 'text-emerald-600' },
  unlinked: { label: 'Not linked', tone: 'text-slate-500' },
  paused: { label: 'Paused', tone: 'text-amber-600' },
  pending_payment: { label: 'Awaiting payment', tone: 'text-slate-400' },
};

function errorMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
  return data?.message ?? fallback;
}

/** Digits from one entry per line or comma-separated. Empty lines are ignored. */
function parseNumbers(text: string): string[] {
  return text
    .split(/[\n,]+/)
    .map((line) => line.replace(/\D/g, ''))
    .filter((digits) => digits.length > 0);
}

/**
 * WhatsApp number slots of the account: the included number plus paid add-ons.
 * The backend enforces the rules (who can change what, and when); this card only
 * offers the actions the backend would accept.
 */
export default function WhatsAppNumbersCard({ accountId, onConnected, reloadKey = 0 }: WhatsAppNumbersCardProps) {
  const [rows, setRows] = useState<WhatsAppNumberRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [connectingId, setConnectingId] = useState<number | null>(null);
  // The number waiting for the user's confirmation before it is removed.
  const [confirmRemove, setConfirmRemove] = useState<WhatsAppNumberRow | null>(null);

  // Extra-number purchase.
  const [price, setPrice] = useState<{ price: number; term_months: number; max_per_purchase: number } | null>(null);
  const [purchaseOpen, setPurchaseOpen] = useState(false);
  const [purchaseText, setPurchaseText] = useState('');
  const [purchasing, setPurchasing] = useState(false);
  const [purchaseError, setPurchaseError] = useState<string | null>(null);
  const [invoice, setInvoice] = useState<AddonInvoice | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await whatsappService.listNumbers(accountId);
      setRows(res.data);
      setError(null);
    } catch (err) {
      setError(errorMessage(err, 'Could not load your WhatsApp numbers.'));
    } finally {
      setLoading(false);
    }
  }, [accountId]);

  useEffect(() => {
    void load();
  }, [load, reloadKey]);

  useEffect(() => {
    whatsappService.addonPrice().then(setPrice).catch(() => setPrice(null));
  }, []);

  const run = async (id: number, action: () => Promise<unknown>, fallback: string) => {
    setBusyId(id);
    setError(null);
    try {
      await action();
      await load();
    } catch (err) {
      setError(errorMessage(err, fallback));
    } finally {
      setBusyId(null);
    }
  };

  const numbers = parseNumbers(purchaseText);
  const unit = price?.price ?? 0;
  const total = Math.round(numbers.length * unit * 100) / 100;

  const submitPurchase = async () => {
    setPurchasing(true);
    setPurchaseError(null);
    try {
      const res = await whatsappService.purchaseAddons(numbers, accountId);
      setInvoice(res.invoice);
      setPurchaseText('');
      await load();
    } catch (err) {
      setPurchaseError(errorMessage(err, 'Could not create the invoice. Please check the numbers and try again.'));
    } finally {
      setPurchasing(false);
    }
  };

  const cancelInvoice = async () => {
    if (!invoice) return;
    setPurchasing(true);
    setPurchaseError(null);
    try {
      await whatsappService.cancelAddonInvoice(invoice.id, accountId);
      setInvoice(null);
      await load();
    } catch (err) {
      setPurchaseError(errorMessage(err, 'Could not cancel this invoice.'));
    } finally {
      setPurchasing(false);
    }
  };

  const connectingRow = rows.find((r) => r.id === connectingId) ?? null;

  return (
    <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-semibold text-slate-900">WhatsApp numbers</p>
          <p className="mt-0.5 text-sm text-slate-500">
            Your plan includes one number. Each extra number is ₹{price?.price ?? 99} (GST included) for {price?.term_months ?? 1} month.
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            setPurchaseOpen((open) => !open);
            setInvoice(null);
            setPurchaseError(null);
          }}
          className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          {purchaseOpen ? <X className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
          {purchaseOpen ? 'Close' : 'Add number'}
        </button>
      </div>

      {purchaseOpen && (
        <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
          {invoice ? (
            <div className="space-y-3">
              <p className="text-sm font-medium text-slate-900">Invoice {invoice.invoice_number}</p>
              <ul className="space-y-1 text-sm text-slate-600">
                {invoice.items.map((item) => (
                  <li key={item.description} className="flex justify-between gap-4">
                    <span>{item.description}</span>
                    <span>₹{item.amount.toFixed(2)}</span>
                  </li>
                ))}
              </ul>
              <p className="flex justify-between border-t border-slate-200 pt-2 text-sm font-semibold text-slate-900">
                <span>Total (GST included)</span>
                <span>₹{invoice.total_amount.toFixed(2)}</span>
              </p>
              <p className="text-xs text-slate-500">
                The numbers are connected after payment. Payment is recorded by an administrator.
              </p>
              {invoice.status === 'pending' && (
                <button
                  type="button"
                  disabled={purchasing}
                  onClick={() => void cancelInvoice()}
                  className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
                >
                  Cancel this invoice
                </button>
              )}
            </div>
          ) : (
            <div className="space-y-3">
              <label htmlFor="addon-numbers" className="block text-sm font-medium text-slate-900">
                Extra WhatsApp numbers
              <span className="text-red-500" aria-hidden="true"> *</span></label>
              <textarea
                id="addon-numbers"
                rows={3}
                value={purchaseText}
                onChange={(e) => setPurchaseText(e.target.value)}
                placeholder={'917236062374\n918888888888'}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
              />
              <p className="text-xs text-slate-400">
                One per line, with country code and no + or spaces. Each number needs its own WhatsApp account.
                {price && ` Up to ${price.max_per_purchase} at a time.`}
              </p>
              <div className="flex items-center justify-between">
                <p className="text-sm text-slate-700">
                  {numbers.length} number{numbers.length === 1 ? '' : 's'} · Total <strong>₹{total.toFixed(2)}</strong> (GST included)
                </p>
                <button
                  type="button"
                  disabled={purchasing || numbers.length === 0}
                  onClick={() => void submitPurchase()}
                  className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                  {purchasing ? 'Creating invoice…' : 'Create invoice'}
                </button>
              </div>
            </div>
          )}
          {purchaseError && <p className="mt-3 text-sm text-red-600">{purchaseError}</p>}
        </div>
      )}

      {rows.length > 1 && !rows.some((r) => r.can_set_default) && !loading && (
        <p className="mt-4 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          <Lock className="h-3.5 w-3.5" />
          The default can be changed once all your numbers are linked and your subscription is active.
        </p>
      )}

      {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

      <div className="mt-4 divide-y divide-slate-200">
        {loading && <p className="py-3 text-sm text-slate-500">Loading numbers…</p>}

        {!loading && rows.length === 0 && (
          <p className="py-3 text-sm text-slate-500">No number yet. Connect WhatsApp above to add your first number.</p>
        )}

        {rows.map((row) => {
          const status = STATUS_LABEL[row.status];
          const busy = busyId === row.id;
          const canConnect = row.status !== 'pending_payment' && row.status !== 'linked';
          // Shown only when the server allows it (linked, paid, all numbers linked, subscription active).
          const canSetDefault = row.can_set_default;
          const canRemove = !row.is_included && row.status === 'pending_payment' && !row.locked;

          return (
            <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="flex items-center gap-2 text-sm font-medium text-slate-900">
                  +{row.phone_number}
                  {row.is_default && (
                    <span className="flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                      <Star className="h-3 w-3" />
                      Default
                    </span>
                  )}
                  {row.is_included && (
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Included</span>
                  )}
                </p>
                <p className={`mt-0.5 text-xs ${status.tone}`}>{status.label}</p>
              </div>

              <div className="flex items-center gap-2">
                {canConnect && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setConnectingId(row.id)}
                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                  >
                    Connect
                  </button>
                )}
                {canSetDefault && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => run(row.id, () => whatsappService.setDefaultNumber(row.id, accountId), 'Could not change the default number.')}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                  >
                    Set as default
                  </button>
                )}
                {canRemove && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setConfirmRemove(row)}
                    className="flex items-center gap-1 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                    Remove
                  </button>
                )}
                {busy && <RefreshCw className="h-4 w-4 animate-spin text-slate-400" />}
              </div>
            </div>
          );
        })}
      </div>

      {confirmRemove && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true">
          <div className="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
            <h2 className="text-base font-semibold text-slate-900">Remove this number?</h2>
            <p className="mt-2 text-sm text-slate-600">
              +{confirmRemove.phone_number} will be removed from your WhatsApp numbers. Its unpaid invoice is not affected.
            </p>
            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setConfirmRemove(null)}
                className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => {
                  const target = confirmRemove;
                  setConfirmRemove(null);
                  void run(target.id, () => whatsappService.removeNumber(target.id, accountId), 'Could not remove this number.');
                }}
                className="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700"
              >
                Yes, remove
              </button>
            </div>
          </div>
        </div>
      )}

      {connectingRow && (
        <QRScannerModal
          accountId={accountId}
          numberId={connectingRow.id}
          expectedPhone={connectingRow.phone_number}
          onClose={() => setConnectingId(null)}
          onConnected={() => {
            setConnectingId(null);
            void load();
            onConnected?.();
          }}
        />
      )}
    </div>
  );
}
