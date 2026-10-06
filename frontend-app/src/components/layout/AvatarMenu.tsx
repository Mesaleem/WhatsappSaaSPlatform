import { useEffect, useRef, useState } from 'react';
import { KeyRound, LogOut, Lock, User } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';
import { activeGradient } from '../../theme/signalIndigo';
import ProfileDetailsModal from '../profile/ProfileDetailsModal';
import ChangePasswordModal from '../profile/ChangePasswordModal';
import ApiKeyModal from '../profile/ApiKeyModal';

type OpenModal = 'profile' | 'password' | 'apiKey' | null;

/**
 * The avatar in the header opens this menu: who is signed in, then Profile, Change password, API key
 * and Log out. Each item opens its own dialog on this page; nothing navigates away.
 */
export default function AvatarMenu() {
  const { user, logout, hasPermission, isSuperAdmin } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [modal, setModal] = useState<OpenModal>(null);
  const wrapRef = useRef<HTMLDivElement | null>(null);

  // The API key is a developer credential. Users who may manage it always see the item; when the
  // Developer API is not in the plan, the item says so instead of hiding.
  const canSeeApiKey = hasPermission('manage-developer-settings') && !isSuperAdmin();
  // The plan decides: the server reports whether it includes API access.
  const apiKeyInPlan = user?.account?.api_access === true;
  const subscriptionActive = user?.account?.subscription_active !== false;

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const choose = (next: Exclude<OpenModal, null>) => {
    setOpen(false);
    setModal(next);
  };

  const signOut = async () => {
    setOpen(false);
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="relative" ref={wrapRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Open account menu"
        aria-expanded={open}
        title={user?.name}
        className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full font-display text-sm font-bold text-white transition hover:opacity-90"
        style={{ background: activeGradient }}
      >
        {(user?.name ?? '?').slice(0, 1).toUpperCase()}
      </button>

      {open && (
        <div role="menu" className="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
          <div className="px-4 py-3">
            <p className="truncate text-sm font-semibold text-slate-900">{user?.name}</p>
            <p className="truncate text-xs text-slate-500">{user?.email}</p>
          </div>
          <div className="border-t border-slate-100 py-1">
            <MenuItem icon={<User className="h-4 w-4 text-slate-500" />} label="Profile" onClick={() => choose('profile')} />
            <MenuItem icon={<Lock className="h-4 w-4 text-slate-500" />} label="Change password" onClick={() => choose('password')} />
            {canSeeApiKey && (
              <MenuItem
                icon={<KeyRound className="h-4 w-4 text-slate-500" />}
                label="API key"
                muted={!apiKeyInPlan}
                onClick={() => choose('apiKey')}
              />
            )}
          </div>
          <div className="border-t border-slate-100 py-1">
            <MenuItem icon={<LogOut className="h-4 w-4" />} label="Log out" danger onClick={() => void signOut()} />
          </div>
        </div>
      )}

      {modal === 'profile' && <ProfileDetailsModal onClose={() => setModal(null)} />}
      {modal === 'password' && <ChangePasswordModal onClose={() => setModal(null)} />}
      {modal === 'apiKey' && (
        <ApiKeyModal locked={!apiKeyInPlan} subscriptionActive={subscriptionActive} onClose={() => setModal(null)} />
      )}
    </div>
  );
}

function MenuItem({
  icon,
  label,
  onClick,
  danger = false,
  muted = false,
}: {
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
  danger?: boolean;
  muted?: boolean;
}) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      className={`flex w-full items-center gap-3 px-4 py-2 text-sm hover:bg-slate-50 ${
        danger ? 'font-medium text-red-600 hover:bg-red-50' : muted ? 'text-slate-400' : 'text-slate-700'
      }`}
    >
      {icon}
      {label}
      {muted && <span className="ml-auto text-[10px] font-medium uppercase tracking-wide text-slate-400">Not in plan</span>}
    </button>
  );
}
