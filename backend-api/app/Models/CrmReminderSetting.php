<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM Sales Phase 5 — the tenant's payment-reminder rules (one row per tenant). */
class CrmReminderSetting extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_reminder_settings';

    /** @var list<string> */
    public const CHANNELS = ['whatsapp', 'email', 'both'];

    protected $fillable = [
        'account_id', 'enabled', 'days_before', 'on_due_date', 'days_after', 'channel', 'auto_send', 'email_fallback',
        'quiet_start_hour', 'quiet_end_hour', 'max_attempts',
    ];

    protected $attributes = ['enabled' => false, 'on_due_date' => true, 'channel' => 'whatsapp', 'auto_send' => true, 'email_fallback' => false, 'max_attempts' => 3];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer', 'enabled' => 'boolean', 'days_before' => 'array', 'on_due_date' => 'boolean', 'days_after' => 'array',
            'auto_send' => 'boolean', 'email_fallback' => 'boolean', 'quiet_start_hour' => 'integer', 'quiet_end_hour' => 'integer', 'max_attempts' => 'integer',
        ];
    }

    /**
     * Every rule occurrence: rule_key => offset in days from the due date
     * (before:3 => -3, due => 0, after:7 => 7).
     *
     * @return array<string, int>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ((array) $this->days_before as $d) {
            $rules['before:'.(int) $d] = -(int) $d;
        }
        if ($this->on_due_date) {
            $rules['due'] = 0;
        }
        foreach ((array) $this->days_after as $d) {
            $rules['after:'.(int) $d] = (int) $d;
        }

        return $rules;
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
