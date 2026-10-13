import { useState } from 'react';
import { CalendarClock, CheckCircle2, Loader2, MessageSquare, Phone, StickyNote, Users, Mail, ListTodo, Sparkles } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { CRM_ACTIVITY_TYPES, crmLabel, type CrmActivity, type CrmActivityLink, type CrmActivityType } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';
import { formatDate } from './crmFilters';
import { useCrmQuery } from './crmHooks';
import { READ_ONLY_TITLE } from './CrmUi';

const ICONS: Record<CrmActivityType, typeof Phone> = {
  call: Phone,
  meeting: Users,
  task: ListTodo,
  email: Mail,
  whatsapp: MessageSquare,
  note: StickyNote,
  event: Sparkles,
};

const fieldClass =
  'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * CRM Sales Phase 1 — one record's timeline (lead, contact, company or deal) with an
 * "add activity" form: calls, meetings, tasks, emails, WhatsApp follow-ups and notes,
 * plus the events the system writes (converted, deal won...). Pending items can be
 * marked done. Everything is decided server-side; the list is re-read after a change.
 */
export default function ActivityPanel({
  link,
  readOnly = false,
  title = 'Activity & follow-ups',
}: {
  link: CrmActivityLink;
  readOnly?: boolean;
  title?: string;
}) {
  const key = JSON.stringify(link);
  const [version, setVersion] = useState(0);
  const query = useCrmQuery(`activities:${key}:${version}`, () => crmSalesService.listActivities(link, 1, 50), 'Failed to load activities.');
  const activities = query.data?.data ?? [];

  const [type, setType] = useState<(typeof CRM_ACTIVITY_TYPES)[number]>('task');
  const [subject, setSubject] = useState('');
  const [dueAt, setDueAt] = useState('');
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const add = () => {
    if (!subject.trim() || saving) return;
    setSaving(true);
    setError(null);
    crmSalesService
      .createActivity({ ...link, type, subject: subject.trim(), due_at: dueAt ? new Date(dueAt).toISOString() : null })
      .then(() => {
        setSubject('');
        setDueAt('');
        setVersion((v) => v + 1);
      })
      .catch((err: unknown) => setError(describeApiError(err, 'Could not add the activity.').message))
      .finally(() => setSaving(false));
  };

  const complete = (activity: CrmActivity) => {
    setBusyId(activity.id);
    setError(null);
    crmSalesService
      .updateActivity(activity.id, { status: 'done' })
      .then(() => setVersion((v) => v + 1))
      .catch((err: unknown) => setError(describeApiError(err, 'Could not update the activity.').message))
      .finally(() => setBusyId(null));
  };

  return (
    <div className="space-y-4" data-testid="crm-activity-panel">
      <p className="text-sm font-semibold text-slate-900">{title}</p>

      {!readOnly && (
        <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
          <div className="flex flex-wrap gap-2">
            <select aria-label="Activity type" value={type} onChange={(e) => setType(e.target.value as typeof type)} className={`${fieldClass} w-auto`}>
              {CRM_ACTIVITY_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t === 'whatsapp' ? 'WhatsApp' : crmLabel(t)}
                </option>
              ))}
            </select>
            {type !== 'note' && (
              <input
                type="datetime-local"
                aria-label="Due date"
                value={dueAt}
                onChange={(e) => setDueAt(e.target.value)}
                className={`${fieldClass} w-auto`}
              />
            )}
          </div>
          <div className="flex gap-2">
            <input
              aria-label="Activity subject"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && add()}
              placeholder={type === 'note' ? 'Write a note…' : 'What needs to happen? e.g. Call back about pricing'}
              maxLength={191}
              className={fieldClass}
            />
            <button
              type="button"
              onClick={add}
              disabled={saving || !subject.trim()}
              className="flex-shrink-0 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Add'}
            </button>
          </div>
        </div>
      )}
      {readOnly && <p className="text-xs text-slate-500">{READ_ONLY_TITLE}</p>}

      {error && (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      )}

      {query.isLoading ? (
        <div className="flex justify-center py-4">
          <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
        </div>
      ) : query.error ? (
        <p className="text-sm text-red-600">{query.error}</p>
      ) : activities.length === 0 ? (
        <p className="text-sm text-slate-500">No activity yet. Add a follow-up or a note above.</p>
      ) : (
        <ol className="space-y-3">
          {activities.map((a) => {
            const Icon = ICONS[a.type] ?? StickyNote;
            return (
              <li key={a.id} className="flex gap-3" data-testid="crm-activity">
                <span className={`mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full ${a.type === 'event' ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700'}`}>
                  <Icon className="h-3.5 w-3.5" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className={`text-sm ${a.status === 'done' && a.type !== 'event' && a.type !== 'note' ? 'text-slate-500 line-through' : 'text-slate-900'}`}>{a.subject}</p>
                  <p className="text-xs text-slate-500">
                    {a.type === 'event' ? 'System' : a.type === 'whatsapp' ? 'WhatsApp' : crmLabel(a.type)}
                    {a.created_by ? ` · ${a.created_by.name}` : ''} · {formatDate(a.created_at)}
                    {a.due_at && a.status === 'pending' && (
                      <span className={`ml-1 inline-flex items-center gap-1 ${a.is_overdue ? 'font-semibold text-red-600' : ''}`}>
                        <CalendarClock className="h-3 w-3" /> due {formatDate(a.due_at)}
                        {a.is_overdue && ' (overdue)'}
                      </span>
                    )}
                  </p>
                  {a.outcome && <p className="text-xs text-slate-600">Outcome: {a.outcome}</p>}
                </div>
                {a.status === 'pending' && !readOnly && (
                  <button
                    type="button"
                    onClick={() => complete(a)}
                    disabled={busyId === a.id}
                    aria-label={`Mark "${a.subject}" done`}
                    title="Mark done"
                    className="flex-shrink-0 self-start rounded-lg p-1 text-slate-400 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-50"
                  >
                    {busyId === a.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  </button>
                )}
              </li>
            );
          })}
        </ol>
      )}
    </div>
  );
}
