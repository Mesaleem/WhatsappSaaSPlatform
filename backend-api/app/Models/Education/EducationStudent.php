<?php

namespace App\Models\Education;

use App\Models\Contact;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11 Task 2 — a student: the Education profile of ONE existing CRM contact. Name, phone, email,
 * tags and CRM history live on the contact; this row only carries what is education-specific.
 */
class EducationStudent extends Model
{
    use LogsActivity;

    public const STATUSES = ['active', 'inactive', 'graduated', 'withdrawn'];

    protected string $auditModuleName = 'Education';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'contact_id'];

    protected $table = 'education_students';

    protected $fillable = ['account_id', 'contact_id', 'admission_number', 'status', 'admission_date', 'metadata'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'contact_id' => 'integer',
            'admission_date' => 'date:Y-m-d',
            'metadata' => 'array',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(EducationStudentGuardian::class, 'student_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(EducationGroup::class, 'education_group_members', 'student_id', 'group_id')->withTimestamps();
    }
}
