<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 fix P5-1 — freeze a group batch's recipients at reservation.
     *
     * A group dispatch reserves quota for N members
     * (MessageQuotaService::reserve()) and queues a job. That job used to
     * RE-READ the live group membership, so members added in between were
     * sent to without any quota reserved for them, and
     * success + failure stopped equalling recipient_count.
     *
     * This table is the recipient list the reservation paid for, written
     * in the SAME transaction as the reserve() and the queued parent row
     * (so it is exactly the N that were reserved), and it is what both
     * group jobs now iterate. One row per reserved member.
     *
     *   parent_dispatch_id      the queued parent message_dispatch_logs row
     *   account_id              copied from the parent — the job reads rows
     *                           by (parent, account), never from its payload
     *   contact_group_member_id the member's id at reservation time. No
     *                           foreign key on purpose: a member removed
     *                           after reservation must still be represented
     *                           (it is resolved as a failure, and refunded)
     *   phone_number, name      the values the reservation covered
     *
     * Purely ADDITIVE: a new table, nothing existing is altered.
     */
    public function up(): void
    {
        Schema::create('group_dispatch_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_dispatch_id')->constrained('message_dispatch_logs')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_group_member_id');
            $table->string('phone_number');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['parent_dispatch_id', 'contact_group_member_id'], 'gdr_parent_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_dispatch_recipients');
    }
};
