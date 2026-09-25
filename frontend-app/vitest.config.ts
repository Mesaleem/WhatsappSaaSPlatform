import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Frontend unit tests (Message Logs / View Message). Kept separate from
// vite.config.ts so the dev/build config is untouched. Tests live in
// tests/ (outside src/) so `tsc -b` for the app build never needs the
// test-only dev dependencies.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.{ts,tsx}'],
  },
});
