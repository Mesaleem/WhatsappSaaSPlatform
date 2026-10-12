import { useEffect, useState } from 'react';
import { BadgeCheck, Check, Info, Loader2, ShieldCheck, Sparkles, Zap } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import publicSiteService from '../../services/publicSiteService';
import type { PublicPlan } from '../../types/site';
import { useSiteContent } from '../SiteContentContext';
import { ButtonLink, Container, FaqList, PageHero, SectionHeading, list } from '../ui';

const ENGINE_LABELS: Record<string, string> = {
  qr: 'WhatsApp via QR scan',
  meta: 'Official Meta Cloud API',
};

export function periodLabel(days: number): string {
  if (days === 30 || days === 31) return 'month';
  if (days === 90) return '3 months';
  if (days === 180) return '6 months';
  if (days === 365 || days === 366) return 'year';
  return `${days} days`;
}

export function formatRupees(amount: string): string {
  const n = Number(amount);
  if (!Number.isFinite(n)) return amount;
  return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: n % 1 === 0 ? 0 : 2 })}`;
}

function messagesLine(plan: PublicPlan): string | null {
  if (plan.billing_model === 'unlimited') return 'Unlimited messages';
  if (plan.total_allocated_messages) return `${plan.total_allocated_messages.toLocaleString('en-IN')} messages`;
  return null;
}

/** Feature lines for a plan: the Super Admin's list from Website Content, else derived from the plan itself. */
function featuresFor(plan: PublicPlan, configured: { plan: string; features: string[] }[]): string[] {
  const custom = configured.find((p) => p.plan === plan.key);
  if (custom && list(custom.features).length > 0) return list(custom.features);
  const messages = messagesLine(plan);
  return [...(messages ? [messages] : []), ENGINE_LABELS[plan.engine_type] ?? plan.engine_type, '1 WhatsApp number included', ...plan.features];
}

function PriceBlock({ plan, taxRate, large }: { plan: PublicPlan; taxRate: number; large?: boolean }) {
  return (
    <>
      {/* The headline is what the customer pays: price including tax. */}
      <p className="flex items-baseline gap-1">
        <span className={`font-display font-extrabold tracking-tight text-slate-900 ${large ? 'text-6xl' : 'text-4xl'}`}>{formatRupees(plan.total_amount)}</span>
        <span className="text-sm text-slate-500">/ {periodLabel(plan.duration_days)}</span>
      </p>
      {taxRate > 0 && (
        <p className="mt-1 text-xs text-slate-400">
          incl. {Math.round(taxRate * 100)}% GST ({formatRupees(plan.price)} + {formatRupees(plan.tax_amount)} GST)
        </p>
      )}
    </>
  );
}

export default function PricingPage() {
  const { content } = useSiteContent();
  const { isAuthenticated } = useAuth();
  const [plans, setPlans] = useState<PublicPlan[] | null>(null);
  const [taxRate, setTaxRate] = useState(0);
  const [error, setError] = useState(false);

  useEffect(() => {
    publicSiteService
      .plans()
      .then((res) => {
        setPlans(res.data);
        setTaxRate(res.tax_rate);
      })
      .catch(() => setError(true));
  }, []);

  if (!content) return null;
  const { pricing, faq, brand } = content;
  const signupEnabled = brand.signup_enabled !== false;
  const trialDays = Number(brand.trial_days) || 0;
  const configured = list(pricing.plan_features);

  const ctaFor = (plan: PublicPlan) =>
    isAuthenticated ? '/billing' : signupEnabled ? `/signup?plan=${encodeURIComponent(plan.key)}` : '/contact';
  const ctaLabel = isAuthenticated ? 'Buy in Billing' : pricing.cta || 'Choose plan';

  return (
    <>
      <PageHero title={pricing.title} subtitle={pricing.subtitle}>
        {signupEnabled && trialDays > 0 && !isAuthenticated && (
          <p className="mx-auto mt-6 inline-flex items-center gap-2 rounded-full bg-emerald-100 px-4 py-1.5 text-sm font-semibold text-emerald-800">
            <Sparkles className="h-4 w-4" /> Start with a {trialDays}-day free trial — no card needed
          </p>
        )}
      </PageHero>

      <section className="pb-16">
        <Container>
          {error ? (
            <p className="py-16 text-center text-slate-500">Plans could not be loaded right now. Please refresh, or contact us for pricing.</p>
          ) : plans === null ? (
            <div className="flex justify-center py-20" role="status" aria-label="Loading plans">
              <Loader2 className="h-7 w-7 animate-spin text-emerald-600" />
            </div>
          ) : plans.length === 0 ? (
            <div className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center">
              <p className="font-semibold text-slate-900">Plans are being updated</p>
              <p className="mt-2 text-sm text-slate-600">Contact us and we will share the right plan for your business.</p>
              <ButtonLink to="/contact" className="mt-5">Contact sales</ButtonLink>
            </div>
          ) : plans.length === 1 ? (
            <Spotlight plan={plans[0]} features={featuresFor(plans[0], configured)} taxRate={taxRate} cta={ctaFor(plans[0])} ctaLabel={ctaLabel} />
          ) : (
            <div className={`grid gap-6 ${plans.length === 2 ? 'mx-auto max-w-4xl sm:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
              {plans.map((plan) => {
                const featured = pricing.featured_plan === plan.key;
                return (
                  <div
                    key={plan.key}
                    data-testid={`plan-${plan.key}`}
                    className={`relative flex flex-col rounded-3xl border bg-white p-7 transition hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/5 ${
                      featured ? 'border-emerald-500 shadow-xl shadow-emerald-600/10 ring-4 ring-emerald-100' : 'border-slate-200 shadow-sm hover:border-emerald-300'
                    }`}
                  >
                    {featured && (
                      <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow">Recommended</span>
                    )}
                    <h3 className="font-display text-xl font-bold text-slate-900">{plan.label}</h3>
                    {plan.description && <p className="mt-2 text-sm text-slate-600">{plan.description}</p>}
                    <div className="mt-6">
                      <PriceBlock plan={plan} taxRate={taxRate} />
                    </div>
                    <ButtonLink to={ctaFor(plan)} variant={featured ? 'primary' : 'secondary'} className="mt-6 w-full">
                      {ctaLabel}
                    </ButtonLink>
                    <ul className="mt-7 space-y-2.5 border-t border-slate-100 pt-6">
                      {featuresFor(plan, configured).map((f, i) => (
                        <li key={i} className="flex items-start gap-2.5 text-sm text-slate-600">
                          <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" strokeWidth={3} /> {f}
                        </li>
                      ))}
                    </ul>
                  </div>
                );
              })}
            </div>
          )}

          {list(pricing.notes).length > 0 && (
            <div className="mx-auto mt-10 max-w-3xl space-y-2">
              {list(pricing.notes).map((note, i) => (
                <p key={i} className="flex items-start gap-2 text-sm text-slate-500">
                  <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /> {note}
                </p>
              ))}
            </div>
          )}
        </Container>
      </section>

      {list(faq.items).length > 0 && (
        <section className="bg-slate-50 py-20">
          <Container>
            <SectionHeading eyebrow="FAQ" title={faq.title} />
            <div className="mt-12">
              <FaqList faq={faq} />
            </div>
          </Container>
        </section>
      )}
    </>
  );
}

