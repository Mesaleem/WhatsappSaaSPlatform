<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Contact sales" request for a custom plan (owner, 2026-10-14). Status:
 * new -> contacted -> closed. See CustomPlanRequestController.
 */
class CustomPlanRequest extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'Billing & Subscriptions';

    /** @var list<string> */
    public const STATUSES = ['new', 'contacted', 'closed'];

    protected $fillable = [
        'account_id', 'user_id', 'name', 'email', 'phone', 'company', 'expected_messages', 'whatsapp_numbers',
        'team_users', 'requirement', 'status', 'admin_note', 'handled_by_user_id', 'contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer', 'user_id' => 'integer', 'expected_messages' => 'integer', 'whatsapp_numbers' => 'integer',
            'team_users' => 'integer', 'handled_by_user_id' => 'integer', 'contacted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }
}
