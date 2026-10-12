import { useState } from 'react';
import { ArrowRight, BarChart3, Briefcase, CheckCircle2, Code2, GraduationCap, Home, LayoutDashboard, QrCode, ShoppingBag, Sparkles, Upload, UserPlus, Workflow } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useSiteContent } from '../SiteContentContext';
import { FeatureIllustration, HeroIllustration, QrIllustration } from '../illustrations';
import { BulletList, ButtonLink, Container, CtaBand, FaqList, SectionHeading, SiteIcon, list } from '../ui';

const CHANNELS = [
  { label: 'WhatsApp', className: 'bg-[#25d366]/10 text-[#128c7e] ring-[#25d366]/30' },
  { label: 'Facebook', className: 'bg-[#1877f2]/10 text-[#1877f2] ring-[#1877f2]/30' },
  { label: 'Instagram', className: 'bg-rose-50 text-rose-600 ring-rose-200' },
  { label: 'Messenger', className: 'bg-sky-50 text-sky-600 ring-sky-200' },
  { label: 'Meta Ads', className: 'bg-indigo-50 text-indigo-600 ring-indigo-200' },
];

const INDUSTRY_ICONS = [ShoppingBag, GraduationCap, Home, Briefcase];
const HIGHLIGHT_ICONS = [QrCode, Workflow, LayoutDashboard, Code2];

/** A small drawing for each "how it works" step, in order. */
function StepVisual({ index }: { index: number }) {
  switch (index) {
    case 0:
      return (
        <div className="w-full space-y-1.5 rounded-xl bg-white p-3 shadow-inner ring-1 ring-slate-200">
          <div className="h-2 w-2/3 rounded bg-slate-200" />
          <div className="h-5 rounded-md border border-slate-200" />
          <div className="h-5 rounded-md border border-slate-200" />
          <div className="flex h-5 items-center justify-center rounded-md bg-emerald-600 text-[9px] font-semibold text-white">Create account</div>
        </div>
      );
    case 1:
      return <QrIllustration />;
    case 2:
      return (
        <div className="flex w-full items-center justify-between gap-1 text-[10px] font-semibold">
          <span className="flex items-center gap-1 rounded-lg bg-white px-2 py-1.5 text-slate-600 ring-1 ring-slate-200"><Upload className="h-3 w-3" /> CSV</span>
          <ArrowRight className="h-3 w-3 shrink-0 text-slate-400" />
          <span className="rounded-lg bg-emerald-50 px-2 py-1.5 text-emerald-700 ring-1 ring-emerald-200">Trigger</span>
          <ArrowRight className="h-3 w-3 shrink-0 text-slate-400" />
          <span className="rounded-lg bg-indigo-50 px-2 py-1.5 text-indigo-700 ring-1 ring-indigo-200">Reply</span>
        </div>
      );
    default:
      return (
        <div className="flex h-14 w-full items-end gap-1 rounded-xl bg-white p-2 ring-1 ring-slate-200">
          {[35, 55, 45, 70, 62, 88].map((h, i) => (
            <div key={i} className="flex-1 rounded-t bg-gradient-to-t from-emerald-600 to-teal-400" style={{ height: `${h}%` }} />
          ))}
        </div>
      );
  }
}

