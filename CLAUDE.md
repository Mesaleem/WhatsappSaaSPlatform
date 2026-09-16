# Project notes for AI coding assistants

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
