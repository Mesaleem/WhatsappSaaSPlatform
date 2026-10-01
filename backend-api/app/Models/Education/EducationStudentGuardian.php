<?php

namespace App\Models\Education;

use App\Models\Contact;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 Task 2 — links a student to a parent/guardian, who is an existing CRM contact. A student may
 * have any number of them (none, one, or several); a contact may guard several students (siblings).
 */
class EducationStudentGuardian extends Model
{
    use LogsActivity;

    public const RELATIONSHIPS = ['parent', 'guardian'];

    protected string $auditModuleName = 'Education';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'student_id', 'contact_id'];

    protected $table = 'education_student_guardians';

    protected $fillable = ['account_id', 'student_id', 'contact_id', 'relationship'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(EducationStudent::class, 'student_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
