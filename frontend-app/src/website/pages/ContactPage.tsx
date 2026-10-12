import { useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import { ArrowRight, Building2, CheckCircle2, Clock, Loader2, Lock, Mail, MapPin, MessageCircle, Phone, Send, User } from 'lucide-react';
import publicSiteService from '../../services/publicSiteService';
import type { ContactPayload } from '../../types/site';
import { useSiteContent } from '../SiteContentContext';
import { Container, list } from '../ui';

const EMPTY: ContactPayload = { name: '', email: '', phone: '', company: '', topic: '', message: '', website: '' };
const MAX_MESSAGE = 5000;

const inputClass =
  'w-full rounded-xl border bg-white py-3 pl-11 pr-3.5 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';

export default function ContactPage() {
  const { content } = useSiteContent();
  const [form, setForm] = useState<ContactPayload>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);

  if (!content) return null;
  const { contact, brand } = content;
  const topics = list(contact.topics);
  const phoneDigits = (brand.support_phone ?? '').replace(/\D/g, '');

  const set = (field: keyof ContactPayload) => (e: { target: { value: string } }) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const next: Record<string, string> = {};
    if (!form.name.trim()) next.name = 'Please enter your name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) next.email = 'Please enter a valid email.';
    if (!form.message.trim()) next.message = 'Please write a message.';
    setErrors(next);
    setFormError(null);
    if (Object.keys(next).length) return;

    setSending(true);
    try {
      await publicSiteService.contact({ ...form, name: form.name.trim(), email: form.email.trim() });
      setSent(true);
      setForm(EMPTY);
    } catch (err) {
      const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      if (e.response?.status === 429) {
        setFormError('Too many messages from your network. Please try again in a few minutes.');
      } else if (e.response?.data?.errors) {
        setErrors(Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]])));
      } else {
        setFormError(e.response?.data?.message ?? 'Your message could not be sent. Please try again.');
      }
    } finally {
      setSending(false);
    }
  };

  const border = (f: string) => (errors[f] ? 'border-red-300' : 'border-slate-300');

  const methods = [
    brand.support_email && { icon: Mail, title: 'Email us', text: brand.support_email, href: `mailto:${brand.support_email}`, cta: 'Send an email' },
    phoneDigits && { icon: MessageCircle, title: 'WhatsApp / Call', text: brand.support_phone, href: `https://wa.me/${phoneDigits}`, cta: 'Chat on WhatsApp' },
    brand.address && { icon: MapPin, title: 'Visit us', text: brand.address, href: '#map', cta: 'See on map' },
  ].filter(Boolean) as { icon: typeof Mail; title: string; text: string; href: string; cta: string }[];

  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden bg-slate-950 text-white">
        <div aria-hidden="true" className="absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(16,185,129,0.35),transparent_40%),radial-gradient(circle_at_85%_60%,rgba(99,102,241,0.25),transparent_40%)]" />
        <div
          aria-hidden="true"
          className="absolute inset-0 opacity-[0.06]"
          style={{ backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)', backgroundSize: '40px 40px' }}
        />
        <Container className="relative pb-28 pt-16 text-center sm:pt-20">
          {contact.response_time && (
            <p className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-emerald-300 ring-1 ring-white/15">
              <Clock className="h-3.5 w-3.5" /> {contact.response_time}
            </p>
          )}
          <h1 className="mt-5 font-display text-4xl font-extrabold tracking-tight sm:text-5xl">{contact.title}</h1>
          {contact.subtitle && <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-300">{contact.subtitle}</p>}
        </Container>
      </section>

      {/* Contact methods */}
      {methods.length > 0 && (
        <section className="relative z-10 -mt-16">
          <Container className={`grid gap-4 ${methods.length === 3 ? 'md:grid-cols-3' : methods.length === 2 ? 'md:grid-cols-2' : 'mx-auto max-w-md'}`}>
            {methods.map((m) => (
              <a
                key={m.title}
                href={m.href}
                target={m.href.startsWith('http') ? '_blank' : undefined}
                rel={m.href.startsWith('http') ? 'noopener noreferrer' : undefined}
                className="group rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-900/5 transition hover:-translate-y-1 hover:border-emerald-200"
              >
                <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md shadow-emerald-500/30">
                  <m.icon className="h-6 w-6" />
                </span>
                <p className="mt-4 font-display text-lg font-bold text-slate-900">{m.title}</p>
                <p className="mt-1 whitespace-pre-line break-words text-sm text-slate-600">{m.text}</p>
                <p className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-emerald-700">
                  {m.cta} <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />
                </p>
              </a>
            ))}
          </Container>
        </section>
      )}

      {/* Form + side panel */}
      <section className="py-16 sm:py-20">
        <Container className="grid gap-8 lg:grid-cols-[1fr_22rem]">
          <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            {sent ? (
              <div className="flex flex-col items-center py-14 text-center" role="status">
                <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
                  <CheckCircle2 className="h-9 w-9" />
                </span>
                <p className="mt-5 max-w-md font-display text-2xl font-bold text-slate-900">{contact.success}</p>
                <button type="button" onClick={() => setSent(false)} className="mt-6 text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                  Send another message
                </button>
              </div>
            ) : (
              <>
                <h2 className="font-display text-2xl font-bold text-slate-900">Send us a message</h2>
                <p className="mt-1 text-sm text-slate-500">Fill in the form and our team will get back to you.</p>

                <form onSubmit={submit} noValidate className="mt-8 grid gap-5 sm:grid-cols-2">
                  <Field label="Your name" error={errors.name} icon={<User />} required>
                    <input className={`${inputClass} ${border('name')}`} value={form.name} onChange={set('name')} autoComplete="name" aria-label="Your name" placeholder="Full name" />
                  </Field>
                  <Field label="Email" error={errors.email} icon={<Mail />} required>
                    <input className={`${inputClass} ${border('email')}`} type="email" value={form.email} onChange={set('email')} autoComplete="email" aria-label="Email" placeholder="you@company.com" />
                  </Field>
                  <Field label="Phone" error={errors.phone} icon={<Phone />}>
                    <input className={`${inputClass} ${border('phone')}`} type="tel" value={form.phone} onChange={set('phone')} autoComplete="tel" aria-label="Phone" placeholder="+91 98xxx xxxxx" />
                  </Field>
                  <Field label="Company" error={errors.company} icon={<Building2 />}>
                    <input className={`${inputClass} ${border('company')}`} value={form.company} onChange={set('company')} autoComplete="organization" aria-label="Company" placeholder="Business name" />
                  </Field>

                  {topics.length > 0 && (
                    <fieldset className="sm:col-span-2">
                      <legend className="text-sm font-medium text-slate-700">What can we help with?</legend>
                      <div className="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Topic">
                        {topics.map((t) => {
                          const on = form.topic === t;
                          return (
                            <button
                              key={t}
                              type="button"
                              role="radio"
                              aria-checked={on}
                              onClick={() => setForm((f) => ({ ...f, topic: on ? '' : t }))}
                              className={`rounded-full px-4 py-2 text-sm font-semibold transition ${
                                on ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/25' : 'border border-slate-300 bg-white text-slate-700 hover:border-emerald-400 hover:text-emerald-700'
                              }`}
                            >
                              {t}
                            </button>
                          );
                        })}
                      </div>
                    </fieldset>
                  )}

                  <label className="block text-sm font-medium text-slate-700 sm:col-span-2">
                    <span>
                      Message <span className="text-red-500">*</span>
                    </span>
                    <textarea
                      className={`mt-1.5 min-h-[160px] w-full rounded-xl border bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 ${border('message')}`}
                      value={form.message}
                      onChange={set('message')}
                      maxLength={MAX_MESSAGE}
                      aria-label="Message"
                      placeholder="Tell us about your business and what you are looking for…"
                    />
                    <span className="mt-1 flex justify-between text-xs">
                      <span className="text-red-600">{errors.message}</span>
                      <span className="text-slate-400">{form.message.length}/{MAX_MESSAGE}</span>
                    </span>
                  </label>

                  {/* Honeypot for bots — hidden from people and screen readers. */}
                  <input type="text" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} className="hidden" aria-hidden="true" name="website" />

                  {formError && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 sm:col-span-2" role="alert">{formError}</p>}

                  <div className="flex flex-col-reverse gap-4 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                    <p className="flex items-center gap-1.5 text-xs text-slate-500">
                      <Lock className="h-3.5 w-3.5" /> We only use your details to reply. See our{' '}
                      <Link to="/privacy" className="font-semibold text-emerald-700 hover:underline">Privacy Policy</Link>.
                    </p>
                    <button
                      type="submit"
                      disabled={sending}
                      className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-7 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:opacity-70"
                    >
                      {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                      {sending ? 'Sending…' : 'Send message'}
                    </button>
                  </div>
                </form>
              </>
            )}
          </div>

          <aside className="space-y-4">
            {contact.side_title && (
            <div className="rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 p-6 text-white shadow-xl">
              <p className="font-display text-lg font-bold">{contact.side_title}</p>
              {contact.side_text && <p className="mt-2 text-sm text-emerald-50">{contact.side_text}</p>}
              <ul className="mt-5 space-y-2.5 text-sm">
                {list(contact.side_points).map((p) => (
                  <li key={p} className="flex items-center gap-2"><CheckCircle2 className="h-4 w-4 text-emerald-200" /> {p}</li>
                ))}
              </ul>
            </div>
            )}
            {brand.business_hours && (
              <div className="flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><Clock className="h-5 w-5" /></span>
                <div className="text-sm">
                  <p className="font-semibold text-slate-900">Business hours</p>
                  <p className="mt-0.5 text-slate-600">{brand.business_hours}</p>
                </div>
              </div>
            )}
            <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm">
              <p className="font-semibold text-slate-900">Looking for something else?</p>
              <div className="mt-3 space-y-2">
                <Link to="/pricing" className="flex items-center justify-between rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-50 hover:text-emerald-700">See plans & pricing <ArrowRight className="h-4 w-4" /></Link>
                <Link to="/features" className="flex items-center justify-between rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-50 hover:text-emerald-700">Explore features <ArrowRight className="h-4 w-4" /></Link>
                <Link to="/data-deletion" className="flex items-center justify-between rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-50 hover:text-emerald-700">Delete my data <ArrowRight className="h-4 w-4" /></Link>
              </div>
            </div>
          </aside>
        </Container>
      </section>

      {/* Map */}
      <section id="map" className="scroll-mt-20 pb-20">
        <Container>
          <div className="relative overflow-hidden rounded-3xl border border-slate-200 bg-slate-100 shadow-xl shadow-slate-900/5">
            {brand.address ? (
              <iframe
                title="Office location map"
                src={`https://www.google.com/maps?q=${encodeURIComponent(brand.address)}&output=embed`}
                className="h-[26rem] w-full border-0 grayscale-[20%]"
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
              />
            ) : (
              <MapPlaceholder />
            )}
            <div className="absolute bottom-4 left-4 right-4 max-w-sm rounded-2xl bg-white/95 p-5 shadow-xl backdrop-blur sm:bottom-6 sm:left-6 sm:right-auto">
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white"><MapPin className="h-5 w-5" /></span>
                <div className="min-w-0 text-sm">
                  <p className="font-display font-bold text-slate-900">{brand.name || 'Our office'}</p>
                  <p className="mt-0.5 whitespace-pre-line text-slate-600">{brand.address || 'We work with businesses across India — online.'}</p>
                  {brand.address && (
                    <a
                      href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(brand.address)}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-2 inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-800"
                    >
                      Get directions <ArrowRight className="h-4 w-4" />
                    </a>
                  )}
                </div>
              </div>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}

/** Drawn stand-in for the map until an address is set in Website Content. */
function MapPlaceholder() {
  return (
    <div aria-hidden="true" className="relative h-[26rem] w-full overflow-hidden bg-[#e8f3ee]">
      <div className="absolute inset-0" style={{ backgroundImage: 'linear-gradient(#d3e7dd 2px, transparent 2px), linear-gradient(90deg, #d3e7dd 2px, transparent 2px)', backgroundSize: '64px 64px' }} />
      <div className="absolute left-0 right-0 top-1/3 h-5 -rotate-6 bg-white/90 shadow-sm" />
      <div className="absolute bottom-0 left-1/2 top-0 w-5 rotate-12 bg-white/90 shadow-sm" />
      <div className="absolute left-[12%] top-[58%] h-24 w-40 rounded-3xl bg-emerald-200/70" />
      <div className="absolute right-[10%] top-[12%] h-28 w-48 rounded-[2rem] bg-sky-200/70" />
      <div className="absolute left-1/2 top-[45%] -translate-x-1/2 -translate-y-full">
        <span className="absolute left-1/2 top-full h-3 w-8 -translate-x-1/2 rounded-full bg-slate-900/20 blur-sm" />
        <span className="relative flex h-12 w-12 items-center justify-center rounded-full rounded-bl-none bg-emerald-600 text-white shadow-xl -rotate-45">
          <MapPin className="h-6 w-6 rotate-45" />
        </span>
      </div>
    </div>
  );
}

function Field({ label, error, required, icon, children }: { label: string; error?: string; required?: boolean; icon: ReactNode; children: ReactNode }) {
  return (
    <label className="block text-sm font-medium text-slate-700">
      <span>
        {label} {required && <span className="text-red-500">*</span>}
      </span>
      <span className="relative mt-1.5 block">
        <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 [&>svg]:h-4 [&>svg]:w-4">{icon}</span>
        {children}
      </span>
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}
