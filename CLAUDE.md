# Project notes for AI coding assistants

## Start with PROJECT_STATE.md

`PROJECT_STATE.md` (repo root) is the single, continuously-updated description of
this project: architecture, capability/plan model, API surface, test-suite status,
and — most importantly — **which phases are done and which are pending**. It is
updated at the end of every development task.

Read it before answering anything about project status or planning new work. This
file (`CLAUDE.md`) covers coding conventions only. The dated files under
`Claude outputs/` are historical snapshots; `PROJECT_STATE.md` supersedes them
wherever they disagree.

**If you complete a development task, update `PROJECT_STATE.md` before reporting
done** — its section 10 lists exactly which parts to touch.

## Page layout convention — keep new pages visually consistent

This app has TWO competing page-layout patterns in the codebase today.
When creating a NEW page, or editing an existing one, use pattern **A**
— it is the current, dominant convention. Pattern B is legacy and
should not be copied into new work.

**Pattern A (use this one) — plain wrapper, no duplicate header:**

```tsx
return (
  <div className="p-6">
    <div className="w-full space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Page Title</h1>
        <p className="mt-1 text-sm text-slate-500">One-line description of the page.</p>
      </div>
      {/* page content, full width */}
    </div>
  </div>
);
```

Examples already following this pattern: `AnalyticsPage.tsx`,
`DashboardPage.tsx`, `JourneyBuilderPage.tsx` (WhatsApp Journey
Builder), `ChatbotPage.tsx`.

Why: the persistent top app bar (`Header.tsx`, via `AppLayout.tsx`'s
`resolvePageTitle`) already shows the current page's title on every
route. A page does not need to draw its own second title-with-icon or
a "← Back to dashboard" link — the sidebar and top bar already provide
navigation. Content should span the full available width (`w-full`),
not be squeezed into a centered, capped-width column, since most pages
here are data tables/dashboards that benefit from the extra width.

**Pattern B (legacy — do not use for new pages):** `PageShell` +
`PageHeader` (`frontend-app/src/components/common/PageShell.tsx`).
This wraps content in a centered `mx-auto max-w-*` column and renders
its own icon + title + "← Back to dashboard" link — both of which
duplicate what the top app bar already shows, and the capped width
makes a table look narrower than sibling pages. Still used by a
handful of older pages (`AuditLogsPage.tsx`, `QuotaRequestsPage.tsx`,
`RouteMasterPage.tsx`, `TemplateManagerPage.tsx`, `BillingPage.tsx`,
`DeveloperPage.tsx`, `NotificationsPage.tsx`, `ContactGroupsPage.tsx`,
`MessageLogsPage.tsx`) — these were NOT migrated as part of the
2026-09-16 Activity Logs fix (out of scope for that task; only
`ActivityLogsPage.tsx` was migrated to Pattern A, since that was the
one explicitly reported as visually inconsistent). Migrating the rest
is a legitimate future cleanup, but do it as its own explicit task, not
silently while doing something else.

**Rule of thumb:** before writing a brand-new page's outer JSX, open
one or two existing pages in the same area of the app and match their
wrapper/heading/spacing conventions exactly, rather than inventing a
new one. If a page's design has to diverge (rare), say so explicitly
rather than doing it silently.

## Brand colours and type — one theme for the app and the website

Since 2026-10-12 the app (dashboard, admin pages, modals) uses the public
website's brand: **emerald** for primary actions/links/focus rings
(`bg-emerald-600 hover:bg-emerald-700`, `text-emerald-700`,
`focus:ring-emerald-100`), **teal** as the second accent, slate for text and
borders, Inter for text and Plus Jakarta Sans for headings (set globally for
`h1`–`h4` in `index.css`; `font-display` elsewhere). Sidebar/dashboard tokens
live in `src/theme/signalIndigo.ts` (the export keeps its old name; the values
are the brand). **Do not introduce `indigo-*` / `violet-*` classes in new
work.** Exceptions: journey node category colours in `src/journey/nodeRegistry.tsx`
and the per-area icon tints in `NAV_TINTS` are semantic and stay varied. For
small text on white use emerald 700+ (600 is too light for body-size text).

**Theming (accent + dark mode).** `emerald-*` is the *themeable brand scale*,
and `slate-*` / `bg-white` are themeable too: `tailwind.config.js` maps them to
CSS variables, and `core/theme/ThemeContext.tsx` (header Palette button) sets
`data-accent` / `data-theme` on `<html>` while the app shell is mounted (the
website never gets them). So: use `emerald-*` for brand colour, `slate-*` for
text/borders and `bg-white` for surfaces, and dark mode + accents work for free.
Avoid raw hex / `black` / `gray-*` for UI chrome — they will not follow the theme.

## Sidebar active-state — nested routes

`frontend-app/src/components/layout/AppLayout.tsx`'s `NavLink`s use
`end={item.to === '/' || hasNestedSibling(item.to)}` — `hasNestedSibling`
auto-detects when one nav item's route is a path-prefix of another
nav item's route (e.g. `/chatbot` vs. `/chatbot/journeys`) and forces
an exact match for the parent, so visiting the child route doesn't
highlight both sidebar entries. If you add a new nav item whose route
sits directly under an existing one (e.g. `/foo/bar` under an existing
`/foo`), this already self-heals — no manual `end` override needed.
If you add a route matching pattern other than a `to`-prefix (e.g. a
route with query params or a wildcard) double-check the sidebar
highlight manually, since `hasNestedSibling` only understands simple
path-prefix nesting.
