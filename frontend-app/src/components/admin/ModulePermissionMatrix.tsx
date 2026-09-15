import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import * as LucideIcons from 'lucide-react';
import { AlertCircle, Loader2, ShieldCheck } from 'lucide-react';
import routeMasterService from '../../services/routeMasterService';
import type { PermissionsTreeCategory, PermissionsTreeRoute } from '../../types/routeMaster';
import { extractErrorMessage } from '../../utils/apiError';

type LucideIconComponent = ComponentType<{ className?: string }>;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — the dynamic, database-driven replacement for
 * CreateAccountModal.tsx's hardcoded CORE_COMMON_MODULES /
 * WHATSAPP_SUITE_MODULES / SOCIAL_SUITE_MODULES arrays + its old
 * SuiteSection component. Fetches GET /api/admin/permissions-tree and
 * renders one styled box per active category (header, category icon,
 * "Select all" master checkbox), each with its active routes as a
 * two-column checkbox grid — same visual language SuiteSection already
 * established, just driven by the database instead of a constant array.
 *
 * DISCLOSED SCOPE BOUNDARY: this component renders whatever categories
 * and routes the Route Master (RouteMasterPage.tsx) currently defines,
 * and toggling any of them calls back into the SAME allowed_modules
 * state / AccountController::updatePermissions() plumbing
 * CreateAccountModal.tsx already had — this feature is additive on top
 * of that existing enforcement layer, not a replacement of it (see
 * RouteMasterController's docblock). A consequence: only permission_key
 * values that are also valid Account::MODULES slugs (i.e., the ones
 * RouteMasterSeeder backfilled) actually persist when toggled —
 * accounts.allowed_modules is still validated server-side against
 * Account::MODULES (Rule::in), unchanged by this feature. Adding a
 * BRAND NEW route via Route Master Page with a permission_key outside
 * that fixed list will render here and its `is_active` state will still
 * dynamically gate Account::effectiveModules() everywhere (see that
 * method's docblock), but a Super Admin toggling it per-account here
 * would get a 422 from updatePermissions() until Account::MODULES is
 * also extended — a disclosed follow-up, out of this phase's scope per
 * the Minimal Change Principle.
 */
function resolveIcon(iconName: string | null): LucideIconComponent {
  if (!iconName) return ShieldCheck;
  const icons = LucideIcons as unknown as Record<string, LucideIconComponent>;
  return icons[iconName] ?? ShieldCheck;
}

/**
 * A checkbox that can render a true tri-state "indeterminate" visual —
 * copied verbatim from CreateAccountModal.tsx's IndeterminateCheckbox
 * (React has no `indeterminate` prop; it's only settable imperatively
 * via a ref). Kept as its own local copy rather than a shared import so
 * this component has no dependency on CreateAccountModal.tsx's
 * internals, matching the "refactor into its own file" spirit of the
 * request.
 */
function IndeterminateCheckbox({
  checked,
  indeterminate,
  disabled,
  onChange,
  className,
}: {
  checked: boolean;
  indeterminate: boolean;
  disabled?: boolean;
  onChange: () => void;
  className?: string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);
  return (
    <input ref={ref} type="checkbox" checked={checked} disabled={disabled} onChange={onChange} className={className} />
  );
}

function CategoryBox({
  category,
  isModuleEnabled,
  canDelegateModule,
  busyKey,
  bulkBusy,
  onToggleModule,
  onToggleSuite,
}: {
  category: PermissionsTreeCategory;
  isModuleEnabled: (permissionKey: string) => boolean;
  canDelegateModule: (permissionKey: string) => boolean;
  busyKey: string | null;
  bulkBusy: boolean;
  onToggleModule: (permissionKey: string) => void;
  onToggleSuite: (routes: PermissionsTreeRoute[], enableAll: boolean) => void;
}) {
  // useMemo (not a plain function call in the render body) so oxlint's
  // react(static-components) check can see this returns a STABLE
  // component reference across re-renders (LucideIcons' own exports
  // never change identity; only the lookup itself is memoized here).
  const Icon = useMemo(() => resolveIcon(category.icon), [category.icon]);

  // Same "Select all" derivation SuiteSection already used: computed
  // against the DELEGABLE subset (falling back to every route in this
  // category only if the viewer can delegate none of them), so an Agent
  // viewer's master checkbox can still read as fully checked once
  // they've granted everything they themselves hold.
  const delegableRoutes = category.routes.filter((route) => canDelegateModule(route.permission_key));
  const relevantRoutes = delegableRoutes.length > 0 ? delegableRoutes : category.routes;
  const enabledCount = relevantRoutes.filter((route) => isModuleEnabled(route.permission_key)).length;
  const allEnabled = relevantRoutes.length > 0 && enabledCount === relevantRoutes.length;
  const someEnabled = enabledCount > 0 && !allEnabled;

  return (
    <div className="mt-4 rounded-lg border border-slate-200 p-3">
      <div className="flex items-center justify-between gap-3">
        <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-700">
          <Icon className="h-3.5 w-3.5 text-indigo-600" />
          {category.category_name}
        </span>
        <label className="flex items-center gap-1.5 text-[11px] font-medium normal-case tracking-normal text-slate-500">
          Select all
          <IndeterminateCheckbox
            checked={allEnabled}
            indeterminate={someEnabled}
            disabled={bulkBusy}
            onChange={() => onToggleSuite(relevantRoutes, !allEnabled)}
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60"
          />
        </label>
      </div>
      <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {category.routes.map((route) => {
          const enabled = isModuleEnabled(route.permission_key);
          const busy = busyKey === route.permission_key;
          const locked = !canDelegateModule(route.permission_key);
          return (
            <label
              key={route.id}
              title={locked ? 'Not enabled on your own agent plan.' : undefined}
              className={`flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm transition ${
                enabled ? 'border-indigo-200 bg-indigo-50/50' : 'border-slate-200'
              } ${locked ? 'opacity-50' : ''}`}
            >
              <span className={enabled ? 'text-slate-900' : 'text-slate-500'}>{route.route_title}</span>
              <span className="flex items-center gap-2">
                {busy && <Loader2 className="h-3.5 w-3.5 animate-spin text-slate-400" />}
                <input
                  type="checkbox"
                  checked={enabled}
                  disabled={busy || locked}
                  onChange={() => onToggleModule(route.permission_key)}
                  className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed"
                />
              </span>
            </label>
          );
        })}
      </div>
    </div>
  );
}

export interface ModulePermissionMatrixProps {
  isModuleEnabled: (permissionKey: string) => boolean;
  canDelegateModule: (permissionKey: string) => boolean;
  busyKey: string | null;
  bulkBusy: boolean;
  onToggleModule: (permissionKey: string) => void;
  onToggleSuite: (routes: PermissionsTreeRoute[], enableAll: boolean) => void;
}

/**
 * Fetches the dynamic permissions tree once on mount and renders every
 * active category as its own CategoryBox. All toggle state/logic stays
 * owned by the parent (CreateAccountModal.tsx) exactly as it did for the
 * old hardcoded SuiteSection — this component only supplies the
 * category/route STRUCTURE dynamically.
 */
export default function ModulePermissionMatrix({
  isModuleEnabled,
  canDelegateModule,
  busyKey,
  bulkBusy,
  onToggleModule,
  onToggleSuite,
}: ModulePermissionMatrixProps) {
  const [categories, setCategories] = useState<PermissionsTreeCategory[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    routeMasterService
      .fetchPermissionsTree()
      .then((data) => {
        if (!cancelled) setCategories(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(extractErrorMessage(err, 'Failed to load the module permission matrix.'));
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (error) {
    return (
      <div
        role="alert"
        className="mt-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      >
        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
        <span>{error}</span>
      </div>
    );
  }

  if (categories === null) {
    return (
      <div className="mt-4 flex items-center gap-2 text-sm text-slate-500">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading module permissions&hellip;
      </div>
    );
  }

  return (
    <>
      {categories.map((category) => (
        <CategoryBox
          key={category.category_id}
          category={category}
          isModuleEnabled={isModuleEnabled}
          canDelegateModule={canDelegateModule}
          busyKey={busyKey}
          bulkBusy={bulkBusy}
          onToggleModule={onToggleModule}
          onToggleSuite={onToggleSuite}
        />
      ))}
    </>
  );
}
