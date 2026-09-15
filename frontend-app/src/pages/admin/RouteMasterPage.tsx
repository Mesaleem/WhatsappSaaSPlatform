import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { AlertCircle, Layers, Loader2, Plus, Route, Trash2, X } from 'lucide-react';
import routeMasterService from '../../services/routeMasterService';
import type { RouteCategory, SystemRoute } from '../../types/routeMaster';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Card, TableCard, inputClass } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage } from '../../utils/apiError';

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — Super-Admin-only CRUD console for route_categories +
 * system_routes (RouteMasterController on the backend). This is what
 * ModulePermissionMatrix.tsx's tree ultimately renders from, so a
 * change made here shows up immediately in CreateAccountModal's
 * "Module & Feature Access" checklist (and, via
 * Account::effectiveModules(), dynamically gates every account's actual
 * module access the moment a route or category is deactivated — see
 * SystemRoute::activePermissionKeys()'s docblock).
 *
 * Two flat management tables (Categories, then Routes) rather than a
 * combined tree editor — simplest UI that covers every CRUD action the
 * spec asks for (create/edit/Active-Inactive toggle for both
 * categories and route items) without inventing a bespoke
 * drag-and-drop tree widget out of scope for this phase.
 */
/** UI Standardization — same Active/Inactive vocabulary as every other table's StatusFilterSelect on this admin console. */
const ACTIVE_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

export default function RouteMasterPage() {
  const [categories, setCategories] = useState<RouteCategory[]>([]);
  const [routes, setRoutes] = useState<SystemRoute[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [categoryForm, setCategoryForm] = useState<RouteCategory | null>(null);
  const [routeForm, setRouteForm] = useState<SystemRoute | null>(null);

  // UI Standardization — every other management table on this admin
  // console (Accounts, Users, Developer API Keys, Activity Logs, ...)
  // offers Search + Status filter + Clear Filters + Pagination; Route
  // Master's two tables were built without them (see this feature's own
  // audit) and are brought in line here, client-side (both endpoints
  // already return their full, unpaginated list — see load() below).
  const [categorySearch, setCategorySearch] = useState('');
  const [categoryStatusFilter, setCategoryStatusFilter] = useState('');
  const [categoryPage, setCategoryPage] = useState(1);
  const [categoryPerPage, setCategoryPerPage] = useState(10);

  const [routeSearch, setRouteSearch] = useState('');
  const [routeStatusFilter, setRouteStatusFilter] = useState('');
  const [routeCategoryFilter, setRouteCategoryFilter] = useState('');
  const [routePage, setRoutePage] = useState(1);
  const [routePerPage, setRoutePerPage] = useState(10);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const [categoryList, routeList] = await Promise.all([
        routeMasterService.listCategories(),
        routeMasterService.listRoutes(),
      ]);
      setCategories(categoryList);
      setRoutes(routeList);
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to load the Route Master.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const toggleCategoryActive = async (category: RouteCategory) => {
    try {
      await routeMasterService.updateCategory(category.id, { is_active: !category.is_active });
      await load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to update the category.'));
    }
  };

  const toggleRouteActive = async (route: SystemRoute) => {
    try {
      await routeMasterService.updateRoute(route.id, { is_active: !route.is_active });
      await load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to update the route.'));
    }
  };

  const deleteCategory = async (category: RouteCategory) => {
    try {
      await routeMasterService.deleteCategory(category.id);
      await load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to delete the category.'));
    }
  };

  const deleteRoute = async (route: SystemRoute) => {
    try {
      await routeMasterService.deleteRoute(route.id);
      await load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to delete the route.'));
    }
  };

  const filteredCategories = categories.filter((category) => {
    const q = categorySearch.trim().toLowerCase();
    if (q && !category.category_name.toLowerCase().includes(q) && !category.category_code.toLowerCase().includes(q)) {
      return false;
    }
    if (categoryStatusFilter === 'active' && !category.is_active) return false;
    if (categoryStatusFilter === 'inactive' && category.is_active) return false;
    return true;
  });
  const hasActiveCategoryFilters = categorySearch !== '' || categoryStatusFilter !== '';
  const clearCategoryFilters = () => {
    setCategorySearch('');
    setCategoryStatusFilter('');
  };
  useEffect(() => {
    setCategoryPage(1);
  }, [categorySearch, categoryStatusFilter]);
  const categoryTotal = filteredCategories.length;
  const categoryLastPage = Math.max(1, Math.ceil(categoryTotal / categoryPerPage));
  const categoryCurrentPage = Math.min(categoryPage, categoryLastPage);
  const pagedCategories = filteredCategories.slice(
    (categoryCurrentPage - 1) * categoryPerPage,
    categoryCurrentPage * categoryPerPage,
  );

  const filteredRoutes = routes.filter((route) => {
    const q = routeSearch.trim().toLowerCase();
    if (
      q &&
      !route.route_title.toLowerCase().includes(q) &&
      !route.permission_key.toLowerCase().includes(q) &&
      !(route.route_path?.toLowerCase().includes(q) ?? false)
    ) {
      return false;
    }
    if (routeStatusFilter === 'active' && !route.is_active) return false;
    if (routeStatusFilter === 'inactive' && route.is_active) return false;
    if (routeCategoryFilter && String(route.category_id) !== routeCategoryFilter) return false;
    return true;
  });
  const hasActiveRouteFilters = routeSearch !== '' || routeStatusFilter !== '' || routeCategoryFilter !== '';
  const clearRouteFilters = () => {
    setRouteSearch('');
    setRouteStatusFilter('');
    setRouteCategoryFilter('');
  };
  useEffect(() => {
    setRoutePage(1);
  }, [routeSearch, routeStatusFilter, routeCategoryFilter]);
  const routeTotal = filteredRoutes.length;
  const routeLastPage = Math.max(1, Math.ceil(routeTotal / routePerPage));
  const routeCurrentPage = Math.min(routePage, routeLastPage);
  const pagedRoutes = filteredRoutes.slice((routeCurrentPage - 1) * routePerPage, routeCurrentPage * routePerPage);

  const routeCategoryOptions = categories.map((category) => ({ value: String(category.id), label: category.category_name }));

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Layers}
        title="Route Master"
        subtitle="Manage the categories and route items that power every client's Module & Feature Access checklist."
        actions={
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setCategoryForm({ id: 0, category_name: '', category_code: '', icon_name: '', sort_order: categories.length, is_active: true })}
              className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              <Plus className="h-4 w-4" />
              New Category
            </button>
            <button
              type="button"
              onClick={() =>
                setRouteForm({
                  id: 0,
                  category_id: categories[0]?.id ?? 0,
                  route_title: '',
                  route_path: '',
                  permission_key: '',
                  is_agent_assignable: true,
                  is_active: true,
                  sort_order: routes.length,
                })
              }
              disabled={categories.length === 0}
              className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Plus className="h-4 w-4" />
              New Route
            </button>
          </div>
        }
      />

      {error && (
        <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <>
          <Card>
            <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <Layers className="h-4 w-4 text-indigo-600" />
              Categories
            </h2>
            <div className="mt-4 mb-3 flex flex-wrap items-center gap-3">
              <SearchInput value={categorySearch} onChange={setCategorySearch} placeholder="Search by name or code…" />
              <StatusFilterSelect value={categoryStatusFilter} onChange={setCategoryStatusFilter} options={ACTIVE_STATUS_OPTIONS} />
              <ClearFiltersButton active={hasActiveCategoryFilters} onClear={clearCategoryFilters} />
            </div>
            <div>
              <TableCard>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                      <th className="px-4 py-2.5">Category</th>
                      <th className="px-4 py-2.5">Code</th>
                      <th className="px-4 py-2.5">Icon</th>
                      <th className="px-4 py-2.5">Routes</th>
                      <th className="px-4 py-2.5">Status</th>
                      <th className="px-4 py-2.5" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {isLoading ? (
                      <TableSkeletonRows columns={6} />
                    ) : (
                      <>
                    {pagedCategories.map((category) => (
                      <tr key={category.id} className="hover:bg-slate-50">
                        <td className="px-4 py-2.5">
                          <button type="button" className="font-medium text-slate-900 hover:text-indigo-600" onClick={() => setCategoryForm(category)}>
                            {category.category_name}
                          </button>
                        </td>
                        <td className="px-4 py-2.5 text-slate-500">{category.category_code}</td>
                        <td className="px-4 py-2.5 text-slate-500">{category.icon_name ?? '—'}</td>
                        <td className="px-4 py-2.5 text-slate-500">{category.routes_count ?? 0}</td>
                        <td className="px-4 py-2.5">
                          <ToggleSwitch checked={category.is_active} onChange={() => void toggleCategoryActive(category)} />
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <button
                            type="button"
                            title={(category.routes_count ?? 0) > 0 ? 'Remove or reassign every route in this category first.' : 'Delete category'}
                            disabled={(category.routes_count ?? 0) > 0}
                            onClick={() => void deleteCategory(category)}
                            className="text-slate-400 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </td>
                      </tr>
                    ))}
                    {pagedCategories.length === 0 && (
                      <tr>
                        <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                          {categories.length === 0 ? 'No categories yet.' : 'No categories match the current filters.'}
                        </td>
                      </tr>
                    )}
                      </>
                    )}
                  </tbody>
                </table>
                <Pagination
                  page={categoryCurrentPage}
                  lastPage={categoryLastPage}
                  total={categoryTotal}
                  perPage={categoryPerPage}
                  onPageChange={setCategoryPage}
                  onPerPageChange={setCategoryPerPage}
                />
              </TableCard>
            </div>
          </Card>

          <Card>
            <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <Route className="h-4 w-4 text-indigo-600" />
              Route Items
            </h2>
            <div className="mt-4 mb-3 flex flex-wrap items-center gap-3">
              <SearchInput value={routeSearch} onChange={setRouteSearch} placeholder="Search by title, permission key, or path…" />
              <StatusFilterSelect value={routeStatusFilter} onChange={setRouteStatusFilter} options={ACTIVE_STATUS_OPTIONS} />
              <StatusFilterSelect value={routeCategoryFilter} onChange={setRouteCategoryFilter} options={routeCategoryOptions} allLabel="All categories" />
              <ClearFiltersButton active={hasActiveRouteFilters} onClear={clearRouteFilters} />
            </div>
            <div>
              <TableCard>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                      <th className="px-4 py-2.5">Route Title</th>
                      <th className="px-4 py-2.5">Category</th>
                      <th className="px-4 py-2.5">Permission Key</th>
                      <th className="px-4 py-2.5">Path</th>
                      <th className="px-4 py-2.5">Agent Assignable</th>
                      <th className="px-4 py-2.5">Status</th>
                      <th className="px-4 py-2.5" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {isLoading ? (
                      <TableSkeletonRows columns={7} />
                    ) : (
                      <>
                    {pagedRoutes.map((route) => (
                      <tr key={route.id} className="hover:bg-slate-50">
                        <td className="px-4 py-2.5">
                          <button type="button" className="font-medium text-slate-900 hover:text-indigo-600" onClick={() => setRouteForm(route)}>
                            {route.route_title}
                          </button>
                        </td>
                        <td className="px-4 py-2.5 text-slate-500">{route.category?.category_name ?? '—'}</td>
                        <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{route.permission_key}</td>
                        <td className="px-4 py-2.5 text-slate-500">{route.route_path ?? '—'}</td>
                        <td className="px-4 py-2.5 text-slate-500">{route.is_agent_assignable ? 'Yes' : 'No'}</td>
                        <td className="px-4 py-2.5">
                          <ToggleSwitch checked={route.is_active} onChange={() => void toggleRouteActive(route)} />
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <button type="button" onClick={() => void deleteRoute(route)} className="text-slate-400 hover:text-red-600">
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </td>
                      </tr>
                    ))}
                    {pagedRoutes.length === 0 && (
                      <tr>
                        <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                          {routes.length === 0 ? 'No route items yet.' : 'No route items match the current filters.'}
                        </td>
                      </tr>
                    )}
                      </>
                    )}
                  </tbody>
                </table>
                <Pagination
                  page={routeCurrentPage}
                  lastPage={routeLastPage}
                  total={routeTotal}
                  perPage={routePerPage}
                  onPageChange={setRoutePage}
                  onPerPageChange={setRoutePerPage}
                />
              </TableCard>
            </div>
          </Card>
      </>

      {categoryForm && <CategoryFormModal category={categoryForm} onClose={() => setCategoryForm(null)} onSaved={() => void load()} />}
      {routeForm && <RouteFormModal route={routeForm} categories={categories} onClose={() => setRouteForm(null)} onSaved={() => void load()} />}
    </PageShell>
  );
}

function ToggleSwitch({ checked, onChange }: { checked: boolean; onChange: () => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={onChange}
      className={`relative inline-flex h-5 w-9 items-center rounded-full transition ${checked ? 'bg-indigo-600' : 'bg-slate-300'}`}
    >
      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${checked ? 'translate-x-4.5' : 'translate-x-1'}`} />
    </button>
  );
}

function ModalShell({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{title}</h3>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4 space-y-4">{children}</div>
      </div>
    </div>
  );
}

function CategoryFormModal({ category, onClose, onSaved }: { category: RouteCategory; onClose: () => void; onSaved: () => void }) {
  const isEditMode = category.id > 0;
  const [name, setName] = useState(category.category_name);
  const [code, setCode] = useState(category.category_code);
  const [icon, setIcon] = useState(category.icon_name ?? '');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setIsSaving(true);
    setError(null);
    try {
      const payload = { category_name: name, category_code: code, icon_name: icon || null };
      if (isEditMode) {
        await routeMasterService.updateCategory(category.id, payload);
      } else {
        await routeMasterService.createCategory(payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to save the category.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <ModalShell title={isEditMode ? 'Edit Category' : 'New Category'} onClose={onClose}>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-4">
        {error && (
          <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}
        <label className="block text-sm font-medium text-slate-700">
          Category Name
          <input className={inputClass} value={name} onChange={(event) => setName(event.target.value)} required />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Category Code
          <input
            className={inputClass}
            value={code}
            onChange={(event) => setCode(event.target.value)}
            placeholder="e.g. whatsapp_suite"
            pattern="[A-Za-z0-9_-]+"
            required
          />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Icon Name
          <input className={inputClass} value={icon} onChange={(event) => setIcon(event.target.value)} placeholder="e.g. MessageSquare (lucide-react icon name)" />
        </label>
        <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="submit"
            disabled={isSaving}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
            Save
          </button>
        </div>
      </form>
    </ModalShell>
  );
}

function RouteFormModal({
  route,
  categories,
  onClose,
  onSaved,
}: {
  route: SystemRoute;
  categories: RouteCategory[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEditMode = route.id > 0;
  const [categoryId, setCategoryId] = useState(route.category_id);
  const [title, setTitle] = useState(route.route_title);
  const [path, setPath] = useState(route.route_path ?? '');
  const [permissionKey, setPermissionKey] = useState(route.permission_key);
  const [isAgentAssignable, setIsAgentAssignable] = useState(route.is_agent_assignable);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setIsSaving(true);
    setError(null);
    try {
      const payload = {
        category_id: categoryId,
        route_title: title,
        route_path: path || null,
        permission_key: permissionKey,
        is_agent_assignable: isAgentAssignable,
      };
      if (isEditMode) {
        await routeMasterService.updateRoute(route.id, payload);
      } else {
        await routeMasterService.createRoute(payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to save the route.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <ModalShell title={isEditMode ? 'Edit Route' : 'New Route'} onClose={onClose}>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-4">
        {error && (
          <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}
        <label className="block text-sm font-medium text-slate-700">
          Category
          <select className={inputClass} value={categoryId} onChange={(event) => setCategoryId(Number(event.target.value))} required>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.category_name}
              </option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Route Title
          <input className={inputClass} value={title} onChange={(event) => setTitle(event.target.value)} required />
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Permission Key
          <input
            className={inputClass}
            value={permissionKey}
            onChange={(event) => setPermissionKey(event.target.value)}
            placeholder="e.g. whatsapp_setup"
            required
          />
          <p className="mt-1 text-xs text-slate-400">
            Only permission keys that also exist in Account::MODULES can currently be toggled per-account — see this
            feature's disclosed scope boundary.
          </p>
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Route Path
          <input className={inputClass} value={path} onChange={(event) => setPath(event.target.value)} placeholder="e.g. /settings/whatsapp" />
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={isAgentAssignable} onChange={(event) => setIsAgentAssignable(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-indigo-600" />
          Agent Assignable
        </label>
        <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="submit"
            disabled={isSaving}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
            Save
          </button>
        </div>
      </form>
    </ModalShell>
  );
}
