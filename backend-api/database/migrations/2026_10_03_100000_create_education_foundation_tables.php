<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Task 2 — the Education foundation. Four tables, all hanging off the EXISTING CRM contact:
 *
 *   contacts (CRM)  ←──  education_students  ←──  education_student_guardians ──→  contacts (CRM)
 *                              ↑                                                    (the parent / guardian)
 *                              └──  education_group_members  ──→  education_groups (class | batch)
 *
 * Name, phone, email, tags and CRM activity stay on `contacts`; nothing is duplicated here.
 *
 * Tenant safety is a DATABASE guarantee, not only an application rule: every reference to a contact,
 * student or group is a COMPOSITE foreign key (id, account_id), so a row can never point at another
 * tenant's record. (contacts already carries unique(id, account_id) for exactly this; the two new
 * parents declare the same.) The contact references are RESTRICT: a contact that is a student or a
 * guardian cannot be deleted out from under its education profile (ContactDependencies reports it as
 * a 409 CONTACT_HAS_DEPENDENTS, like leads). The education-owned children cascade with their parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            // Optional school-issued identifier; unique per account when present (NULLs do not collide).
            $table->string('admission_number', 64)->nullable();
            $table->string('status', 20)->default('active'); // active | inactive | graduated | withdrawn
            $table->date('admission_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'contact_id'], 'education_students_account_contact_unique');
            $table->unique(['account_id', 'admission_number'], 'education_students_account_admission_unique');
            $table->unique(['id', 'account_id'], 'education_students_id_account_unique');
            $table->index(['account_id', 'status'], 'education_students_account_status_index');

            $table->foreign(['contact_id', 'account_id'], 'education_students_contact_account_foreign')
                ->references(['id', 'account_id'])->on('contacts')->restrictOnDelete();
        });

        Schema::create('education_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // class | batch — a school's grade or a coaching/institute batch
            $table->string('name', 120);
            $table->string('academic_year', 20)->nullable();
            $table->string('status', 20)->default('active'); // active | archived
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'education_groups_id_account_unique');
            $table->index(['account_id', 'kind', 'status'], 'education_groups_account_kind_status_index');
        });

        Schema::create('education_student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('relationship', 20); // parent | guardian
            $table->timestamps();

            // A contact is linked to a student once (a mother cannot be both "parent" and "guardian" twice).
            $table->unique(['student_id', 'contact_id'], 'education_guardians_student_contact_unique');
            $table->index(['account_id', 'contact_id'], 'education_guardians_account_contact_index');

            $table->foreign(['student_id', 'account_id'], 'education_guardians_student_account_foreign')
                ->references(['id', 'account_id'])->on('education_students')->cascadeOnDelete();
            $table->foreign(['contact_id', 'account_id'], 'education_guardians_contact_account_foreign')
                ->references(['id', 'account_id'])->on('contacts')->restrictOnDelete();
        });

        Schema::create('education_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('group_id');
            $table->timestamps();

            $table->unique(['student_id', 'group_id'], 'education_group_members_student_group_unique');
            $table->index(['account_id', 'group_id'], 'education_group_members_account_group_index');

            $table->foreign(['student_id', 'account_id'], 'education_group_members_student_account_foreign')
                ->references(['id', 'account_id'])->on('education_students')->cascadeOnDelete();
            $table->foreign(['group_id', 'account_id'], 'education_group_members_group_account_foreign')
                ->references(['id', 'account_id'])->on('education_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_group_members');
        Schema::dropIfExists('education_student_guardians');
        Schema::dropIfExists('education_groups');
        Schema::dropIfExists('education_students');
    }
};
