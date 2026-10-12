import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import LogoMark from '../components/common/LogoMark';
import { BadgeCheck, Bell, CheckCheck, CheckCircle2, Sparkles, TrendingUp, UserPlus } from 'lucide-react';

export const BRAND_NAME = 'WapHub';

export function BrandMark({ light = false, name = BRAND_NAME }: { light?: boolean; name?: string }) {
  return (
    <Link to="/home" className="inline-flex items-center gap-2.5">
      <LogoMark className="h-10 w-10 drop-shadow-lg" />
      <span className={`font-display text-xl font-bold tracking-tight ${light ? 'text-white' : 'text-slate-900'}`}>{name}</span>
    </Link>
  );
}

/**
 * Decorative product scene for the auth pages: an analytics card with a
 * phone conversation in front of it and live notifications around them.
 * Sample figures are illustrative only.
 */
function ShowcaseScene() {
  return (
    <div aria-hidden="true" className="relative mx-auto h-[22rem] w-full max-w-[30rem]">
      {/* Analytics card */}
      <div className="absolute left-0 top-6 w-[78%] rounded-2xl border border-white/10 bg-white/95 p-4 shadow-2xl shadow-black/30 backdrop-blur">
        <div className="flex items-center justify-between">
          <p className="text-xs font-semibold text-slate-800">Overview</p>
          <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Last 7 days</span>
        </div>
        <div className="mt-3 grid grid-cols-3 gap-2">
          {[
            ['Messages', '12.4k', 'text-emerald-600'],
            ['Read rate', '81%', 'text-sky-600'],
            ['New leads', '326', 'text-indigo-600'],
          ].map(([k, v, c]) => (
            <div key={k} className="rounded-lg bg-slate-50 p-2">
              <p className="text-[9px] text-slate-400">{k}</p>
              <p className={`text-sm font-bold ${c}`}>{v}</p>
            </div>
          ))}
        </div>
        <svg viewBox="0 0 200 60" className="mt-3 h-20 w-full" preserveAspectRatio="none">
          <defs>
            <linearGradient id="auth-area" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor="#10b981" stopOpacity="0.35" />
              <stop offset="100%" stopColor="#10b981" stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d="M0 48 C20 44 30 30 50 34 S80 20 100 24 S130 10 150 14 S180 4 200 6 L200 60 L0 60 Z" fill="url(#auth-area)" />
          <path d="M0 48 C20 44 30 30 50 34 S80 20 100 24 S130 10 150 14 S180 4 200 6" fill="none" stroke="#10b981" strokeWidth="2" />
          <path d="M0 54 C25 52 40 46 60 47 S95 40 120 41 S165 34 200 32" fill="none" stroke="#6366f1" strokeWidth="1.5" strokeDasharray="4 3" />
        </svg>
      </div>

      {/* Phone */}
      <div className="absolute bottom-0 right-0 w-[11.5rem] rounded-[1.8rem] border-[5px] border-slate-900 bg-slate-900 shadow-2xl shadow-black/40">
        <div className="overflow-hidden rounded-[1.4rem] bg-[#efeae2]">
          <div className="flex items-center gap-1.5 bg-[#075e54] px-2.5 pb-2 pt-3 text-white">
            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-[9px] font-bold">W</span>
            <p className="flex items-center gap-1 text-[10px] font-semibold">{BRAND_NAME} <BadgeCheck className="h-2.5 w-2.5 text-emerald-300" /></p>
          </div>
          <div className="space-y-1.5 p-2 text-[10px] leading-snug text-slate-700">
            <div className="w-[85%] rounded-md rounded-tl-none bg-white px-2 py-1 shadow-sm">Your appointment is confirmed for 5 PM ✅</div>
            <div className="w-[85%] rounded-md rounded-tl-none bg-white px-2 py-1 shadow-sm">
              Need to reschedule?
              <div className="mt-1 rounded border border-emerald-200 py-0.5 text-center font-semibold text-emerald-700">Pick a new time</div>
            </div>
            <div className="ml-auto flex w-fit items-center gap-1 rounded-md rounded-tr-none bg-[#d9fdd3] px-2 py-1 shadow-sm">
              Thanks! <CheckCheck className="h-3 w-3 text-sky-500" />
            </div>
          </div>
        </div>
      </div>

      {/* Notifications */}
      <div className="absolute -top-2 right-4 flex items-center gap-2 rounded-xl bg-white px-3 py-2 shadow-xl shadow-black/20">
        <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600"><UserPlus className="h-3.5 w-3.5" /></span>
        <div>
          <p className="text-[9px] text-slate-400">Lead captured</p>
          <p className="text-[11px] font-semibold text-slate-800">from Instagram</p>
        </div>
      </div>
      <div className="absolute bottom-10 left-2 flex items-center gap-2 rounded-xl bg-white px-3 py-2 shadow-xl shadow-black/20">
        <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-100 text-violet-600"><Sparkles className="h-3.5 w-3.5" /></span>
        <div>
          <p className="text-[9px] text-slate-400">AI agent</p>
          <p className="text-[11px] font-semibold text-slate-800">answered 24 chats</p>
        </div>
      </div>
      <div className="absolute bottom-28 left-[44%] flex items-center gap-1.5 rounded-full bg-emerald-500 px-2.5 py-1 text-[10px] font-semibold text-white shadow-lg">
        <TrendingUp className="h-3 w-3" /> +32% replies
      </div>
      <span className="absolute left-[70%] top-[42%] flex h-6 w-6 items-center justify-center rounded-full bg-amber-400 text-white shadow-lg">
        <Bell className="h-3 w-3" />
      </span>
    </div>
  );
}

