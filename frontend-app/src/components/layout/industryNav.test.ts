import { describe, expect, it } from 'vitest';
import { hasIndustryModule, industryModuleKey } from './industryNav';
import { ACCOUNT_MODULES, ACCOUNT_MODULE_LABELS } from '../../types/account';

describe('industry navigation gating', () => {
  it('shows an item only when its industry.module key is usable', () => {
    expect(hasIndustryModule(['education.students'], 'education.students')).toBe(true);
    expect(hasIndustryModule(['education.students'], 'education.fees')).toBe(false);
    expect(hasIndustryModule(['education.students'], 'healthcare.students')).toBe(false);
  });

  it('fails closed when the list is absent or empty', () => {
    expect(hasIndustryModule(undefined, 'education.students')).toBe(false);
    expect(hasIndustryModule(null, 'education.students')).toBe(false);
    expect(hasIndustryModule([], 'education.students')).toBe(false);
  });

  it('builds the same key shape the backend emits', () => {
    expect(industryModuleKey('real_estate', 'site_visits')).toBe('real_estate.site_visits');
  });

  it('registers the industry_modules account module with a label', () => {
    expect(ACCOUNT_MODULES).toContain('industry_modules');
    expect(ACCOUNT_MODULE_LABELS.industry_modules).toBe('Industry Modules');
  });
});
