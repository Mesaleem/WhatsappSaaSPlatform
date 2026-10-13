import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Sparkles } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import aiCreditService, { AI_CREDITS_CHANGED_EVENT, type AiCreditStatus } from '../../services/aiCreditService';
import { indigo } from '../../theme/signalIndigo';

/**
 * The header's AI credit chip, for clients and agents (their OWN account): today's free
 * credits (set per day by the Super Admin, they expire at midnight and never add up)
 * and the paid wallet. Hidden for the Super Admin, whose AI use is unlimited.
 * Re-read on every page change and after an AI action (AI_CREDITS_CHANGED_EVENT).
 */
export default function AiCreditChip() {
  const { isSuperAdmin, user } = useAuth();
  const { pathname } = useLocation();
  const [status, setStatus] = useState<AiCreditStatus | null>(null);
  const [tick, setTick] = useState(0);
  const show = !!user && !isSuperAdmin();

  useEffect(() => {
    const onChange = () => setTick((n) => n + 1);
    window.addEventListener(AI_CREDITS_CHANGED_EVENT, onChange);
    return () => window.removeEventListener(AI_CREDITS_CHANGED_EVENT, onChange);
  }, []);

  useEffect(() => {
    if (!show) return;
    let cancelled = false;
    aiCreditService
      .status()
      .then((data) => {
        if (!cancelled) setStatus(data);
      })
      .catch(() => {
        if (!cancelled) setStatus(null);
      });
    return () => {
      cancelled = true;
    };
  }, [show, pathname, tick]);

  if (!show || !status || status.unlimited) return null;

  const daily = status.daily;
  const wallet = status.wallet_available ?? 0;
  const hasDaily = !!daily && daily.per_day > 0;
  const title = hasDaily
    ? `AI credits: ${daily.remaining} of ${daily.per_day} free credits left today (they reset at midnight and do not carry over), plus ${wallet} in your wallet.`
    : `AI credits: ${wallet} in your wallet.`;

  return (
    <div
      className="hidden items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold sm:flex"
      style={{ borderColor: indigo.border, color: indigo.ink }}
      title={title}
      aria-label={title}
    >
      <Sparkles className="h-3.5 w-3.5 text-emerald-600" aria-hidden />
      <span>AI</span>
      {hasDaily && (
        <span className="font-mono">
          {daily.remaining}/{daily.per_day}
          <span className="ml-0.5 font-sans font-medium text-slate-500">today</span>
        </span>
      )}
      {hasDaily && <span className="text-slate-300">·</span>}
      <span className="font-mono">
        {wallet}
        <span className="ml-0.5 font-sans font-medium text-slate-500">wallet</span>
      </span>
    </div>
  );
}
