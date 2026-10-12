import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import publicSiteService from '../services/publicSiteService';
import type { SiteContent } from '../types/site';

interface SiteContentValue {
  content: SiteContent | null;
  error: boolean;
  reload: () => void;
}

const SiteContentContext = createContext<SiteContentValue | undefined>(undefined);

/** Loads GET /api/public/site once for every website page. */
export function SiteContentProvider({ children }: { children: ReactNode }) {
  const [content, setContent] = useState<SiteContent | null>(null);
  const [error, setError] = useState(false);

  const load = useCallback(() => {
    setError(false);
    publicSiteService
      .site()
      .then(setContent)
      .catch(() => setError(true));
  }, []);

  useEffect(load, [load]);

  return <SiteContentContext.Provider value={{ content, error, reload: load }}>{children}</SiteContentContext.Provider>;
}

export function useSiteContent(): SiteContentValue {
  const ctx = useContext(SiteContentContext);
  if (!ctx) throw new Error('useSiteContent must be used within a SiteContentProvider');
  return ctx;
}
