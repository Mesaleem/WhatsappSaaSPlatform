import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

/**
 * Developer API Key Management UI — this project had no test runner at
 * all before this task (no vitest/jest, no @testing-library, no `test`
 * script, zero test files), so there was no existing frontend testing
 * framework to follow. Vitest is the choice that matches what is already
 * here: it reads the same vite.config plugins/resolution this app
 * already builds with, so a component test compiles exactly the way the
 * app does, with no second bundler config to keep in sync.
 *
 * Kept as its own file rather than a `test` block inside vite.config.ts
 * so the production build config stays untouched.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
  },
});