/** One plan on its own: a large price card beside the full "What's included" grid. */
function Spotlight({ plan, features, taxRate, cta, ctaLabel }: { plan: PublicPlan; features: string[]; taxRate: number; cta: string; ctaLabel: string }) {
  return (
    <div data-testid={`plan-${plan.key}`} className="mx-auto grid max-w-6xl overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-2xl shadow-slate-900/5 lg:grid-cols-[0.9fr_1.1fr]">
      <div className="relative overflow-hidden bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-800 p-8 text-white sm:p-10">
        <div aria-hidden="true" className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl" />
        <div aria-hidden="true" className="absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-teal-300/20 blur-2xl" />
        <div className="relative">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold ring-1 ring-white/25">
            <Zap className="h-3.5 w-3.5" /> {ENGINE_LABELS[plan.engine_type] ?? plan.engine_type}
          </span>
          <h3 className="mt-5 font-display text-3xl font-bold">{plan.label}</h3>
          {plan.description && <p className="mt-2 text-emerald-50">{plan.description}</p>}

          <div className="mt-8 rounded-2xl bg-white p-6 text-slate-900 shadow-xl">
            <PriceBlock plan={plan} taxRate={taxRate} large />
            <ButtonLink to={cta} className="mt-6 w-full py-3.5 text-base">{ctaLabel}</ButtonLink>
            <p className="mt-3 flex items-center justify-center gap-1.5 text-xs text-slate-500">
              <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" /> Secure online payment · valid for {plan.duration_days} days
            </p>
          </div>

          <div className="mt-8 grid grid-cols-2 gap-3 text-sm">
            {[
              [messagesLine(plan) ?? '—', 'per ' + periodLabel(plan.duration_days)],
              ['1 number', 'included'],
            ].map(([v, k]) => (
              <div key={k} className="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                <p className="font-display text-lg font-bold">{v}</p>
                <p className="text-xs text-emerald-100">{k}</p>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="p-8 sm:p-10">
        <p className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-emerald-600">
          <BadgeCheck className="h-4 w-4" /> What's included
        </p>
        <h4 className="mt-2 font-display text-2xl font-bold text-slate-900">Everything you need to start on WhatsApp</h4>
        <ul className="mt-8 grid gap-x-6 gap-y-4 sm:grid-cols-2">
          {features.map((f, i) => (
            <li key={i} className="flex items-start gap-3 text-sm text-slate-700">
              <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <Check className="h-3 w-3" strokeWidth={3} />
              </span>
              {f}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
