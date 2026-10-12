import type { ReactNode } from 'react';
import {
  BadgeCheck,
  Bot,
  CalendarClock,
  CheckCheck,
  FileSpreadsheet,
  Heart,
  Image as ImageIcon,
  MessageCircle,
  MousePointerClick,
  Send,
  Smartphone,
  Sparkles,
  UserPlus,
  Users,
  Zap,
} from 'lucide-react';

/*
 * Product illustrations for the public website, drawn in markup so they stay
 * sharp at any size and need no screenshots. Each one shows how a feature
 * works. All decorative (aria-hidden); the sample names and numbers inside
 * them are illustrative, not customer data.
 */

function Frame({ title, children, className = '' }: { title?: string; children: ReactNode; className?: string }) {
  return (
    <div className={`overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/10 ${className}`}>
      <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
        <span className="h-2.5 w-2.5 rounded-full bg-red-300" />
        <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
        <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
        {title && <span className="ml-3 truncate text-[11px] font-medium text-slate-400">{title}</span>}
      </div>
      {children}
    </div>
  );
}

function Floating({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={`absolute z-10 flex items-center gap-2.5 rounded-xl border border-slate-100 bg-white px-3 py-2 shadow-xl shadow-slate-900/10 ${className}`}>
      {children}
    </div>
  );
}

function Bubble({ me, children }: { me?: boolean; children: ReactNode }) {
  return (
    <div
      className={`max-w-[82%] rounded-lg px-2.5 py-1.5 text-[12px] leading-snug text-slate-700 shadow-sm ${
        me ? 'ml-auto rounded-tr-none bg-[#d9fdd3]' : 'rounded-tl-none bg-white'
      }`}
    >
      {children}
    </div>
  );
}

/** Hero: a phone with a live WhatsApp conversation, surrounded by what happens around it. */
export function HeroIllustration({ brand }: { brand: string }) {
  return (
    <div aria-hidden="true" className="relative mx-auto w-full max-w-[30rem] px-6 py-6 sm:px-10">
      <div className="absolute inset-6 rounded-[3rem] bg-gradient-to-tr from-emerald-300/50 via-teal-200/40 to-sky-200/50 blur-3xl" />

      <div className="relative mx-auto w-[15.5rem] rounded-[2.4rem] border-[7px] border-slate-900 bg-slate-900 shadow-2xl sm:w-[17rem]">
        <div className="absolute left-1/2 top-1.5 z-10 h-4 w-20 -translate-x-1/2 rounded-full bg-slate-900" />
        <div className="overflow-hidden rounded-[1.9rem] bg-[#efeae2]">
          <div className="flex items-center gap-2 bg-[#075e54] px-3 pb-2.5 pt-6 text-white">
            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-white/20 text-[11px] font-bold">{brand.slice(0, 1).toUpperCase()}</span>
            <div className="min-w-0">
              <p className="flex items-center gap-1 truncate text-[12px] font-semibold">
                {brand} <BadgeCheck className="h-3 w-3 text-emerald-300" />
              </p>
              <p className="text-[10px] text-emerald-100">online</p>
            </div>
          </div>
          <div className="space-y-2 p-3">
            <Bubble me>Hi, do you have the summer collection?</Bubble>
            <Bubble>
              Yes! 🌞 Here are this week's picks.
              <div className="mt-1.5 h-16 rounded-md bg-gradient-to-br from-amber-200 via-rose-200 to-fuchsia-200" />
            </Bubble>
            <Bubble>
              What would you like to do?
              <div className="mt-1.5 space-y-1">
                <div className="rounded border border-emerald-200 bg-emerald-50/50 py-1 text-center text-[11px] font-semibold text-emerald-700">View catalogue</div>
                <div className="rounded border border-emerald-200 bg-emerald-50/50 py-1 text-center text-[11px] font-semibold text-emerald-700">Talk to sales</div>
              </div>
            </Bubble>
            <div className="ml-auto flex w-fit items-center gap-1 rounded-lg rounded-tr-none bg-[#d9fdd3] px-2.5 py-1.5 text-[12px] shadow-sm">
              Talk to sales <CheckCheck className="h-3.5 w-3.5 text-sky-500" />
            </div>
          </div>
        </div>
      </div>

      <Floating className="left-0 top-10 sm:-left-2">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700"><Send className="h-4 w-4" /></span>
        <div>
          <p className="text-[10px] text-slate-400">Campaign delivered</p>
          <p className="text-sm font-bold text-slate-900">98.4%</p>
        </div>
      </Floating>
      <Floating className="right-0 top-1/3 sm:-right-4">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700"><UserPlus className="h-4 w-4" /></span>
        <div>
          <p className="text-[10px] text-slate-400">New lead in CRM</p>
          <p className="text-xs font-semibold text-slate-900">Priya · Hot</p>
        </div>
      </Floating>
      <Floating className="bottom-6 left-0 sm:-left-6">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 text-violet-700"><Sparkles className="h-4 w-4" /></span>
        <div>
          <p className="text-[10px] text-slate-400">AI replied</p>
          <p className="text-xs font-semibold text-slate-900">in 2 seconds</p>
        </div>
      </Floating>
    </div>
  );
}

