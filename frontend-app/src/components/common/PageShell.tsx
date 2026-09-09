import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, type LucideIcon } from 'lucide-react';

/**
 * UI Standardization — shared page container. Every standardized screen
 * uses the same outer padding (`p-6`) and centered max-width column
 * (`space-y-6`) instead of each page hand-rolling its own, so spacing
 * stays visually identical platform-wide. `maxWidthClassName` lets a page
 * keep its existing content width (e.g. `max-w-5xl`) while still sharing
 * the rest of the shell.
 */
export function PageShell({
  children,
  maxWidthClassName = 'max-w-6xl',
}: {
  children: ReactNode;
  maxWidthClassName?: string;
}) {
  return (
    <div className="p-6">
      <div className={`mx-auto ${maxWidthClassName} space-y-6`}>{children}</div>
    </div>
  );
}

/**
 * UI Standardization — shared page header: back-to-dashboard link, an
 * icon + title, and an optional subtitle. Replaces the near-identical
 * hand-copied header block that BillingPage, DeveloperPage and
 * GatewaySettingsPage each wrote separately.
 */
export function PageHeader({
  icon: Icon,
  title,
  subtitle,
  backTo = '/',
  actions,
}: {
  icon?: LucideIcon;
  title: string;
  subtitle?: string;
  backTo?: string | null;
  actions?: ReactNode;
}) {
  return (
    <div>
      {backTo && (
        <Link to={backTo} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
          <ArrowLeft className="h-4 w-4" />
          Back to dashboard
        </Link>
      )}
      <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-xl font-semibold text-slate-900">
          {Icon && <Icon className="h-5 w-5 text-indigo-600" />}
          {title}
        </h1>
        {actions}
      </div>
      {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
    </div>
  );
}
