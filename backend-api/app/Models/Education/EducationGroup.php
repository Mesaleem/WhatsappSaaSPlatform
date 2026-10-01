<?php

namespace App\Models\Education;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Phase 11 Task 2 — one reusable grouping of students: a school class/grade, or a coaching/institute
 * batch. `kind` is data (class | batch); the same model and API serve every vertical. Attendance, fees
 * and exams are NOT modelled — they will hang off this row's id later.
 */
class EducationGroup extends Model
{
    use LogsActivity;

    public const KINDS = ['class', 'batch'];

    public const STATUSES = ['active', 'archived'];

    protected string $auditModuleName = 'Education';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'name'];

    protected $table = 'education_groups';

    protected $fillable = ['account_id', 'kind', 'name', 'academic_year', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'metadata' => 'array'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(EducationStudent::class, 'education_group_members', 'group_id', 'student_id')->withTimestamps();
    }
}
