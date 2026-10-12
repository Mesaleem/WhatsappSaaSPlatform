import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { Loader2, Mail, MapPin, Menu, Phone, RefreshCw, X } from 'lucide-react';
import { useAuth } from '../core/context/AuthContext';
import LogoMark from '../components/common/LogoMark';
import { SiteContentProvider, useSiteContent } from './SiteContentContext';
import { ButtonLink, Container, list } from './ui';
import { usePageTitle } from '../utils/usePageTitle';

/** Tab title per website page: "WapHub | Home", "WapHub | Contact", ... */
const PAGE_TITLES: Record<string, string> = {
  '/home': 'Home',
  '/features': 'Features',
  '/pricing': 'Pricing',
  '/about': 'About',
  '/contact': 'Contact',
  '/privacy': 'Privacy Policy',
  '/terms': 'Terms of Service',
  '/data-deletion': 'Data Deletion',
};

const NAV = [
  { label: 'Home', to: '/home' },
  { label: 'Features', to: '/features' },
  { label: 'Pricing', to: '/pricing' },
  { label: 'About', to: '/about' },
  { label: 'Contact', to: '/contact' },
];

/** Public marketing website shell: navbar, footer, and the shared site content. */
export default function WebsiteLayout() {
  return (
    <SiteContentProvider>
      <WebsiteShell />
    </SiteContentProvider>
  );
}

