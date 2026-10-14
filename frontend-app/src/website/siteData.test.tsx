import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import publicSiteService from '../services/publicSiteService';
import { SiteContentProvider, useSiteContent } from './SiteContentContext';
import { cachedSite, loadPlans, loadSite, prefetchSiteData, resetSiteDataForTests } from './siteData';
import type { SiteContent } from '../types/site';

/** Website data: fetched once at start-up, shared by every page, shown at once on a return visit. */

vi.mock('../services/publicSiteService', () => ({ default: { site: vi.fn(), plans: vi.fn() } }));
const svc = publicSiteService as unknown as Record<string, Mock>;

const content = (name: string) => ({ brand: { name } }) as unknown as SiteContent;

function Brand() {
  const { content: c } = useSiteContent();
  return <p>{c ? `brand:${c.brand.name}` : 'no content yet'}</p>;
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  resetSiteDataForTests();
  svc.site.mockResolvedValue(content('Fresh'));
  svc.plans.mockResolvedValue({ data: [], tax_rate: 0 });
});

describe('website data', () => {
  it('asks the server once, however many pages want it', async () => {
    prefetchSiteData();
    await Promise.all([loadSite(), loadSite(), loadPlans(), loadPlans()]);
    expect(svc.site).toHaveBeenCalledTimes(1);
    expect(svc.plans).toHaveBeenCalledTimes(1);
    expect(cachedSite()?.brand.name).toBe('Fresh');
  });

  it('shows the saved copy at once on a return visit, then the fresh one', async () => {
    localStorage.setItem('waphub_site_v1', JSON.stringify(content('Saved')));
    let release: (c: SiteContent) => void = () => undefined;
    svc.site.mockReturnValue(new Promise((r) => (release = r)));

    render(
      <SiteContentProvider>
        <Brand />
      </SiteContentProvider>,
    );
    // No loader: the saved content is on screen before the server answers.
    expect(screen.getByText('brand:Saved')).toBeInTheDocument();

    release(content('Fresh'));
    expect(await screen.findByText('brand:Fresh')).toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem('waphub_site_v1')!).brand.name).toBe('Fresh');
  });

  it('a failed first load can be retried', async () => {
    svc.site.mockRejectedValueOnce(new Error('offline'));
    await expect(loadSite()).rejects.toThrow('offline');
    await expect(loadSite()).resolves.toMatchObject({ brand: { name: 'Fresh' } });
  });
});
