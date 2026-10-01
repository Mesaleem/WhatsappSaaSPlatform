/**
 * Phase 11 Task 1 — industry navigation gating (UX only; IndustryAuthorizer / `industry.guard` on the
 * backend is the boundary). `/auth/me` returns `industry_modules`: the "industry.module" keys this user
 * may use right now (industry assigned + capability + module + subscription + permission + shipped).
 * A nav item with `requiresIndustryModule: 'education.students'` is visible only when that key is present.
 * Fail-closed: an absent list (older /auth/me) shows nothing, unlike capabilities, because no industry
 * route exists to fall back to.
 */
export function hasIndustryModule(keys: readonly string[] | undefined | null, key: string): boolean {
  return Array.isArray(keys) && keys.includes(key);
}

export function industryModuleKey(industry: string, module: string): string {
  return `${industry}.${module}`;
}
