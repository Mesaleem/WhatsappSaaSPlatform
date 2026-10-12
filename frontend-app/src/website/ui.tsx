import { useState, type ComponentType, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Bot,
  Building2,
  Check,
  CheckCheck,
  ChevronDown,
  Code2,
  Megaphone,
  MessageSquare,
  Send,
  Share2,
  Sparkles,
  Users,
  Workflow,
  type LucideProps,
} from 'lucide-react';
import type { SiteFaq, SiteHome } from '../types/site';

/** Icon names the Super Admin can use on feature modules (features.modules[].icon). */
const ICONS: Record<string, ComponentType<LucideProps>> = {
  message: MessageSquare,
  workflow: Workflow,
  users: Users,
  share: Share2,
  megaphone: Megaphone,
  sparkles: Sparkles,
  code: Code2,
  building: Building2,
  bot: Bot,
};

export function SiteIcon({ name, className }: { name?: string; className?: string }) {
  const Icon = (name && ICONS[name]) || Sparkles;
  return <Icon className={className} aria-hidden="true" />;
}

export const list = <T,>(value: T[] | undefined | null): T[] => (Array.isArray(value) ? value : []);

export function Container({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8 ${className}`}>{children}</div>;
}

export function ButtonLink({
  to,
  children,
  variant = 'primary',
  className = '',
}: {
  to: string;
  children: ReactNode;
  variant?: 'primary' | 'secondary' | 'light';
  className?: string;
}) {
  const styles = {
    primary: 'bg-emerald-600 text-white shadow-lg shadow-emerald-600/25 hover:bg-emerald-700',
    secondary: 'border border-slate-300 bg-white text-slate-800 hover:border-slate-400 hover:bg-slate-50',
    light: 'bg-white text-emerald-700 shadow-lg hover:bg-emerald-50',
  }[variant];
  return (
    <Link
      to={to}
      className={`inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 ${styles} ${className}`}
    >
      {children}
    </Link>
  );
}

export function SectionHeading({ eyebrow, title, subtitle, center = true }: { eyebrow?: string; title?: string; subtitle?: string; center?: boolean }) {
  return (
    <div className={center ? 'mx-auto max-w-3xl text-center' : 'max-w-3xl'}>
      {eyebrow && <p className="text-sm font-semibold uppercase tracking-wider text-emerald-600">{eyebrow}</p>}
      {title && <h2 className="mt-2 font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{title}</h2>}
      {subtitle && <p className="mt-4 text-base leading-relaxed text-slate-600 sm:text-lg">{subtitle}</p>}
    </div>
  );
}

/** Top banner used by every inner page (Features, Pricing, About, Contact, legal). */
export function PageHero({ title, subtitle, children }: { title?: string; subtitle?: string; children?: ReactNode }) {
  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-emerald-50 via-white to-white">
      <div aria-hidden="true" className="pointer-events-none absolute -top-24 left-1/2 h-72 w-[42rem] -translate-x-1/2 rounded-full bg-emerald-200/40 blur-3xl" />
      <Container className="relative py-16 text-center sm:py-20">
        <h1 className="font-display text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">{title}</h1>
        {subtitle && <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-600">{subtitle}</p>}
        {children}
      </Container>
    </section>
  );
}

export function BulletList({ items }: { items: string[] }) {
  return (
    <ul className="space-y-2.5">
      {items.map((item, i) => (
        <li key={i} className="flex items-start gap-2.5 text-sm text-slate-600">
          <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
            <Check className="h-3 w-3" strokeWidth={3} />
          </span>
          <span>{item}</span>
        </li>
      ))}
    </ul>
  );
}

export function FaqList({ faq }: { faq: Partial<SiteFaq> | undefined }) {
  const [open, setOpen] = useState<number | null>(0);
  const items = list(faq?.items);
  if (items.length === 0) return null;

  return (
    <div className="mx-auto max-w-3xl space-y-3">
      {items.map((item, i) => {
        const isOpen = open === i;
        return (
          <div
            key={i}
            className={`overflow-hidden rounded-2xl border bg-white transition-[border-color,box-shadow] duration-300 ${
              isOpen ? 'border-emerald-300 shadow-lg shadow-emerald-900/5' : 'border-slate-200 hover:border-slate-300'
            }`}
          >
            <button
              type="button"
              id={`faq-q-${i}`}
              onClick={() => setOpen(isOpen ? null : i)}
              aria-expanded={isOpen}
              aria-controls={`faq-a-${i}`}
              className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left text-base font-semibold text-slate-900 outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 sm:px-6"
            >
              <span className={`transition-colors duration-300 ${isOpen ? 'text-emerald-700' : ''}`}>{item.question}</span>
              <span
                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-all duration-300 ${
                  isOpen ? 'rotate-180 bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500'
                }`}
              >
                <ChevronDown className="h-4 w-4" />
              </span>
            </button>
            {/* grid-rows 0fr → 1fr animates to the answer's real height, open and close. */}
            <div
              id={`faq-a-${i}`}
              role="region"
              aria-labelledby={`faq-q-${i}`}
              className={`grid transition-[grid-template-rows,opacity] duration-300 ease-out ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}
            >
              <div className="overflow-hidden">
                <p className="px-5 pb-5 text-sm leading-relaxed text-slate-600 sm:px-6">{item.answer}</p>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export function CtaBand({ cta, to }: { cta: Partial<SiteHome['cta']> | undefined; to: string }) {
  if (!cta?.title) return null;
  return (
    <section className="py-16 sm:py-20">
      <Container>
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-800 px-6 py-14 text-center shadow-xl sm:px-12">
          <div aria-hidden="true" className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-white/10 blur-2xl" />
          <div aria-hidden="true" className="pointer-events-none absolute -bottom-24 -left-16 h-64 w-64 rounded-full bg-teal-300/20 blur-2xl" />
          <CtaDecor />
          <h2 className="relative font-display text-3xl font-bold text-white sm:text-4xl">{cta.title}</h2>
          {cta.subtitle && <p className="relative mx-auto mt-3 max-w-xl text-emerald-50">{cta.subtitle}</p>}
          {cta.button && (
            <div className="relative mt-8">
              <ButtonLink to={to} variant="light">
                {cta.button} <ArrowRight className="h-4 w-4" />
              </ButtonLink>
            </div>
          )}
        </div>
      </Container>
    </section>
  );
}

/** Faint background graphics for the CTA band: a dot grid, chat bubbles and ticks. Kept at low opacity. */
function CtaDecor() {
  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-0">
      <div
        className="absolute inset-0 opacity-[0.12] [mask-image:radial-gradient(ellipse_at_center,transparent_30%,black_75%)]"
        style={{ backgroundImage: 'radial-gradient(#fff 1px, transparent 1px)', backgroundSize: '18px 18px' }}
      />
      {/* Left: incoming chat */}
      <div className="absolute left-8 top-1/2 hidden -translate-y-1/2 -rotate-6 space-y-2 opacity-25 lg:block">
        <div className="h-8 w-36 rounded-2xl rounded-tl-sm bg-white/70" />
        <div className="h-8 w-24 rounded-2xl rounded-tl-sm bg-white/50" />
        <div className="ml-10 flex h-8 w-28 items-center justify-end gap-1 rounded-2xl rounded-tr-sm bg-emerald-200/70 pr-3">
          <CheckCheck className="h-4 w-4 text-sky-600" />
        </div>
      </div>
      {/* Right: icons */}
      <div className="absolute right-10 top-1/2 hidden -translate-y-1/2 rotate-6 opacity-25 lg:block">
        <div className="relative h-32 w-40">
          <span className="absolute left-0 top-2 flex h-12 w-12 items-center justify-center rounded-2xl bg-white/70 text-emerald-700"><MessageSquare className="h-6 w-6" /></span>
          <span className="absolute right-0 top-0 flex h-10 w-10 items-center justify-center rounded-xl bg-white/50 text-emerald-700"><Send className="h-5 w-5" /></span>
          <span className="absolute bottom-0 left-12 flex h-11 w-11 items-center justify-center rounded-2xl bg-white/60 text-emerald-700"><Users className="h-5 w-5" /></span>
        </div>
      </div>
    </div>
  );
}

/**
 * Illustrative product preview drawn in markup (no screenshots needed):
 * a WhatsApp-style chat beside a mini journey flow. Purely decorative.
 */
export function ProductMockup({ brand }: { brand: string }) {
  return (
    <div aria-hidden="true" className="relative mx-auto w-full max-w-xl">
      <div className="absolute -inset-4 rounded-[2rem] bg-gradient-to-tr from-emerald-200/60 via-teal-100/40 to-indigo-200/50 blur-2xl" />
      <div className="relative grid gap-4 sm:grid-cols-5">
        {/* Chat */}
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl sm:col-span-3">
          <div className="flex items-center gap-3 bg-emerald-700 px-4 py-3 text-white">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 text-xs font-bold">
              {brand.slice(0, 1).toUpperCase()}
            </div>
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold">{brand}</p>
              <p className="text-[11px] text-emerald-100">online</p>
            </div>
          </div>
          <div className="space-y-2.5 bg-[#efeae2] p-4 text-[13px]">
            <div className="max-w-[85%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-slate-700 shadow-sm">
              Hi Asha 👋 Your order #1042 has shipped and will arrive tomorrow.
            </div>
            <div className="max-w-[85%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-slate-700 shadow-sm">
              Anything else we can help with?
              <div className="mt-2 grid gap-1.5">
                <span className="rounded-md border border-emerald-200 py-1 text-center text-xs font-medium text-emerald-700">Track order</span>
                <span className="rounded-md border border-emerald-200 py-1 text-center text-xs font-medium text-emerald-700">Talk to an agent</span>
              </div>
            </div>
            <div className="ml-auto flex max-w-[70%] items-end gap-1 rounded-lg rounded-tr-none bg-[#d9fdd3] px-3 py-2 text-slate-700 shadow-sm">
              Track order <CheckCheck className="h-3.5 w-3.5 shrink-0 text-sky-500" />
            </div>
          </div>
        </div>
        {/* Journey + stats */}
        <div className="space-y-4 sm:col-span-2">
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Journey</p>
            <div className="mt-3 space-y-2 text-xs font-medium">
              <div className="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 ring-1 ring-emerald-200">Keyword: “order”</div>
              <div className="mx-auto h-3 w-px bg-slate-300" />
              <div className="rounded-lg bg-indigo-50 px-3 py-2 text-indigo-800 ring-1 ring-indigo-200">Buttons</div>
              <div className="mx-auto h-3 w-px bg-slate-300" />
              <div className="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 ring-1 ring-amber-200">Save lead → CRM</div>
            </div>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Campaign</p>
            <div className="mt-3 space-y-2">
              {[
                ['Sent', 'w-full', 'bg-slate-300'],
                ['Delivered', 'w-11/12', 'bg-emerald-400'],
                ['Read', 'w-3/4', 'bg-emerald-600'],
              ].map(([label, width, color]) => (
                <div key={label}>
                  <p className="text-[11px] text-slate-500">{label}</p>
                  <div className="mt-1 h-2 rounded-full bg-slate-100">
                    <div className={`h-2 rounded-full ${width} ${color}`} />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