/** Broadcast: pick audience + template + time, then watch sent → delivered → read. */
export function BroadcastIllustration() {
  return (
    <Frame title="New campaign">
      <div className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
        <div className="space-y-3 text-[12px]">
          <Step n={1} label="Audience">
            <div className="flex flex-wrap gap-1">
              {['All Contacts', 'VIP', 'Leads – May'].map((t, i) => (
                <span key={t} className={`rounded-full px-2 py-0.5 text-[11px] ${i === 1 ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600'}`}>{t}</span>
              ))}
            </div>
          </Step>
          <Step n={2} label="Template">
            <div className="rounded-lg bg-[#efeae2] p-2">
              <div className="rounded-md bg-white p-2 text-[11px] text-slate-700 shadow-sm">
                Hi <span className="rounded bg-amber-100 px-1 font-mono text-amber-800">{'{{name}}'}</span>, our sale is live — 30% off till Sunday!
              </div>
            </div>
          </Step>
          <Step n={3} label="Send">
            <div className="flex items-center gap-1.5 text-slate-600">
              <CalendarClock className="h-3.5 w-3.5 text-emerald-600" /> Today, 10:00 AM
            </div>
          </Step>
        </div>
        <div className="rounded-xl bg-slate-50 p-4">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Results</p>
          {[
            ['Sent', '2,400', 'w-full', 'bg-slate-300'],
            ['Delivered', '2,362', 'w-[96%]', 'bg-emerald-400'],
            ['Read', '1,987', 'w-[80%]', 'bg-emerald-600'],
            ['Replied', '612', 'w-[26%]', 'bg-indigo-500'],
          ].map(([label, n, w, c]) => (
            <div key={label} className="mt-3">
              <div className="flex justify-between text-[11px]">
                <span className="text-slate-500">{label}</span>
                <span className="font-semibold text-slate-800">{n}</span>
              </div>
              <div className="mt-1 h-2 rounded-full bg-white">
                <div className={`h-2 rounded-full ${w} ${c}`} />
              </div>
            </div>
          ))}
        </div>
      </div>
    </Frame>
  );
}

function Step({ n, label, children }: { n: number; label: string; children: ReactNode }) {
  return (
    <div className="flex gap-2.5">
      <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700">{n}</span>
      <div className="min-w-0 flex-1">
        <p className="mb-1 font-semibold text-slate-800">{label}</p>
        {children}
      </div>
    </div>
  );
}

function Node({ color, icon, title, sub, className = '' }: { color: string; icon: ReactNode; title: string; sub?: string; className?: string }) {
  return (
    <div className={`absolute w-[42%] rounded-xl border bg-white p-2.5 shadow-lg ${color} ${className}`}>
      <p className="flex items-center gap-1.5 text-[11px] font-bold text-slate-800">{icon} {title}</p>
      {sub && <p className="mt-0.5 truncate text-[10px] text-slate-500">{sub}</p>}
    </div>
  );
}

