import publicSiteService from '../services/publicSiteService';
import type { PublicPlan, SiteContent } from '../types/site';

/**
 * The public website's data — content (/public/site) and plans (/public/plans) — fetched ONCE,
 * as early as possible, and remembered.
 *
 *  - main.tsx calls prefetchSiteData() before React even renders, so on a website URL the two
 *    requests run in parallel with the page's code download instead of after it.
 *  - The last answer is kept in localStorage, so a returning visitor sees the page at once
 *    (stale-while-revalidate: the fresh copy replaces it a moment later, if it changed).
 *  - Every page reads the same promise, so moving between pages never asks again.
 */

export interface PlansData {
  data: PublicPlan[];
  tax_rate: number;
}

const SITE_KEY = 'waphub_site_v1';
const PLANS_KEY = 'waphub_plans_v1';

function readStored<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

function writeStored(key: string, value: unknown) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage full or blocked: the page still works, it just is not remembered.
  }
}

let siteCache: SiteContent | null = null;
let plansCache: PlansData | null = null;
let sitePromise: Promise<SiteContent> | null = null;
let plansPromise: Promise<PlansData> | null = null;

/** The last known content (memory, else the browser's saved copy), or null on a first visit. */
export function cachedSite(): SiteContent | null {
  return (siteCache ??= readStored<SiteContent>(SITE_KEY));
}

export function cachedPlans(): PlansData | null {
  return (plansCache ??= readStored<PlansData>(PLANS_KEY));
}

/** Fresh content; the same request for every caller until it finishes. */
export function loadSite(force = false): Promise<SiteContent> {
  if (!sitePromise || force) {
    sitePromise = publicSiteService.site().then(
      (content) => {
        siteCache = content;
        writeStored(SITE_KEY, content);
        return content;
      },
      (err: unknown) => {
        sitePromise = null; // a later call may retry
        throw err;
      },
    );
  }
  return sitePromise;
}

export function loadPlans(force = false): Promise<PlansData> {
  if (!plansPromise || force) {
    plansPromise = publicSiteService.plans().then(
      (plans) => {
        plansCache = plans;
        writeStored(PLANS_KEY, plans);
        return plans;
      },
      (err: unknown) => {
        plansPromise = null;
        throw err;
      },
    );
  }
  return plansPromise;
}

/** Starts both requests now (errors surface later, where the data is used). */
export function prefetchSiteData(): void {
  loadSite().catch(() => undefined);
  loadPlans().catch(() => undefined);
}

/** Paths that show website content (the signup screen uses it too). */
export const WEBSITE_PATHS = ['/', '/home', '/features', '/pricing', '/about', '/contact', '/privacy', '/terms', '/data-deletion', '/signup'];

/** Tests only. */
export function resetSiteDataForTests(): void {
  siteCache = null;
  plansCache = null;
  sitePromise = null;
  plansPromise = null;
}
