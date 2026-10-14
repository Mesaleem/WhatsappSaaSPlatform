import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import type { SiteContent } from '../types/site';
import { cachedSite, loadSite } from './siteData';

interface SiteContentValue {
  content: SiteContent | null;
  error: boolean;
  reload: () => void;
}

const SiteContentContext = createContext<SiteContentValue | undefined>(undefined);

/**
 * GET /api/public/site for every website page — usually already on its way (main.tsx prefetches
 * it), and shown at once from the browser's saved copy for a returning visitor, then refreshed.
 */
export function SiteContentProvider({ children }: { children: ReactNode }) {
  const [content, setContent] = useState<SiteContent | null>(() => cachedSite());
  const [error, setError] = useState(false);

  const load = useCallback((force = false) => {
    setError(false);
    loadSite(force)
      .then(setContent)
      // With a saved copy on screen, a failed refresh is not worth an error page.
      .catch(() => setError(cachedSite() === null));
  }, []);

  useEffect(() => load(), [load]);

  return <SiteContentContext.Provider value={{ content, error, reload: () => load(true) }}>{children}</SiteContentContext.Provider>;
}

export function useSiteContent(): SiteContentValue {
  const ctx = useContext(SiteContentContext);
  if (!ctx) throw new Error('useSiteContent must be used within a SiteContentProvider');
  return ctx;
}
