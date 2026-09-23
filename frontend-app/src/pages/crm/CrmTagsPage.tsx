import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import crmService from '../../services/crmService';
import { TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import PromptModal from '../../components/common/PromptModal';
import { ClearFiltersButton, Pagination, SearchInput } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Toast } from '../../components/crm/CrmUi';
import { formatDate } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { describeApiError } from '../../utils/apiError';
import type { CrmTag } from '../../types/crm';

/** CrmTag::NAME_MAX — mirrored only as an input maxLength hint; the server validates. */
const TAG_NAME_MAX = 50;
const TAGS_PER_PAGE = 50;

/**
 * Phase 6 — CRM Task 8. Tag management — GET/POST /api/crm/tags,
 * PATCH/DELETE /api/crm/tags/{id} (Task 7).
 *
 * Name rules (trim, whitespace collapse, 50 chars, case-insensitive
 * duplicate per account) are enforced ONLY by the backend; its 422 message
 * is shown next to the input. Search is the backend's case-insensitive
 * prefix match. lead_count comes from the list response (one query
 * server-side) and links to the lead list filtered by that tag.
 * Deleting a tag removes it from its leads; leads and contacts are kept.
 */
export default function CrmTagsPage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [params, setParams] = useSearchParams();
  const search = params.get('q') ?? '';
  const page = Math.max(1, Number(params.get('page')) || 1);

  const update = (next: { q?: string; page?: number }) => {
    const out = new URLSearchParams();
    const q = next.q ?? search;
    const p = next.page ?? page;
    if (q.trim() !== '') out.set('q', q);
    if (p > 1) out.set('page', String(p));
    setParams(out);
  };

  const query = useCrmQuery(
    needsClient ? null : JSON.stringify({ account: selectedAccountId, search, page }),
    () => crmService.listTags(search, page, TAGS_PER_PAGE),
    'Failed to load tags.',
  );
  const tags: CrmTag[] = query.data?.data ?? [];
  const total = query.data?.total ?? 0;
  const lastPage = query.data?.last_page ?? 1;
  const isLoading = query.isLoading;
  const error = query.error;
  const load = query.reload;

  const [newName, setNewName] = useState('');
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const [renaming, setRenaming] = useState<CrmTag | null>(null);
  const [renameBusy, setRenameBusy] = useState(false);
  const [deleting, setDeleting] = useState<CrmTag | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);

  const create = (e: FormEvent) => {
    e.preventDefault();
    if (creating || newName.trim() === '') return;
    setCreating(true);
    setCreateError(null);
    crmService
      .createTag(newName)
      .then((res) => {
        setNewName('');
        show(res.message);
        load();
      })
      .catch((err: unknown) => setCreateError(describeApiError(err, 'Failed to create the tag.').message))
      .finally(() => setCreating(false));
  };

  const rename = (name: string) => {
    if (!renaming || renameBusy) return;
    setRenameBusy(true);
    crmService
      .renameTag(renaming.id, name)
      .then((res) => {
        query.setData((current) => ({ ...current, data: current.data.map((t) => (t.id === res.data.id ? res.data : t)) }));
        setRenaming(null);
        show(res.message);
      })
      // The modal stays open so the name can be corrected (e.g. duplicate).
      .catch((err: unknown) => show(describeApiError(err, 'Failed to rename the tag.').message))
      .finally(() => setRenameBusy(false));
  };

  const remove = () => {
    if (!deleting || deleteBusy) return;
    setDeleteBusy(true);
    crmService
      .deleteTag(deleting.id)
      .then((res) => {
        setDeleting(null);
        show(res.message);
        load();
      })
      .catch((err: unknown) => {
        show(describeApiError(err, 'Failed to delete the tag.').message);
        setDeleting(null);
      })
      .finally(() => setDeleteBusy(false));
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">CRM Tags</h1>
          <p className="mt-1 text-sm text-slate-500">Labels for classifying and filtering leads. Tags never change a lead’s status.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM tags" />
        ) : (
          <>
            <form onSubmit={create} className="flex flex-wrap items-start gap-3" data-testid="crm-create-tag">
              <div className="w-full sm:w-72">
                <label htmlFor="crm-new-tag" className="sr-only">
                  New tag name
                </label>
                <input
                  id="crm-new-tag"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  placeholder="New tag name, e.g. Follow Up"
                  maxLength={TAG_NAME_MAX}
                  disabled={readOnly}
                  className={inputClass.replace('mt-1.5 ', '')}
                />
                {createError && <p className="mt-1 text-xs text-red-600" role="alert">{createError}</p>}
              </div>
              <button
                type="submit"
                disabled={readOnly || creating || newName.trim() === ''}
                title={readOnly ? READ_ONLY_TITLE : undefined}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                Add tag
              </button>
              <div className="flex flex-wrap items-center gap-3 sm:ml-auto">
                <SearchInput value={search} onChange={(q) => update({ q, page: 1 })} placeholder="Search tags…" />
                <ClearFiltersButton active={search !== ''} onClear={() => update({ q: '', page: 1 })} />
              </div>
            </form>

            {error && <ErrorBanner message={error} />}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-tags-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Tag</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Leads</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Created</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {isLoading ? (
                    <TableSkeletonRows columns={4} />
                  ) : tags.length === 0 ? (
                    <tr>
                      <td colSpan={4}>
                        <EmptyState
                          title={search ? 'No tags match this search.' : 'No tags yet.'}
                          hint={search ? undefined : 'Create tags like “Hot” or “Follow Up” to classify leads.'}
                        />
                      </td>
                    </tr>
                  ) : (
                    tags.map((tag) => (
                      <tr key={tag.id} data-testid={`crm-tag-row-${tag.id}`}>
                        <td className="px-4 py-3">
                          <span className="inline-flex rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{tag.name}</span>
                        </td>
                        <td className="px-4 py-3">
                          <Link to={`/crm/leads?tags=${tag.id}`} className="text-indigo-600 hover:underline" data-testid={`crm-tag-count-${tag.id}`}>
                            {tag.lead_count}
                          </Link>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(tag.created_at)}</td>
                        <td className="px-4 py-3">
                          <div className="flex justify-end gap-2">
                            <button
                              type="button"
                              onClick={() => setRenaming(tag)}
                              disabled={readOnly}
                              title={readOnly ? READ_ONLY_TITLE : 'Rename'}
                              aria-label={`Rename ${tag.name}`}
                              className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                              <Pencil className="h-4 w-4" />
                            </button>
                            <button
                              type="button"
                              onClick={() => setDeleting(tag)}
                              disabled={readOnly}
                              title={readOnly ? READ_ONLY_TITLE : 'Delete'}
                              aria-label={`Delete ${tag.name}`}
                              className="rounded-md p-1.5 text-red-500 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <Pagination page={page} lastPage={lastPage} total={total} perPage={TAGS_PER_PAGE} onPageChange={(p) => update({ page: p })} />
            </TableCard>
          </>
        )}
      </div>

      {renaming && (
        <PromptModal
          title="Rename tag"
          label="Tag name"
          defaultValue={renaming.name}
          confirmLabel="Save"
          required
          isLoading={renameBusy}
          onSubmit={rename}
          onCancel={() => setRenaming(null)}
        />
      )}

      {deleting && (
        <ConfirmModal
          title="Delete tag"
          message={`Delete the tag “${deleting.name}”? It will be removed from ${deleting.lead_count} ${
            deleting.lead_count === 1 ? 'lead' : 'leads'
          }. The leads themselves, their statuses and their contacts are not deleted or changed.`}
          confirmLabel="Delete tag"
          isLoading={deleteBusy}
          onConfirm={remove}
          onCancel={() => setDeleting(null)}
        />
      )}

      <Toast message={toast} />
    </div>
  );
}