function WebsiteShell() {
  const { content, error, reload } = useSiteContent();
  const { isAuthenticated } = useAuth();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  const brand = content?.brand ?? {};
  const brandName = brand.name || 'WapHub';
  const signupEnabled = brand.signup_enabled !== false;

  useEffect(() => {
    setMenuOpen(false);
    window.scrollTo({ top: 0 });
  }, [location.pathname]);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  usePageTitle(PAGE_TITLES[location.pathname], brandName);

  const navClass = ({ isActive }: { isActive: boolean }) =>
    `rounded-lg px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-emerald-500 ${isActive ? 'text-emerald-700' : 'text-slate-600 hover:text-slate-900'}`;

  return (
    <div className="flex min-h-screen flex-col overflow-x-clip bg-white font-sans text-slate-900">
      <header
        className={`sticky top-0 z-40 border-b transition ${
          scrolled || menuOpen ? 'border-slate-200 bg-white/90 shadow-sm backdrop-blur-md' : 'border-transparent bg-white/70 backdrop-blur'
        }`}
      >
        <Container className="flex h-16 items-center justify-between gap-4">
          <Link to="/home" className="flex min-w-0 items-center gap-2">
            <LogoMark className="h-9 w-9 shrink-0 drop-shadow-md" />
            <span className="truncate font-display text-lg font-bold tracking-tight">{brandName}</span>
          </Link>

          <nav className="hidden items-center gap-1 lg:flex" aria-label="Main">
            {NAV.map((item) => (
              <NavLink key={item.to} to={item.to} className={navClass}>
                {item.label}
              </NavLink>
            ))}
          </nav>

          <div className="hidden items-center gap-2 lg:flex">
            {isAuthenticated ? (
              <ButtonLink to="/">Go to dashboard</ButtonLink>
            ) : (
              <>
                <Link to="/login" className="rounded-lg px-4 py-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
                  Log in
                </Link>
                {signupEnabled && <ButtonLink to="/signup">Get started</ButtonLink>}
              </>
            )}
          </div>

          <button
            type="button"
            className="rounded-lg p-2 text-slate-700 hover:bg-slate-100 lg:hidden"
            onClick={() => setMenuOpen((v) => !v)}
            aria-expanded={menuOpen}
            aria-label={menuOpen ? 'Close menu' : 'Open menu'}
          >
            {menuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </button>
        </Container>

        {menuOpen && (
          <div className="border-t border-slate-200 bg-white lg:hidden">
            <Container className="flex flex-col gap-1 py-4">
              {NAV.map((item) => (
                <NavLink key={item.to} to={item.to} className={navClass}>
                  {item.label}
                </NavLink>
              ))}
              <div className="mt-3 grid gap-2 border-t border-slate-100 pt-4">
                {isAuthenticated ? (
                  <ButtonLink to="/">Go to dashboard</ButtonLink>
                ) : (
                  <>
                    <ButtonLink to="/login" variant="secondary">Log in</ButtonLink>
                    {signupEnabled && <ButtonLink to="/signup">Get started</ButtonLink>}
                  </>
                )}
              </div>
            </Container>
          </div>
        )}
      </header>

      <main className="flex-1">
        {content ? (
          <Outlet />
        ) : error ? (
          <Container className="flex flex-col items-center py-32 text-center">
            <p className="text-lg font-semibold">We couldn't load this page.</p>
            <p className="mt-1 text-sm text-slate-500">Please check your connection and try again.</p>
            <button
              type="button"
              onClick={reload}
              className="mt-6 inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50"
            >
              <RefreshCw className="h-4 w-4" /> Try again
            </button>
          </Container>
        ) : (
          <div className="flex justify-center py-40" role="status" aria-label="Loading">
            <Loader2 className="h-8 w-8 animate-spin text-emerald-600" />
          </div>
        )}
      </main>

      <footer className="border-t border-slate-200 bg-slate-950 text-slate-300">
        <Container className="grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-5">
          <div className="lg:col-span-2">
            <div className="flex items-center gap-2">
              <LogoMark className="h-9 w-9" />
              <span className="font-display text-lg font-bold text-white">{brandName}</span>
            </div>
            {brand.description && <p className="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">{brand.description}</p>}
            <div className="mt-5 flex flex-wrap gap-2">
              {list(brand.social_links)
                .filter((l) => typeof l.url === 'string' && /^https?:\/\//i.test(l.url))
                .map((l) => (
                  <a
                    key={l.label}
                    href={l.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="rounded-lg border border-slate-800 px-3 py-1.5 text-xs font-medium text-slate-300 hover:border-emerald-500 hover:text-white"
                  >
                    {l.label}
                  </a>
                ))}
            </div>
          </div>

          <FooterColumn
            title="Product"
            links={[
              { label: 'Features', to: '/features' },
              { label: 'Pricing', to: '/pricing' },
              ...(isAuthenticated
                ? [{ label: 'Dashboard', to: '/' }]
                : [...(signupEnabled ? [{ label: 'Sign up', to: '/signup' }] : []), { label: 'Log in', to: '/login' }]),
            ]}
          />
          <FooterColumn
            title="Company"
            links={[
              { label: 'About', to: '/about' },
              { label: 'Contact', to: '/contact' },
              { label: 'Privacy Policy', to: '/privacy' },
              { label: 'Terms of Service', to: '/terms' },
              { label: 'Data Deletion', to: '/data-deletion' },
            ]}
          />

          <div>
            <h3 className="text-sm font-semibold text-white">Get in touch</h3>
            <ul className="mt-4 space-y-3 text-sm">
              {brand.support_email && (
                <li className="flex items-start gap-2">
                  <Mail className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                  <a href={`mailto:${brand.support_email}`} className="break-all hover:text-white">{brand.support_email}</a>
                </li>
              )}
              {brand.support_phone && (
                <li className="flex items-start gap-2">
                  <Phone className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                  <a href={`tel:${brand.support_phone.replace(/\s+/g, '')}`} className="hover:text-white">{brand.support_phone}</a>
                </li>
              )}
              {brand.address && (
                <li className="flex items-start gap-2">
                  <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                  <span className="whitespace-pre-line">{brand.address}</span>
                </li>
              )}
              <li>
                <Link to="/contact" className="font-semibold text-emerald-400 hover:text-emerald-300">Send us a message →</Link>
              </li>
            </ul>
          </div>
        </Container>
        <div className="border-t border-slate-800">
          <Container className="flex flex-col gap-2 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>© {new Date().getFullYear()} {brandName}. All rights reserved.</p>
            {brand.tagline && <p>{brand.tagline}</p>}
          </Container>
        </div>
      </footer>
    </div>
  );
}

function FooterColumn({ title, links }: { title: string; links: { label: string; to: string }[] }) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-white">{title}</h3>
      <ul className="mt-4 space-y-3 text-sm">
        {links.map((l) => (
          <li key={l.to}>
            <Link to={l.to} className="hover:text-white">{l.label}</Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
