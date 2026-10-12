import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarDays, CheckCircle2, FileText, Mail, MessageSquare, ShieldCheck, Trash2, type LucideIcon } from 'lucide-react';
import type { SiteLegal } from '../../types/site';
import { useSiteContent } from '../SiteContentContext';
import { Container, list } from '../ui';

const PAGES: { key: keyof SiteLegal; to: string; label: string; icon: LucideIcon }[] = [
  { key: 'privacy', to: '/privacy', label: 'Privacy Policy', icon: ShieldCheck },
  { key: 'terms', to: '/terms', label: 'Terms of Service', icon: FileText },
  { key: 'data_deletion', to: '/data-deletion', label: 'Data Deletion', icon: Trash2 },
];

const sectionId = (i: number) => `section-${i + 1}`;

/** Privacy Policy, Terms of Service and Data Deletion — the public URLs a Meta app needs to go Live. */
export default function LegalPage({ page }: { page: keyof SiteLegal }) {
  const { content } = useSiteContent();
  const [active, setActive] = useState(0);

  const doc = content?.legal?.[page];
  const sections = list(doc?.sections);

  // Highlight the section being read in the "On this page" list.
  useEffect(() => {
    setActive(0);
    if (typeof IntersectionObserver === 'undefined' || sections.length === 0) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible[0]) setActive(Number(visible[0].target.getAttribute('data-index')));
      },
      { rootMargin: '-96px 0px -60% 0px' },
    );
    sections.forEach((_, i) => {
      const el = document.getElementById(sectionId(i));
      if (el) observer.observe(el);
    });
    return () => observer.disconnect();
  }, [page, sections.length]);

  if (!content) return null;

  const meta = PAGES.find((p) => p.key === page) ?? PAGES[0];
  const Icon = meta.icon;
  const email = content.brand.support_email;
  const highlights = list(doc?.highlights);

  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-emerald-50 via-white to-white">
        <div aria-hidden="true" className="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-emerald-200/40 blur-3xl" />
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 opacity-[0.35] [mask-image:linear-gradient(to_bottom,black,transparent)]"
          style={{ backgroundImage: 'radial-gradient(#a7f3d0 1px, transparent 1px)', backgroundSize: '22px 22px' }}
        />
        <Container className="relative py-14 sm:py-16">
          <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/30">
            <Icon className="h-7 w-7" />
          </span>
          <h1 className="mt-6 font-display text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">{doc?.title}</h1>
          {doc?.intro && <p className="mt-4 max-w-2xl text-lg text-slate-600">{doc.intro}</p>}
          {doc?.updated && (
            <p className="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-500">
              <CalendarDays className="h-3.5 w-3.5 text-emerald-600" /> Last updated: {doc.updated}
            </p>
          )}

          <nav className="mt-8 flex flex-wrap gap-2" aria-label="Legal pages">
            {PAGES.map((p) => {
              const current = p.key === page;
              return (
                <Link
                  key={p.key}
                  to={p.to}
                  aria-current={current ? 'page' : undefined}
                  className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition ${
                    current ? 'bg-slate-900 text-white shadow' : 'border border-slate-200 bg-white text-slate-600 hover:border-emerald-300 hover:text-emerald-700'
                  }`}
                >
                  <p.icon className="h-4 w-4" /> {p.label}
                </Link>
              );
            })}
          </nav>
        </Container>
      </section>

      <section className="bg-slate-50/60 py-12 sm:py-16">
        <Container className="grid gap-10 lg:grid-cols-[16rem_1fr]">
          {/* On this page */}
          {sections.length > 0 && (
            <aside className="hidden lg:block">
              <div className="sticky top-24 rounded-2xl border border-slate-200 bg-white p-5">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">On this page</p>
                <ol className="mt-4 space-y-1 border-l border-slate-200">
                  {sections.map((s, i) => (
                    <li key={i}>
                      <a
                        href={`#${sectionId(i)}`}
                        className={`-ml-px block border-l-2 py-1.5 pl-4 text-sm transition ${
                          active === i ? 'border-emerald-500 font-semibold text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-800'
                        }`}
                      >
                        {s.heading}
                      </a>
                    </li>
                  ))}
                </ol>
              </div>
            </aside>
          )}

          <div className="min-w-0 space-y-6">
            {highlights.length > 0 && (
              <div className="rounded-3xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-teal-50 p-6 sm:p-8">
                <p className="text-sm font-semibold uppercase tracking-wider text-emerald-700">At a glance</p>
                <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                  {highlights.map((h, i) => (
                    <li key={i} className="flex items-start gap-2.5 text-sm font-medium text-slate-800">
                      <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" /> {h}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {sections.map((s, i) => (
              <article
                key={i}
                id={sectionId(i)}
                data-index={i}
                className="scroll-mt-24 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md sm:p-8"
              >
                <div className="flex items-start gap-4">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 font-display text-sm font-bold text-emerald-700 ring-1 ring-emerald-100">
                    {String(i + 1).padStart(2, '0')}
                  </span>
                  <div className="min-w-0">
                    <h2 className="font-display text-xl font-bold text-slate-900">{s.heading}</h2>
                    <p className="mt-2 whitespace-pre-line leading-relaxed text-slate-600">{s.body}</p>
                  </div>
                </div>
              </article>
            ))}

            {/* Contact */}
            <div className="relative overflow-hidden rounded-3xl bg-slate-950 p-6 text-white sm:p-8">
              <div aria-hidden="true" className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-emerald-500/20 blur-2xl" />
              <div className="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-display text-lg font-bold">Questions about this page?</p>
                  <p className="mt-1 text-sm text-slate-400">We are happy to help — reach out and we will reply by email.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {email && (
                    <a href={`mailto:${email}`} className="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-emerald-50">
                      <Mail className="h-4 w-4 text-emerald-600" /> {email}
                    </a>
                  )}
                  <Link to="/contact" className="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400">
                    <MessageSquare className="h-4 w-4" /> Contact us
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}