/** Journey Builder: nodes on a canvas joined by connectors. */
export function JourneyIllustration() {
  return (
    <Frame title="Journey Builder — Order help">
      <div
        className="relative h-[19rem] bg-slate-50"
        style={{ backgroundImage: 'radial-gradient(#cbd5e1 1px, transparent 1px)', backgroundSize: '16px 16px' }}
      >
        <svg className="absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" fill="none">
          <path d="M29 22 C29 32, 50 30, 50 40" stroke="#10b981" strokeWidth="0.6" vectorEffect="non-scaling-stroke" strokeDasharray="3 2" />
          <path d="M50 55 C50 65, 26 62, 26 72" stroke="#6366f1" strokeWidth="0.6" vectorEffect="non-scaling-stroke" strokeDasharray="3 2" />
          <path d="M50 55 C50 65, 74 62, 74 72" stroke="#f59e0b" strokeWidth="0.6" vectorEffect="non-scaling-stroke" strokeDasharray="3 2" />
        </svg>
        <Node className="left-[6%] top-[6%]" color="border-emerald-300" icon={<Zap className="h-3.5 w-3.5 text-emerald-600" />} title="Trigger" sub='Keyword "order"' />
        <Node className="left-[29%] top-[40%]" color="border-sky-300" icon={<MousePointerClick className="h-3.5 w-3.5 text-sky-600" />} title="Reply buttons" sub="Track · Return · Agent" />
        <Node className="left-[4%] top-[72%]" color="border-indigo-300" icon={<Bot className="h-3.5 w-3.5 text-indigo-600" />} title="AI answer" sub="From knowledge base" />
        <Node className="right-[4%] top-[72%]" color="border-amber-300" icon={<UserPlus className="h-3.5 w-3.5 text-amber-600" />} title="Save lead" sub="→ CRM · assign agent" />
        <div className="absolute right-3 top-3 hidden rounded-lg border border-slate-200 bg-white p-2 text-[10px] text-slate-500 shadow sm:block">
          <p className="font-semibold text-slate-700">Add node</p>
          {['Message', 'Question', 'Condition', 'Delay', 'API call'].map((n) => (
            <p key={n} className="mt-1 rounded bg-slate-50 px-2 py-0.5">{n}</p>
          ))}
        </div>
      </div>
    </Frame>
  );
}

