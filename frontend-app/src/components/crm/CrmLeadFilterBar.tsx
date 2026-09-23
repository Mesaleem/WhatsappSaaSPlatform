import { X } from 'lucide-react';
import { ClearFiltersButton, SearchInput, StatusFilterSelect } from '../common/DataTableControls';
import { TagPicker } from './CrmUi';
import { CRM_TAG_FILTER_MAX, hasActiveFilters } from './crmFilters';
import {
  CRM_LEAD_SOURCES,
  CRM_LEAD_STATUSES,
  crmLabel,
  crmSourceLabel,
  type CrmAssignee,
  type CrmLeadFilters,
  type CrmLeadSource,
  type CrmLeadStatus,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. The one filter bar for every CRM lead surface
 * (Leads, Pipeline). Every control maps 1:1 onto a CrmLead::filterRules()
 * key and every change is a new server query — nothing is filtered in
 * memory. Changing one control keeps the others (useCrmUrlState merges).
 *
 * `showStatus` is off on the Pipeline, where status IS the column.
 * Tags use AND semantics (the backend's documented rule), so the chips
 * read "has all of".
 */
export default function CrmLeadFilterBar({
  filters,
  onChange,
  onClear,
  assignees,
  tagName,
  showStatus = true,
}: {
  filters: CrmLeadFilters;
  onChange: (patch: Partial<CrmLeadFilters>) => void;
  onClear: () => void;
  assignees: CrmAssignee[];
  tagName: (id: number) => string;
  showStatus?: boolean;
}) {
  const tagIds = filters.tag_ids ?? [];
  const assigneeValue = filters.assigned_user_id ?? '';
  const assigneeKnown =
    assigneeValue === '' || assigneeValue === 'none' || assignees.some((a) => String(a.id) === assigneeValue);

  return (
    <div className="space-y-3" data-testid="crm-filter-bar">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput
          value={filters.search ?? ''}
          onChange={(search) => onChange({ search })}
          placeholder="Search contact name or phone…"
        />
        {showStatus && (
          <StatusFilterSelect
            value={filters.status ?? ''}
            onChange={(status) => onChange({ status: (status || undefined) as CrmLeadStatus | undefined })}
            options={CRM_LEAD_STATUSES.map((s) => ({ value: s, label: crmLabel(s) }))}
          />
        )}
        <StatusFilterSelect
          value={filters.source ?? ''}
          onChange={(source) => onChange({ source: (source || undefined) as CrmLeadSource | undefined })}
          options={CRM_LEAD_SOURCES.map((s) => ({ value: s, label: crmSourceLabel(s) }))}
          allLabel="All sources"
        />
        <select
          aria-label="Filter by assignee"
          value={assigneeValue}
          onChange={(e) => onChange({ assigned_user_id: e.target.value || undefined })}
          className="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        >
          <option value="">All assignees</option>
          <option value="none">Unassigned</option>
          {!assigneeKnown && <option value={assigneeValue}>User #{assigneeValue}</option>}
          {assignees.map((a) => (
            <option key={a.id} value={String(a.id)}>
              {a.name}
            </option>
          ))}
        </select>
        <TagPicker
          label="Filter by tag"
          excludeIds={tagIds}
          disabled={tagIds.length >= CRM_TAG_FILTER_MAX}
          onPick={(tag) => onChange({ tag_ids: [...tagIds, tag.id] })}
        />
        <ClearFiltersButton active={hasActiveFilters(filters)} onClear={onClear} />
      </div>

      {tagIds.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500" data-testid="crm-tag-filter-chips">
          <span>{tagIds.length > 1 ? 'Has all of:' : 'Has tag:'}</span>
          {tagIds.map((id) => (
            <span key={id} className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">
              {tagName(id)}
              <button
                type="button"
                aria-label={`Remove tag filter ${tagName(id)}`}
                onClick={() => onChange({ tag_ids: tagIds.filter((t) => t !== id) })}
                className="text-indigo-400 hover:text-indigo-700"
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
