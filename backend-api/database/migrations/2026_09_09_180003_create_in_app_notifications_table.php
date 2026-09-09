<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user in-app notification inbox rows fanned out from a broadcast
     * (channels containing 'in_app'). `user_id` is NOT nullable and uses
     * cascadeOnDelete (unlike login_audit_logs/notification_broadcasts,
     * which intentionally keep their history after the referenced row is
     * gone): an in-app notification has no meaning without a user to read
     * it, so it is correct for it to disappear when the user does.
     */
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->nullable()->constrained('notification_broadcasts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('category')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