/** Full-height split layout: illustrated brand panel on the left, the form on the right. */
export function AuthSplit({
  title,
  subtitle,
  points,
  children,
}: {
  title: string;
  subtitle: string;
  points: string[];
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-screen w-full bg-white">
      <aside className="relative hidden w-[48%] flex-col justify-between overflow-hidden bg-slate-950 p-10 text-white lg:flex xl:p-14">
        <div aria-hidden="true" className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(16,185,129,0.35),transparent_45%),radial-gradient(circle_at_85%_80%,rgba(99,102,241,0.30),transparent_45%)]" />
        <div
          aria-hidden="true"
          className="absolute inset-0 opacity-[0.07]"
          style={{ backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)', backgroundSize: '36px 36px' }}
        />
        <div className="relative">
          <BrandMark light />
        </div>

        <div className="relative">
          <h1 className="max-w-md font-display text-3xl font-bold leading-tight xl:text-4xl">{title}</h1>
          <p className="mt-3 max-w-md text-sm text-slate-300 xl:text-base">{subtitle}</p>
          <div className="mt-10">
            <ShowcaseScene />
          </div>
        </div>

        <ul className="relative grid grid-cols-2 gap-x-6 gap-y-2.5 text-sm text-slate-300">
          {points.map((p) => (
            <li key={p} className="flex items-center gap-2">
              <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-400" /> {p}
            </li>
          ))}
        </ul>
      </aside>

      <main className="relative flex w-full flex-col lg:w-[52%]">
        <div aria-hidden="true" className="pointer-events-none absolute right-0 top-0 h-72 w-72 rounded-full bg-emerald-100/60 blur-3xl" />
        <div className="relative flex items-center justify-between px-6 py-5 sm:px-10">
          <div className="lg:invisible">
            <BrandMark />
          </div>
          <Link to="/home" className="text-sm font-medium text-slate-500 hover:text-slate-800">← Back to website</Link>
        </div>
        <div className="relative flex flex-1 items-center justify-center px-6 pb-12 sm:px-10">{children}</div>
      </main>
    </div>
  );
}
