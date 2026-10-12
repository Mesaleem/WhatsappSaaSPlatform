import { useAuth } from '../../core/context/AuthContext';
import { useSiteContent } from '../SiteContentContext';
import { FeatureIllustration } from '../illustrations';
import { BulletList, Container, CtaBand, PageHero, SiteIcon, list } from '../ui';

export default function FeaturesPage() {
  const { content } = useSiteContent();
  const { isAuthenticated } = useAuth();
  if (!content) return null;

  const { features, home, brand } = content;
  const startTo = isAuthenticated ? '/' : brand.signup_enabled !== false ? '/signup' : '/contact';
  const modules = list(features.modules);

  return (
    <>
      <PageHero title={features.title} subtitle={features.subtitle}>
        <div className="mx-auto mt-8 flex max-w-4xl flex-wrap justify-center gap-2">
          {modules.map((m, i) => (
            <a
              key={i}
              href={`#feature-${i}`}
              className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-emerald-300 hover:text-emerald-700"
            >
              <SiteIcon name={m.icon} className="h-4 w-4 text-emerald-600" /> {m.title}
            </a>
          ))}
        </div>
      </PageHero>

      {modules.map((m, i) => {
        const flip = i % 2 === 1;
        return (
          <section key={i} id={`feature-${i}`} className={`scroll-mt-20 py-16 sm:py-20 ${flip ? 'bg-slate-50' : 'bg-white'}`}>
            <Container className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
              <div className={flip ? 'lg:order-2' : ''}>
                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/25">
                  <SiteIcon name={m.icon} className="h-6 w-6" />
                </span>
                <h2 className="mt-5 font-display text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{m.title}</h2>
                <p className="mt-3 text-lg leading-relaxed text-slate-600">{m.description}</p>
                <div className="mt-7">
                  <BulletList items={list(m.bullets)} />
                </div>
              </div>
              <div className={`relative ${flip ? 'lg:order-1' : ''}`}>
                <div aria-hidden="true" className="absolute -inset-4 rounded-[2rem] bg-gradient-to-tr from-emerald-100 via-teal-50 to-indigo-100 blur-xl" />
                <div className="relative">
                  <FeatureIllustration icon={m.icon} />
                </div>
              </div>
            </Container>
          </section>
        );
      })}

      <CtaBand cta={isAuthenticated ? undefined : home.cta} to={startTo} />
    </>
  );
}
