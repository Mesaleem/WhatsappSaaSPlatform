import { useSearchParams } from 'react-router-dom';
import educationService from '../../services/educationService';
import GroupsPanel from '../../components/education/GroupsPanel';
import StudentsPanel from '../../components/education/StudentsPanel';
import AttendancePanel from '../../components/education/AttendancePanel';
import AttendanceHistory from '../../components/education/AttendanceHistory';
import FeesTab from '../../components/education/FeesTab';
import { hasIndustryModule } from '../../components/layout/industryNav';
import { useIndustryModuleKeys } from '../../components/layout/useIndustryModuleKeys';
import { useEducationContext } from '../../components/education/educationHooks';
import { NoClientSelected, Toast } from '../../components/crm/CrmUi';
import { useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';

type Tab = 'students' | 'groups' | 'attendance' | 'history' | 'fees';

/**
 * Phase 11 Task 2 — Education landing page: the implemented foundation only, with Students and
 * Classes / Batches kept clearly apart. No attendance, fees, exams or timetable.
 */
export default function EducationPage() {
  const { needsClient, readOnly, canManage, selectedAccountId } = useEducationContext();
  const { toast, show } = useCrmToast();
  const [params, setParams] = useSearchParams();
  // Attendance is its own industry module: its tabs appear only when the backend says it is usable here.
  const { keys } = useIndustryModuleKeys();
  const attendanceUsable = hasIndustryModule(keys, 'education.attendance');
  // Fees is its own module (it also needs the shared billing capability): same rule, same source (the backend's keys).
  const feesUsable = hasIndustryModule(keys, 'education.fees');
  const requested = params.get('tab');
  const tab: Tab =
    requested === 'groups' ? 'groups'
    : attendanceUsable && (requested === 'attendance' || requested === 'history') ? requested
    : feesUsable && requested === 'fees' ? 'fees'
    : 'students';

  // One read of the class/batch list per client: the Classes tab shows it, the Students tab filters and assigns by it.
  const groupsQuery = useCrmQuery(
    needsClient ? null : `groups:${selectedAccountId ?? 'own'}`,
    () => educationService.listGroups({}, 1, 100),
    'Failed to load classes and batches.',
  );
  const groups = groupsQuery.data?.data ?? [];

  const tabClass = (active: boolean) =>
    `border-b-2 px-4 py-2 text-sm font-medium ${active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Education</h1>
          <p className="mt-1 text-sm text-slate-500">Students, their parents and guardians, the classes or batches they belong to, their attendance and fees — all linked to your CRM contacts.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="students and classes" />
        ) : (
          <>
            <div className="flex gap-2 border-b border-slate-200" role="tablist">
              <button type="button" role="tab" aria-selected={tab === 'students'} className={tabClass(tab === 'students')} onClick={() => setParams({})}>
                Students
              </button>
              <button type="button" role="tab" aria-selected={tab === 'groups'} className={tabClass(tab === 'groups')} onClick={() => setParams({ tab: 'groups' })}>
                Classes / Batches
              </button>
              {attendanceUsable && (
                <>
                  <button type="button" role="tab" aria-selected={tab === 'attendance'} className={tabClass(tab === 'attendance')} onClick={() => setParams({ tab: 'attendance' })}>
                    Attendance
                  </button>
                  <button type="button" role="tab" aria-selected={tab === 'history'} className={tabClass(tab === 'history')} onClick={() => setParams({ tab: 'history' })}>
                    Attendance history
                  </button>
                </>
              )}
              {feesUsable && (
                <button type="button" role="tab" aria-selected={tab === 'fees'} className={tabClass(tab === 'fees')} onClick={() => setParams({ tab: 'fees' })}>
                  Fees
                </button>
              )}
            </div>

            {tab === 'students' ? (
              <StudentsPanel accountKey={selectedAccountId} groups={groups} canManage={canManage} readOnly={readOnly} onToast={show} />
            ) : tab === 'attendance' ? (
              <AttendancePanel accountKey={selectedAccountId} groups={groups} groupsLoading={groupsQuery.isLoading} canManage={canManage} readOnly={readOnly} onToast={show} />
            ) : tab === 'fees' ? (
              <FeesTab accountKey={selectedAccountId} canManage={canManage} readOnly={readOnly} onToast={show} />
            ) : tab === 'history' ? (
              <AttendanceHistory accountKey={selectedAccountId} groups={groups} />
            ) : (
              <GroupsPanel
                groups={groups}
                isLoading={groupsQuery.isLoading}
                error={groupsQuery.error}
                canManage={canManage}
                readOnly={readOnly}
                onChanged={groupsQuery.reload}
                onToast={show}
              />
            )}
          </>
        )}
      </div>
      <Toast message={toast} />
    </div>
  );
}
