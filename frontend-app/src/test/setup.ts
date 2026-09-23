import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

/**
 * Shared test setup: jest-dom matchers, DOM cleanup between tests, and a
 * guard that fails a test if production code ever writes a credential to
 * the console (see the credential-leakage assertions in
 * DeveloperPage.test.tsx).
 */
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  localStorage.clear();
  sessionStorage.clear();
});
