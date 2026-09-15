import { useEffect, useState, type FormEvent } from 'react';
import { Copy, Eye, EyeOff, KeyRound, Loader2, LogOut, RefreshCw, ShieldAlert, X, XCircle } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';
import profileService from '../../services/profileService';
import templateService from '../../services/templateService';
import type { ClientApiKey } from '../../types/templates';
import { indigo, activeGradient, cardShadow, ROLE_BADGE_CLASS, roleLabel } from '../../theme/signalIndigo';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';
import ConfirmModal from '../common/ConfirmModal';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-[#EAE8F7] px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20';


export default function ProfileModal({ onClose }: { onClose: () => void }) {
  const { user, logout, refreshUser, hasPermission, isSuperAdmin } = useAuth();
  const navigate = useNavigate();

  const [name, setName] = useState(user?.name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [showRegenerateConfirm, setShowRegenerateConfirm] = useState(false);

  // Client API Key — Developer Portal credential for this tenant account. Super Admin has no
  // tenant account and is excluded; a tenant user needs manage-developer-settings, matching the
  // backend route's `permission:manage-developer-settings` gate on /account/api-key.
  const canManageApiKey = hasPermission('manage-developer-settings') && !isSuperAdmin();
  const [apiKey, setApiKey] = useState<ClientApiKey | null>(null);
  const [isKeyLoading, setIsKeyLoading] = useState(canManageApiKey);
  const [keyError, setKeyError] = useState<string | null>(null);
  const [isRegenerating, setIsRegenerating] = useState(false);
  // Held only in local memory for this browser session — the server stores a hash, never the
  // plaintext key, so a key can be copied/revealed only immediately after it is (re)generated.
  const [revealedPlainKey, setRevealedPlainKey] = useState<string | null>(null);
  const [showKey, setShowKey] = useState(false);
  const [keyCopied, setKeyCopied] = useState(false);

  useEffect(() => {
    if (!canManageApiKey) return;
    let cancelled = false;
    setIsKeyLoading(true);
    templateService
      .getApiKey()
      .then((data) => {
        if (!cancelled) setApiKey(data);
      })
      .catch((err) => {
        if (!cancelled) setKeyError(extractMessage(err, 'Could not load your API key.'));
      })
      .finally(() => {
        if (!cancelled) setIsKeyLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManageApiKey]);

  const handleRegenerateKey = () => {
    setShowRegenerateConfirm(true);
  };

  const confirmRegenerateKey = async () => {
    setKeyError(null);
    setIsRegenerating(true);
    try {
      const result = await templateService.regenerateApiKey();
      setApiKey(result.data);
      setRevealedPlainKey(result.plain_text_key);
      setShowKey(true);
      setShowRegenerateConfirm(false);
    } catch (err) {
      setKeyError(extractMessage(err, 'Could not generate a new API key.'));
      setShowRegenerateConfirm(false);
    } finally {
      setIsRegenerating(false);
    }
  };

  const handleCopyKey = async () => {
    if (!revealedPlainKey) return;
    try {
      await navigator.clipboard.writeText(revealedPlainKey);
      setKeyCopied(true);
      setTimeout(() => setKeyCopied(false), 2000);
    } catch {
      // Clipboard API can be unavailable — the value is still visible in the field above.
    }
  };

  const handleSave = async (e: FormEvent) => {
    e.preventDefault();
    setSuccess(false);

    if (!name.trim() || !email.trim()) {
      setError('Name and email are required.');
      return;
    }
    if (password && password.length < 8) {
      setError('New password must be at least 8 characters, or left blank to keep your current one.');
      return;
    }

    setError(null);
    setIsSaving(true);
    try {
      await profileService.updateProfile({
        name: name.trim(),
        email: email.trim(),
        ...(password ? { password } : {}),
      });
      await refreshUser();
      setPassword('');
      setSuccess(true);
    } catch (err) {
      setError(extractMessage(err, 'Could not save your changes.'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleLogout = async () => {
    setIsLoggingOut(true);
    try {
      await logout();
      navigate('/login', { replace: true });
    } finally {
      setIsLoggingOut(false);
    }
  };

  return (
    <>
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md rounded-2xl bg-white p-6"
        style={{ boxShadow: cardShadow }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <span
              className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full font-display text-base font-bold text-white"
              style={{ background: activeGradient }}
            >
              {(user?.name ?? '?').slice(0, 1).toUpperCase()}
            </span>
            <div>
              <p className="text-sm font-semibold" style={{ color: indigo.ink }}>
                {user?.name}
              </p>
              {/* Dynamic Multi-Role Sidebar Aggregation refactor — one badge per assigned role, not just the first. */}
              <div className="mt-0.5 flex flex-wrap items-center gap-1">
                {(user?.roles ?? []).map((r) => (
                  <span
                    key={r.id}
                    className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                      ROLE_BADGE_CLASS[r.name] ?? 'bg-slate-100 text-slate-600 border-slate-200'
                    }`}
                  >
                    {roleLabel(r.name)}
                  </span>
                ))}
              </div>
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Close"
            className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSave(e)} className="mt-5 space-y-4">
          <div>
            <label className="text-sm font-medium" style={{ color: indigo.ink }}>
              Full Name <span className="text-red-500">*</span>
            </label>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              className={inputClass}
              autoFocus
            />
          </div>
          <div>
            <label className="text-sm font-medium" style={{ color: indigo.ink }}>
              Email Address <span className="text-red-500">*</span>
            </label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={inputClass}
            />
          </div>
          <div>
            <label className="text-sm font-medium" style={{ color: indigo.ink }}>
              New Password <span className="font-normal" style={{ color: indigo.muted }}>(optional)</span>
            </label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Leave blank to keep your current password"
              className={inputClass}
              autoComplete="new-password"
            />
          </div>

          {canManageApiKey && (
            <div className="space-y-2 border-t pt-4" style={{ borderColor: indigo.border }}>
              <div className="flex items-center gap-1.5">
                <KeyRound className="h-4 w-4" style={{ color: indigo.muted }} />
                <span className="text-sm font-medium" style={{ color: indigo.ink }}>
                  Client API Key
                </span>
              </div>

              {isKeyLoading ? (
                <div className="flex items-center gap-2 text-sm" style={{ color: indigo.muted }}>
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  Loading…
                </div>
              ) : !apiKey && !revealedPlainKey ? (
                <div className="space-y-2">
                  <p className="text-xs" style={{ color: indigo.muted }}>
                    No Client API Key has been generated for this account yet.
                  </p>
                  <button
                    type="button"
                    onClick={() => void handleRegenerateKey()}
                    disabled={isRegenerating}
                    className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                  >
                    {isRegenerating ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <KeyRound className="h-3.5 w-3.5" />}
                    Generate Key
                  </button>
                </div>
              ) : (
                <div className="space-y-2">
                  {revealedPlainKey && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                      <ShieldAlert className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                      <span>
                        <strong>Copy this key now.</strong> For your security it will not be shown again after you
                        close this dialog — only the key hash is stored.
                      </span>
                    </div>
                  )}
                  <div className="flex items-center gap-2">
                    <code className="flex-1 overflow-x-auto rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900">
                      {showKey
                        ? revealedPlainKey ?? `${apiKey?.key_prefix ?? ''}••••••••••••••••`
                        : '••••••••••••••••••••••••'}
                    </code>
                    <button
                      type="button"
                      onClick={() => setShowKey((v) => !v)}
                      aria-label={showKey ? 'Hide key' : 'Show key'}
                      title={showKey ? 'Hide key' : 'Show key'}
                      className="flex-shrink-0 rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50"
                    >
                      {showKey ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                    </button>
                    <button
                      type="button"
                      onClick={() => void handleCopyKey()}
                      disabled={!revealedPlainKey}
                      title={
                        revealedPlainKey
                          ? 'Copy key'
                          : 'The full key is only shown once, right after it is generated — regenerate to get a copyable key.'
                      }
                      className="flex flex-shrink-0 items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      <Copy className="h-3.5 w-3.5" />
                      {keyCopied ? 'Copied' : 'Copy'}
                    </button>
                  </div>
                  <div className="flex items-center justify-between">
                    <p className="text-[11px]" style={{ color: indigo.muted }}>
                      {apiKey?.last_used_at
                        ? `Last used ${new Date(apiKey.last_used_at).toLocaleDateString()}`
                        : 'Never used yet'}
                    </p>
                    <button
                      type="button"
                      onClick={() => void handleRegenerateKey()}
                      disabled={isRegenerating}
                      className="flex items-center gap-1.5 text-xs font-medium hover:underline disabled:opacity-60"
                      style={{ color: indigo.accentSolid }}
                    >
                      {isRegenerating ? (
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      ) : (
                        <RefreshCw className="h-3.5 w-3.5" />
                      )}
                      Regenerate Key
                    </button>
                  </div>
                </div>
              )}

              {keyError && (
                <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                  <XCircle className="h-3.5 w-3.5 flex-shrink-0" />
                  {keyError}
                </div>
              )}
            </div>
          )}

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}
          {success && !error && (
            <div className="rounded-lg border border-[#BFF0DE] bg-[#E6F8F1] px-3 py-2 text-sm font-medium text-[#0E9F6E]">
              Changes saved.
            </div>
          )}

          <div className="flex items-center justify-between border-t pt-4" style={{ borderColor: indigo.border }}>
            <button
              type="button"
              onClick={() => void handleLogout()}
              disabled={isLoggingOut}
              className="flex items-center gap-1.5 text-sm font-medium text-red-600 hover:text-red-700 disabled:opacity-60"
            >
              {isLoggingOut ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogOut className="h-4 w-4" />}
              Logout
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm disabled:opacity-60"
              style={{ background: activeGradient }}
            >
              {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>

    {showRegenerateConfirm && (
      <ConfirmModal
        title={apiKey ? 'Regenerate API key' : 'Generate API key'}
        message={
          apiKey
            ? 'Regenerate your Client API Key? The current key will stop working immediately for any integration using it.'
            : 'Generate a Client API Key for this account?'
        }
        confirmLabel={apiKey ? 'Regenerate' : 'Generate'}
        variant={apiKey ? 'danger' : 'default'}
        isLoading={isRegenerating}
        onConfirm={() => void confirmRegenerateKey()}
        onCancel={() => setShowRegenerateConfirm(false)}
      />
    )}
    </>
  );
}