export default function HomePage() {
  const { content } = useSiteContent();
  const { isAuthenticated } = useAuth();
  const [active, setActive] = useState(0);
  if (!content) return null;

  const { brand, home, features, faq } = content;
  const hero = home.hero;
  const startTo = isAuthenticated ? '/' : brand.signup_enabled !== false ? '/signup' : '/contact';
  const modules = list(features.modules);
  const current = modules[Math.min(active, modules.length - 1)];
  const trialDays = Number(brand.trial_days) || 0;

  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden bg-[radial-gradient(ellipse_at_top_left,_#d1fae5_0%,_#ffffff_45%),radial-gradient(ellipse_at_bottom_right,_#e0e7ff_0%,_#ffffff_50%)]">
        <Container className="relative grid items-center gap-10 pb-16 pt-12 sm:pt-16 lg:grid-cols-[1.05fr_1fr] lg:pb-24 lg:pt-20">
          <div className="text-center lg:text-left">
            {hero?.eyebrow && (
              <p className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-1 text-xs font-semibold text-emerald-700 shadow-sm">
                <Sparkles className="h-3.5 w-3.5" /> {hero.eyebrow}
              </p>
            )}
            <h1 className="mt-5 font-display text-4xl font-extrabold leading-[1.08] tracking-tight text-slate-900 sm:text-5xl xl:text-[3.6rem]">
              {hero?.title}
            </h1>
            {hero?.subtitle && <p className="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-slate-600 lg:mx-0">{hero.subtitle}</p>}
            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
              {hero?.primary_cta && (
                <ButtonLink to={startTo} className="px-7 py-3.5 text-base">
                  {isAuthenticated ? 'Go to dashboard' : hero.primary_cta} <ArrowRight className="h-4 w-4" />
                </ButtonLink>
              )}
              {hero?.secondary_cta && <ButtonLink to="/features" variant="secondary" className="px-7 py-3.5 text-base">{hero.secondary_cta}</ButtonLink>}
            </div>
            {!isAuthenticated && trialDays > 0 && brand.signup_enabled !== false && (
              <p className="mt-4 flex items-center justify-center gap-4 text-sm text-slate-500 lg:justify-start">
                <span className="flex items-center gap-1"><CheckCircle2 className="h-4 w-4 text-emerald-600" /> {trialDays}-day free trial</span>
                <span className="flex items-center gap-1"><CheckCircle2 className="h-4 w-4 text-emerald-600" /> No card needed</span>
              </p>
            )}
            <div className="mt-8 flex flex-wrap justify-center gap-2 lg:justify-start">
              {CHANNELS.map((c) => (
                <span key={c.label} className={`rounded-full px-3 py-1 text-xs font-semibold ring-1 ${c.className}`}>{c.label}</span>
              ))}
            </div>
          </div>
          <HeroIllustration brand={brand.name || 'Your business'} />
        </Container>
      </section>

      {/* Highlights */}
      {list(home.highlights).length > 0 && (
        <section className="relative z-10 -mt-6">
          <Container>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {list(home.highlights).map((h, i) => {
                const Icon = HIGHLIGHT_ICONS[i % HIGHLIGHT_ICONS.length];
                return (
                  <div
                    key={i}
                    className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-lg shadow-slate-900/5 transition duration-300 hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-900/10"
                  >
                    <span aria-hidden="true" className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-400 via-teal-500 to-emerald-600 opacity-0 transition group-hover:opacity-100" />
                    <span aria-hidden="true" className="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-emerald-50 transition group-hover:scale-125" />
                    <div className="relative flex items-start gap-4">
                      <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md shadow-emerald-500/30">
                        <Icon className="h-6 w-6" />
                      </span>
                      <div className="min-w-0">
                        <p className="font-display text-lg font-extrabold leading-tight text-slate-900">{h.value}</p>
                        <p className="mt-1 text-sm leading-snug text-slate-500">{h.label}</p>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </Container>
        </section>
      )}

      {/* How it works, drawn */}
      {list(home.steps).length > 0 && (
        <section className="py-20 sm:py-24">
          <Container>
            <SectionHeading eyebrow="How it works" title={home.steps_title} />
            <ol className="relative mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              <div aria-hidden="true" className="absolute left-[12%] right-[12%] top-[3.25rem] hidden border-t-2 border-dashed border-emerald-200 lg:block" />
              {list(home.steps).map((s, i) => (
                <li key={i} className="relative flex flex-col items-center rounded-3xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                  <span className="relative z-10 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 font-display text-lg font-bold text-white shadow-lg shadow-emerald-500/30">
                    {i + 1}
                  </span>
                  <div className="mt-5 flex h-24 w-full items-center justify-center rounded-2xl bg-slate-50 p-3" aria-hidden="true">
                    <StepVisual index={i} />
                  </div>
                  <h3 className="mt-5 font-display text-lg font-semibold text-slate-900">{s.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-600">{s.description}</p>
                </li>
              ))}
            </ol>
          </Container>
        </section>
      )}

      {/* Product tour */}
      {current && (
        <section className="bg-slate-950 py-20 text-white sm:py-24">
          <Container>
            <div className="mx-auto max-w-3xl text-center">
              <p className="text-sm font-semibold uppercase tracking-wider text-emerald-400">Product tour</p>
              <h2 className="mt-2 font-display text-3xl font-bold tracking-tight sm:text-4xl">{home.features_title}</h2>
              {home.features_subtitle && <p className="mt-4 text-slate-400">{home.features_subtitle}</p>}
            </div>

            <div className="brand-scroll -mx-4 mt-10 overflow-x-auto px-4 pb-3 pt-1 sm:mx-0 sm:px-1">
              {/* w-max + mx-auto centres the row when it fits and scrolls from the first tab when it doesn't. */}
              <div className="mx-auto flex w-max gap-3 py-1" role="tablist">
              {modules.map((m, i) => (
                <button
                  key={i}
                  type="button"
                  role="tab"
                  aria-selected={i === active}
                  onClick={() => setActive(i)}
                  className={`flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-5 py-2.5 text-sm font-semibold outline-none ring-1 transition duration-200 focus-visible:ring-2 focus-visible:ring-emerald-400 ${
                    i === active
                      ? 'bg-emerald-500 text-slate-950 ring-emerald-400 shadow-lg shadow-emerald-500/30'
                      : 'bg-slate-900 text-slate-300 ring-slate-700 hover:bg-slate-800 hover:text-white hover:ring-slate-600'
                  }`}
                >
                  <SiteIcon name={m.icon} className="h-4 w-4" /> {m.title}
                </button>
              ))}
              </div>
            </div>

            <div className="mt-10 grid items-center gap-10 lg:grid-cols-2">
              <div>
                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30">
                  <SiteIcon name={current.icon} className="h-6 w-6" />
                </span>
                <h3 className="mt-5 font-display text-2xl font-bold sm:text-3xl">{current.title}</h3>
                <p className="mt-3 text-slate-400">{current.description}</p>
                <ul className="mt-6 space-y-3">
                  {list(current.bullets).map((b, i) => (
                    <li key={i} className="flex items-start gap-2.5 text-sm text-slate-300">
                      <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" /> {b}
                    </li>
                  ))}
                </ul>
                <ButtonLink to="/features" variant="light" className="mt-8">
                  See all features <ArrowRight className="h-4 w-4" />
                </ButtonLink>
              </div>
              <div className="rounded-3xl bg-gradient-to-br from-emerald-500/20 via-slate-800/40 to-indigo-500/20 p-3 sm:p-6">
                <FeatureIllustration icon={current.icon} />
              </div>
            </div>
          </Container>
        </section>
      )}

      {/* Lead journey: one picture of the whole flow */}
      <section className="py-20 sm:py-24">
        <Container>
          <SectionHeading eyebrow="All connected" title="From first message to closed deal" subtitle="Every channel feeds the same contact list and CRM, so nothing slips through." />
          <div className="mt-14 grid items-center gap-4 md:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr]">
            {[
              { icon: SiteIcon, name: 'share', title: 'Customer reaches out', text: 'WhatsApp, Instagram, Facebook comment or lead ad' },
              { icon: SiteIcon, name: 'workflow', title: 'Bot or AI replies', text: 'Journey answers, qualifies and asks questions' },
              { icon: UserPlus, name: '', title: 'Lead lands in CRM', text: 'Tagged, scored and assigned to your team' },
              { icon: BarChart3, name: '', title: 'You follow up & win', text: 'Campaigns, reminders and analytics' },
            ].map((s, i, arr) => (
              <div key={s.title} className="contents">
                <div className="rounded-3xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-6 text-center">
                  <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
                    {s.name ? <SiteIcon name={s.name} className="h-6 w-6" /> : <s.icon className="h-6 w-6" />}
                  </span>
                  <h3 className="mt-4 font-display font-semibold text-slate-900">{s.title}</h3>
                  <p className="mt-1.5 text-sm text-slate-500">{s.text}</p>
                </div>
                {i < arr.length - 1 && <ArrowRight aria-hidden="true" className="mx-auto h-6 w-6 rotate-90 text-emerald-400 md:rotate-0" />}
              </div>
            ))}
          </div>
        </Container>
      </section>

      {/* Industries */}
      {list(home.industries).length > 0 && (
        <section className="bg-gradient-to-b from-emerald-50/60 to-white py-20 sm:py-24">
          <Container>
            <SectionHeading eyebrow="Use cases" title={home.industries_title} />
            <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {list(home.industries).map((ind, i) => {
                const Icon = INDUSTRY_ICONS[i % INDUSTRY_ICONS.length];
                return (
                  <div key={i} className="group rounded-3xl border border-slate-200 bg-white p-6 transition hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/5">
                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-900 text-white transition group-hover:bg-emerald-600">
                      <Icon className="h-5 w-5" />
                    </span>
                    <h3 className="mt-5 font-display text-lg font-semibold text-slate-900">{ind.title}</h3>
                    <p className="mt-2 text-sm leading-relaxed text-slate-600">{ind.description}</p>
                  </div>
                );
              })}
            </div>
          </Container>
        </section>
      )}

      {/* Why WhatsApp */}
      <section className="py-20 sm:py-24">
        <Container className="grid items-center gap-12 lg:grid-cols-2">
          <div>
            <SectionHeading center={false} eyebrow="Why WhatsApp" title="Your customers already read WhatsApp" subtitle="Emails sit unopened and calls go unanswered. A WhatsApp message gets seen, and a reply button makes it easy to act." />
            <div className="mt-8">
              <BulletList
                items={[
                  'Rich messages: images, videos, documents, buttons and lists',
                  'Two-way chat — customers reply, your bot or team answers',
                  'Personal: every message can carry the customer’s name and details',
                  'Measurable: delivered, read and replied for every campaign',
                ]}
              />
            </div>
          </div>
          <FeatureIllustration icon="message" />
        </Container>
      </section>

      {/* FAQ */}
      {list(faq.items).length > 0 && (
        <section className="bg-slate-50 py-20 sm:py-24">
          <Container>
            <SectionHeading eyebrow="FAQ" title={faq.title} />
            <div className="mt-12">
              <FaqList faq={faq} />
            </div>
          </Container>
        </section>
      )}

      <CtaBand cta={isAuthenticated ? undefined : home.cta} to={startTo} />
    </>
  );
}
