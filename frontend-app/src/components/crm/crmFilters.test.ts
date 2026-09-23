import { describe, expect, it } from 'vitest';
import { buildCrmUrl, hasActiveFilters, parseCrmUrl } from './crmFilters';

/**
 * Phase 6 — CRM Task 8. URL <-> filter state. The URL is shape-checked so
 * an invented value never reaches the API; the backend still validates.
 */
describe('crm URL filter state', () => {
  it('round-trips every supported filter', () => {
    const state = parseCrmUrl(new URLSearchParams('q=ada&status=contacted&source=meta_ad&assignee=7&tags=3,5&page=2&per_page=50'));

    expect(state).toEqual({
      filters: { search: 'ada', status: 'contacted', source: 'meta_ad', assigned_user_id: '7', tag_ids: [3, 5] },
      page: 2,
      perPage: 50,
    });
    expect(buildCrmUrl(state).toString()).toBe('q=ada&status=contacted&source=meta_ad&assignee=7&tags=3%2C5&page=2&per_page=50');
  });

  it('accepts "none" as the unassigned filter', () => {
    expect(parseCrmUrl(new URLSearchParams('assignee=none')).filters).toEqual({ assigned_user_id: 'none' });
  });

  it('drops values the backend does not define', () => {
    const state = parseCrmUrl(new URLSearchParams('status=won&source=tv&assignee=abc&tags=x,-1,0&page=-2&per_page=1000'));

    expect(state).toEqual({ filters: {}, page: 1, perPage: 20 });
  });

  it('de-duplicates and caps tag ids at the backend maximum', () => {
    const tags = Array.from({ length: 15 }, (_, i) => i + 1).concat([1, 2]).join(',');
    const state = parseCrmUrl(new URLSearchParams(`tags=${tags}`));

    expect(state.filters.tag_ids).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  });

  it('omits defaults from the URL', () => {
    expect(buildCrmUrl({ filters: {}, page: 1, perPage: 20 }).toString()).toBe('');
  });

  it('knows when any filter is active', () => {
    expect(hasActiveFilters({})).toBe(false);
    expect(hasActiveFilters({ search: '  ' })).toBe(false);
    expect(hasActiveFilters({ tag_ids: [1] })).toBe(true);
    expect(hasActiveFilters({ assigned_user_id: 'none' })).toBe(true);
  });
});
