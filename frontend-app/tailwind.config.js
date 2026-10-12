import colors from 'tailwindcss/colors';
import plugin from 'tailwindcss/plugin';

/*
 * Themeable colours (2026-10-12). The brand scale (`emerald-*` classes), `slate-*`
 * and `white` backgrounds read CSS variables, so the dashboard can switch accent
 * colour and light/dark mode at runtime without touching any component:
 *   <html data-accent="blue" data-theme="dark">  (set by core/theme/ThemeContext)
 * Without those attributes (the public website, and the default) every value is
 * Tailwind's own emerald/slate/white, so nothing changes there.
 *
 * Text, background and border/ring get separate variables because dark mode needs
 * them to move differently (e.g. `text-emerald-700` must lighten on a dark card
 * while `bg-emerald-700` buttons keep their strength).
 */

const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];

/** Accent choices offered in the dashboard. The key is the data-accent value. */
export const ACCENTS = {
  emerald: colors.emerald,
  blue: colors.blue,
  indigo: colors.indigo,
  violet: colors.violet,
  rose: colors.rose,
  orange: colors.orange,
  teal: colors.teal,
};

const DARK_SURFACE = '#0f172a';

const rgb = (hex) => {
  const h = hex.replace('#', '');
  const n = parseInt(h.length === 3 ? h.replace(/./g, (c) => c + c) : h, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
};
const triplet = (hex) => rgb(hex).join(' ');
/** `amount` of `a` mixed into `b`, as an "r g b" triplet. */
const mix = (a, b, amount) => {
  const [r1, g1, b1] = rgb(a);
  const [r2, g2, b2] = rgb(b);
  return [r1, g1, b1].map((v, i) => Math.round(v * amount + [r2, g2, b2][i] * (1 - amount))).join(' ');
};

const scale = (prefix) =>
  Object.fromEntries(SHADES.map((s) => [s, `rgb(var(--${prefix}-${s}) / <alpha-value>)`]));

/** Brand variables for one accent: identical to the palette in light mode. */
function brandVars(palette) {
  const vars = {};
  for (const s of SHADES) {
    vars[`--brand-${s}`] = triplet(palette[s]);
    vars[`--brand-text-${s}`] = triplet(palette[s]);
    vars[`--brand-line-${s}`] = triplet(palette[s]);
  }
  return vars;
}

/** Dark mode for one accent: pale tints become dark tints, text lightens, buttons keep their colour. */
function brandDarkVars(palette) {
  return {
    '--brand-50': mix(palette['500'], DARK_SURFACE, 0.12),
    '--brand-100': mix(palette['500'], DARK_SURFACE, 0.2),
    '--brand-200': mix(palette['500'], DARK_SURFACE, 0.3),
    '--brand-text-500': triplet(palette['400']),
    '--brand-text-600': triplet(palette['400']),
    '--brand-text-700': triplet(palette['300']),
    '--brand-text-800': triplet(palette['200']),
    '--brand-text-900': triplet(palette['100']),
    '--brand-text-950': triplet(palette['50']),
    '--brand-line-100': mix(palette['500'], DARK_SURFACE, 0.25),
    '--brand-line-200': mix(palette['500'], DARK_SURFACE, 0.35),
    '--brand-line-300': mix(palette['500'], DARK_SURFACE, 0.5),
  };
}

const slateLight = () => {
  const vars = {};
  for (const s of SHADES) {
    vars[`--slate-${s}`] = triplet(colors.slate[s]);
    vars[`--slate-text-${s}`] = triplet(colors.slate[s]);
    vars[`--slate-line-${s}`] = triplet(colors.slate[s]);
  }
  return vars;
};

const themePlugin = plugin(({ addBase }) => {
  const base = {
    ':root': {
      ...brandVars(ACCENTS.emerald),
      ...slateLight(),
      '--surface': '255 255 255',
      '--app-canvas': '248 250 249',
    },
    // Dark mode (dashboard only).
    'html[data-theme="dark"]': {
      colorScheme: 'dark',
      '--surface': '17 24 39', // cards: bg-white
      '--app-canvas': '11 17 32', // page behind the cards
      // backgrounds
      '--slate-50': '22 31 50',
      '--slate-100': '30 41 59',
      '--slate-200': '39 52 73',
      '--slate-300': '51 65 85',
      '--slate-400': '71 85 105',
      '--slate-500': '100 116 139',
      '--slate-600': '71 85 105',
      '--slate-700': '51 65 85',
      '--slate-800': '51 65 85', // solid dark chips keep white text readable
      '--slate-900': '30 41 59',
      '--slate-950': '2 6 23', // overlays stay dark
      // text
      '--slate-text-50': '248 250 252',
      '--slate-text-100': '241 245 249',
      '--slate-text-200': '71 85 105',
      '--slate-text-300': '100 116 139',
      '--slate-text-400': '100 116 139',
      '--slate-text-500': '148 163 184',
      '--slate-text-600': '168 180 198',
      '--slate-text-700': '203 213 225',
      '--slate-text-800': '226 232 240',
      '--slate-text-900': '241 245 249',
      '--slate-text-950': '248 250 252',
      // borders, dividers, rings
      '--slate-line-50': '30 41 59',
      '--slate-line-100': '30 41 59',
      '--slate-line-200': '39 52 73',
      '--slate-line-300': '51 65 85',
      '--slate-line-400': '71 85 105',
    },
  };

  for (const [name, palette] of Object.entries(ACCENTS)) {
    base[`html[data-accent="${name}"]`] = brandVars(palette);
    base[`html[data-accent="${name}"][data-theme="dark"]`] = brandDarkVars(palette);
  }
  // Dark mode with the default accent (no data-accent attribute).
  base['html[data-theme="dark"]:not([data-accent])'] = brandDarkVars(ACCENTS.emerald);

  addBase(base);
});

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Inter"', 'system-ui', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', '"Inter"', 'system-ui', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
      colors: {
        emerald: scale('brand'),
        slate: scale('slate'),
      },
      textColor: {
        emerald: scale('brand-text'),
        slate: scale('slate-text'),
      },
      borderColor: {
        emerald: scale('brand-line'),
        slate: scale('slate-line'),
      },
      ringColor: {
        emerald: scale('brand-line'),
        slate: scale('slate-line'),
      },
      divideColor: {
        slate: scale('slate-line'),
      },
      backgroundColor: {
        white: 'rgb(var(--surface) / <alpha-value>)',
      },
    },
  },
  plugins: [themePlugin],
};
