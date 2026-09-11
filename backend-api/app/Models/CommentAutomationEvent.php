<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Redelivery-safety + audit-trail row for one processed comment webhook
 * event — see the creating migration's docblock for why this exists
 * beyond the literal spec's table list.
 */
class CommentAutomationEvent extends Model
{
    protected $fillable = [
        'account_id',
        'comment_automation_rule_id',
        'platform',
        'comment_id',
        'post_id',
        'commenter_id',
        'public_replied_at',
        'public_reply_error',
        'private_message_sent_at',
        'private_message_error',
    ];

    protected function casts(): array
    {
        return [
            'public_replied_at' => 'datetime',
            'private_message_sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommentAutomationRule::class, 'comment_automation_rule_id');
    }
}