/** CRM: leads moving across a pipeline. */
export function CrmIllustration() {
  const cols = [
    { name: 'New', color: 'bg-sky-500', cards: [['Rahul M.', 'WhatsApp'], ['Neha S.', 'Lead ad']] },
    { name: 'Contacted', color: 'bg-amber-500', cards: [['Priya K.', 'Instagram']] },
    { name: 'Won', color: 'bg-emerald-500', cards: [['Arjun P.', '₹12,000'], ['Sara T.', '₹4,500']] },
  ];
  return (
    <Frame title="CRM Pipeline">
      <div className="grid grid-cols-3 gap-2.5 bg-slate-50 p-3 sm:gap-3 sm:p-4">
        {cols.map((c) => (
          <div key={c.name} className="rounded-xl bg-white/70 p-2">
            <p className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-700">
              <span className={`h-2 w-2 rounded-full ${c.color}`} /> {c.name}
              <span className="ml-auto text-slate-400">{c.cards.length}</span>
            </p>
            <div className="mt-2 space-y-2">
              {c.cards.map(([who, tag]) => (
                <div key={who} className="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
                  <div className="flex items-center gap-1.5">
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[9px] font-bold text-slate-600">{who[0]}</span>
                    <p className="truncate text-[11px] font-semibold text-slate-800">{who}</p>
                  </div>
                  <span className="mt-1.5 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[9px] text-slate-500">{tag}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </Frame>
  );
}

/** Social: compose once, publish to Facebook and Instagram, answer comments. */
export function SocialIllustration() {
  return (
    <Frame title="Create post">
      <div className="grid gap-4 p-4 sm:grid-cols-5 sm:p-5">
        <div className="space-y-2.5 sm:col-span-3">
          <div className="flex gap-1.5">
            <span className="rounded-full bg-[#1877f2] px-2.5 py-1 text-[10px] font-semibold text-white">Facebook Page</span>
            <span className="rounded-full bg-gradient-to-r from-fuchsia-500 via-rose-500 to-amber-400 px-2.5 py-1 text-[10px] font-semibold text-white">Instagram</span>
          </div>
          <div className="rounded-xl border border-slate-200 p-3">
            <div className="flex h-24 items-center justify-center rounded-lg bg-gradient-to-br from-sky-200 via-indigo-200 to-violet-200">
              <ImageIcon className="h-6 w-6 text-white" />
            </div>
            <p className="mt-2 text-[11px] text-slate-600">New arrivals are here ✨ Shop now, link in bio.</p>
            <div className="mt-2 flex gap-3 text-[10px] text-slate-400">
              <span className="flex items-center gap-1"><Heart className="h-3 w-3 text-rose-500" /> 1.2k</span>
              <span className="flex items-center gap-1"><MessageCircle className="h-3 w-3" /> 86</span>
            </div>
          </div>
        </div>
        <div className="sm:col-span-2">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Comments</p>
          <div className="mt-2 space-y-2 text-[11px]">
            <div className="rounded-lg bg-slate-100 p-2"><b>@meera</b> Price please?</div>
            <div className="ml-3 rounded-lg bg-emerald-50 p-2 text-emerald-800">
              <span className="flex items-center gap-1 text-[9px] font-semibold uppercase text-emerald-600"><Zap className="h-3 w-3" /> Auto-reply</span>
              Sent you the details in DM 💬
            </div>
            <div className="rounded-lg bg-slate-100 p-2"><b>@dev_k</b> Do you deliver to Pune?</div>
          </div>
        </div>
      </div>
    </Frame>
  );
}

/** Ads: campaign metrics and leads flowing to the CRM. */
export function AdsIllustration() {
  return (
    <Frame title="Ads dashboard">
      <div className="space-y-4 p-4 sm:p-5">
        <div className="grid grid-cols-3 gap-2">
          {[
            ['Spend', '₹8,450'],
            ['Leads', '214'],
            ['Cost / lead', '₹39'],
          ].map(([k, v]) => (
            <div key={k} className="rounded-xl bg-slate-50 p-2.5">
              <p className="text-[10px] text-slate-400">{k}</p>
              <p className="text-sm font-bold text-slate-900 sm:text-base">{v}</p>
            </div>
          ))}
        </div>
        <div className="flex h-24 items-end gap-1.5 rounded-xl bg-gradient-to-b from-white to-emerald-50/60 px-2 pb-2">
          {[30, 45, 38, 60, 52, 75, 68, 90, 84, 100].map((h, i) => (
            <div key={i} className="flex-1 rounded-t bg-gradient-to-t from-emerald-600 to-teal-400" style={{ height: `${h}%` }} />
          ))}
        </div>
        <div className="flex items-center gap-2 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/50 p-2.5 text-[11px] text-emerald-800">
          <FileSpreadsheet className="h-4 w-4 shrink-0" /> Lead form → <b>CRM</b> → WhatsApp welcome message, automatically
        </div>
      </div>
    </Frame>
  );
}

/** AI: answers from your knowledge base and writes templates. */
export function AiIllustration() {
  return (
    <Frame title="AI assistant">
      <div className="grid gap-4 p-4 sm:grid-cols-5 sm:p-5">
        <div className="space-y-2 rounded-xl bg-[#efeae2] p-3 sm:col-span-3">
          <Bubble me>What are your store timings on Sunday?</Bubble>
          <Bubble>
            <span className="mb-0.5 flex items-center gap-1 text-[9px] font-semibold uppercase text-violet-600"><Sparkles className="h-3 w-3" /> AI agent</span>
            We're open 11 AM – 7 PM on Sundays. Want me to book a slot for you?
          </Bubble>
          <Bubble me>Yes, 4 PM please</Bubble>
        </div>
        <div className="space-y-2 sm:col-span-2">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Knowledge base</p>
          {['Store timings.pdf', 'Price list.xlsx', 'FAQ page'].map((f) => (
            <div key={f} className="flex items-center gap-2 rounded-lg border border-slate-200 px-2 py-1.5 text-[11px] text-slate-600">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" /> {f}
            </div>
          ))}
          <div className="rounded-lg bg-violet-50 p-2 text-[11px] text-violet-800">
            <span className="font-semibold">Write a template:</span> “Diwali offer for repeat buyers” ✨
          </div>
        </div>
      </div>
    </Frame>
  );
}

/** Developer API: one request sends a message; a webhook reports back. */
export function ApiIllustration() {
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 shadow-2xl shadow-slate-900/30">
      <div className="flex items-center gap-1.5 border-b border-slate-800 px-4 py-2.5">
        <span className="h-2.5 w-2.5 rounded-full bg-red-400/70" />
        <span className="h-2.5 w-2.5 rounded-full bg-amber-400/70" />
        <span className="h-2.5 w-2.5 rounded-full bg-emerald-400/70" />
        <span className="ml-3 text-[11px] text-slate-500">send-message.sh</span>
      </div>
      <pre className="overflow-x-auto p-4 font-mono text-[11px] leading-relaxed text-slate-300 sm:text-[12px]">
        <span className="text-emerald-400">curl</span> -X POST https://api.yourdomain/v1/messages \{'\n'}
        {'  '}-H <span className="text-amber-300">"Authorization: Bearer $API_KEY"</span> \{'\n'}
        {'  '}-d <span className="text-amber-300">'{'{'}"to":"+9198xxxxxx01","text":"Your OTP is 4821"{'}'}'</span>
        {'\n\n'}
        <span className="text-slate-500"># webhook → your server</span>{'\n'}
        {'{'} <span className="text-sky-300">"event"</span>: <span className="text-amber-300">"message.delivered"</span>, <span className="text-sky-300">"status"</span>: <span className="text-amber-300">"read"</span> {'}'}
      </pre>
    </div>
  );
}

/** Teams & agencies: one login, many client workspaces. */
export function TeamIllustration() {
  return (
    <Frame title="Agency workspace">
      <div className="space-y-2 p-4 sm:p-5">
        {[
          ['Sunrise Bakery', 'Active', '3 users', 'bg-emerald-100 text-emerald-700'],
          ['Metro Realty', 'Active', '5 users', 'bg-emerald-100 text-emerald-700'],
          ['Bright Tutors', 'Trial', '2 users', 'bg-amber-100 text-amber-700'],
        ].map(([name, status, users, badge]) => (
          <div key={name} className="flex items-center gap-3 rounded-xl border border-slate-200 p-2.5">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">{name[0]}</span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[12px] font-semibold text-slate-800">{name}</p>
              <p className="flex items-center gap-1 text-[10px] text-slate-400"><Users className="h-3 w-3" /> {users}</p>
            </div>
            <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${badge}`}>{status}</span>
          </div>
        ))}
        <div className="flex items-center justify-between rounded-xl bg-indigo-50 px-3 py-2 text-[11px] text-indigo-800">
          <span>Roles: Admin · Agent · Viewer</span>
          <span className="font-semibold">+ Add client</span>
        </div>
      </div>
    </Frame>
  );
}

/** QR connect: scan the code with WhatsApp and the number is linked. */
export function QrIllustration() {
  // A fixed pseudo-random pattern; not a real, scannable code.
  const cells = Array.from({ length: 121 }, (_, i) => ((i * 7919) % 13) % 3 === 0);
  const finder = (r: number, c: number) => (r < 3 && c < 3) || (r < 3 && c > 7) || (r > 7 && c < 3);
  return (
    <div className="flex items-center justify-center gap-3">
      <div className="grid grid-cols-11 gap-[2px] rounded-lg bg-white p-2 shadow-md ring-1 ring-slate-200">
        {cells.map((on, i) => {
          const r = Math.floor(i / 11);
          const c = i % 11;
          return <span key={i} className={`h-[5px] w-[5px] rounded-[1px] ${finder(r, c) || on ? 'bg-slate-900' : 'bg-transparent'}`} />;
        })}
      </div>
      <Smartphone className="h-8 w-8 text-emerald-600" />
    </div>
  );
}

const BY_ICON: Record<string, () => ReactNode> = {
  message: () => <BroadcastIllustration />,
  workflow: () => <JourneyIllustration />,
  users: () => <CrmIllustration />,
  share: () => <SocialIllustration />,
  megaphone: () => <AdsIllustration />,
  sparkles: () => <AiIllustration />,
  bot: () => <AiIllustration />,
  code: () => <ApiIllustration />,
  building: () => <TeamIllustration />,
};

/** The illustration for a feature module, chosen by its icon name. */
export function FeatureIllustration({ icon }: { icon?: string }) {
  const draw = (icon && BY_ICON[icon]) || BY_ICON.message;
  return <div aria-hidden="true">{draw()}</div>;
}

