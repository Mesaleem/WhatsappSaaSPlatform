/**
 * The website pages' code, as loaders shared by App.tsx's lazy() routes and the prefetch below,
 * so a prefetched page's chunk is the very one the route then uses.
 */
export const loadHomePage = () => import('./pages/HomePage');
export const loadFeaturesPage = () => import('./pages/FeaturesPage');
export const loadPricingPage = () => import('./pages/PricingPage');
export const loadAboutPage = () => import('./pages/AboutPage');
export const loadContactPage = () => import('./pages/ContactPage');
export const loadLegalPage = () => import('./pages/LegalPage');
export const loadSignupPage = () => import('./pages/SignupPage');

const BY_PATH: Record<string, () => Promise<unknown>> = {
  '/': loadHomePage,
  '/home': loadHomePage,
  '/features': loadFeaturesPage,
  '/pricing': loadPricingPage,
  '/about': loadAboutPage,
  '/contact': loadContactPage,
  '/privacy': loadLegalPage,
  '/terms': loadLegalPage,
  '/data-deletion': loadLegalPage,
  '/signup': loadSignupPage,
};

/** Starts downloading the page for this path now (called before React renders). */
export function preloadWebsitePage(path: string): void {
  BY_PATH[path]?.().catch(() => undefined);
}

/** Downloads every other website page once the browser is idle, so moving between pages never waits. */
export function prefetchWebsitePages(): void {
  const run = () => {
    for (const load of new Set(Object.values(BY_PATH))) load().catch(() => undefined);
  };
  const w = window as Window & { requestIdleCallback?: (cb: () => void, opts?: { timeout: number }) => number };
  if (w.requestIdleCallback) w.requestIdleCallback(run, { timeout: 2000 });
  else window.setTimeout(run, 1200);
}
