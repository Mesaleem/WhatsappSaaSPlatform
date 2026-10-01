<?php

namespace App\Models\Education;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 Task 3 — one student's attendance in one class/batch on one date. No personal data is stored
 * here; the person is the CRM contact behind the student.
 */
class EducationAttendance extends Model
{
    /** Add a status here (and to the UI list) — no migration needed. */
    public const STATUSES = ['present', 'absent', 'late'];

    /** Statuses that count as having attended when a percentage is computed. */
    public const ATTENDED = ['present', 'late'];

    protected $table = 'education_attendances';

    protected $fillable = ['account_id', 'student_id', 'group_id', 'attendance_date', 'status', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'student_id' => 'integer', 'group_id' => 'integer', 'attendance_date' => 'date:Y-m-d'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(EducationStudent::class, 'student_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EducationGroup::class, 'group_id');
    }
}
