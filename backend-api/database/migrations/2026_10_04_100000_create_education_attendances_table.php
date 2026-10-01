<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Task 3 — Education attendance. ONE table: a row says "this student was <status> in this
 * class/batch on this date". Nothing about the person is copied (name/phone/email stay on the CRM contact
 * reached through the student).
 *
 *  - unique(account_id, student_id, group_id, attendance_date): a database guarantee against duplicates,
 *    which is also what makes the bulk save an idempotent UPSERT and keeps two simultaneous submissions
 *    from creating two rows.
 *  - Composite FKs (student_id, account_id) and (group_id, account_id): a row can never join another
 *    account's student or group. Both CASCADE — attendance is education-owned data of those parents
 *    (students and groups are never deleted by the API; they are deactivated/archived, which keeps history).
 *  - `status` is a plain string validated against EducationAttendance::STATUSES, so a new status (excused,
 *    holiday…) is a code change, not a migration.
 *  - Group MEMBERSHIP is deliberately NOT a foreign key: a student may later leave a class, and the history
 *    they earned there must stay readable. "Only members get attendance" is enforced by the service, in the
 *    same transaction as the write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('group_id');
            $table->date('attendance_date');
            $table->string('status', 20);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'student_id', 'group_id', 'attendance_date'], 'education_attendance_unique');
            $table->index(['account_id', 'group_id', 'attendance_date'], 'education_attendance_group_date_index');
            $table->index(['account_id', 'student_id', 'attendance_date'], 'education_attendance_student_date_index');

            $table->foreign(['student_id', 'account_id'], 'education_attendance_student_account_foreign')
                ->references(['id', 'account_id'])->on('education_students')->cascadeOnDelete();
            $table->foreign(['group_id', 'account_id'], 'education_attendance_group_account_foreign')
                ->references(['id', 'account_id'])->on('education_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_attendances');
    }
};
