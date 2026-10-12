import { useEffect } from 'react';

export const APP_NAME = 'WapHub';

/** Sets the browser tab title to "WapHub | <page>" (just "WapHub" when no page is given). */
export function usePageTitle(page?: string | null, brand: string = APP_NAME): void {
  useEffect(() => {
    document.title = page ? `${brand} | ${page}` : brand;
  }, [page, brand]);
}
