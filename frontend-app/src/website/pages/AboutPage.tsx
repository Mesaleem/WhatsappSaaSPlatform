import { Target } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useSiteContent } from '../SiteContentContext';
import { Container, CtaBand, PageHero, SectionHeading, list } from '../ui';

export default function AboutPage() {
  const { content } = useSiteContent();
  const { isAuthenticated } = useAuth();
  if (!content) return null;

  const { about, home, brand } = content;
  const startTo = isAuthenticated ? '/' : brand.signup_enabled !== false ? '/signup' : '/contact';

  return (
    <>
      <PageHero title={about.title} subtitle={about.subtitle} />

      <section className="pb-16">
        <Container className="grid gap-10 lg:grid-cols-5">
          <div className="lg:col-span-3">
            {about.story_title && <h2 className="font-display text-2xl font-bold text-slate-900 sm:text-3xl">{about.story_title}</h2>}
            <div className="mt-5 space-y-4 text-base leading-relaxed text-slate-600">
              {list(about.story).map((p, i) => (
                <p key={i}>{p}</p>
              ))}
            </div>
          </div>
          {about.mission && (
            <div className="lg:col-span-2">
              <div className="h-full rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 p-8 text-white shadow-xl">
                <Target className="h-8 w-8 text-emerald-200" />
                <h2 className="mt-4 font-display text-xl font-bold">{about.mission_title}</h2>
                <p className="mt-3 text-lg leading-relaxed text-emerald-50">{about.mission}</p>
              </div>
            </div>
          )}
        </Container>
      </section>

      {list(about.values).length > 0 && (
        <section className="bg-slate-50 py-20">
          <Container>
            <SectionHeading title={about.values_title} />
            <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {list(about.values).map((v, i) => (
                <div key={i} className="rounded-2xl border border-slate-200 bg-white p-6">
                  <span className="font-display text-sm font-bold text-emerald-600">0{i + 1}</span>
                  <h3 className="mt-3 font-display text-lg font-semibold text-slate-900">{v.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-600">{v.description}</p>
                </div>
              ))}
            </div>
          </Container>
        </section>
      )}

      <CtaBand cta={isAuthenticated ? undefined : home.cta} to={startTo} />
    </>
  );
}
