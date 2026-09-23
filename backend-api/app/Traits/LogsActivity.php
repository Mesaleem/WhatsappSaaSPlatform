<?php

namespace App\Traits;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — Global Audit Logging Engine (requirement 3): "Ensure all
 * backend controllers trigger the Activity Logger trait on CRUD
 * actions."
 *
 * DISCLOSED DESIGN SUBSTITUTION: implemented as a MODEL trait (hooking
 * Eloquent's created/updated/deleted events via the boot{Trait}()
 * convention — see Model::bootTraits()) rather than manually adding a
 * logging call inside every controller action. This is the standard
 * Laravel pattern for exhaustive audit trails (it is, not coincidentally,
 * how spatie/laravel-activitylog's own `LogsActivity` trait works, which
 * this requirement's exact phrasing echoes) and is strictly safer than
 * threading calls through ~20+ controller methods by hand in a codebase
 * with zero test coverage: every mutation this project's own controllers
 * already perform via `$model->save()/update()/delete()` is captured
 * automatically and permanently, with no risk of a future controller
 * action forgetting to call a logger. See this feature's summary doc for
 * the full list of models this trait was/wasn't added to and why.
 *
 * Fires only when there's a real authenticated actor and we're not
 * running in the console (`app()->runningInConsole()`) — so
 * RouteMasterSeeder/DatabaseSeeder's own firstOrCreate() calls, and any
 * artisan command, never pollute activity_logs with rows attributed to
 * no one. Logs every authenticated mutation regardless of role
 * (including Super Admin's own) — narrowing this to only Agent/Client
 * actors would leave Super Admin's own mutations invisible to the very
 * audit trail meant to hold everyone accountable, defeating the point.
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->recordActivity('create', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());

            if ($changes === []) {
                // Every changed field was either a timestamp or one of
                // this model's own $hidden secrets (e.g. only an OAuth
                // access_token got refreshed) — nothing meaningful left
                // to audit.
                return;
            }

            $original = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);

            // Disclosed refinement beyond the spec's literal action_type
            // list: a change touching ONLY is_active (Route Master /
            // account activation, etc.) reads as 'toggle' rather than a
            // generic 'update'.
            $actionType = array_keys($changes) === ['is_active'] ? 'toggle' : 'update';

            // Phase 6 CRM Task 9 — an opted-in model ($auditIdentity) also
            // names the record the row is about, so an `update` row can
            // be traced to its lead even when many are changed at once.
            // Models without $auditIdentity are unaffected.
            $identity = $model->auditIdentityAttributes();
            $model->recordActivity($actionType, $identity + $original, $identity + $changes);
        });

        static::deleted(function ($model) {
            $model->recordActivity('delete', $model->auditableAttributes($model->getAttributes()), null);
        });
    }

    /**
     * Strips this model's OWN $hidden attributes (every model in this
     * codebase that stores a secret/token/password hash already
     * declares $hidden for exactly that field — see User::$hidden,
     * PaymentGatewaySetting::$hidden, SocialAccount::$hidden,
     * SocialProviderConfig::$hidden, WebhookSubscription::$hidden,
     * ApiKey::$hidden, MailSetting::$hidden) plus the two timestamp
     * columns, which are never useful audit payload (this ActivityLog
     * row already has its own created_at).
     *
     * @return array<string, mixed>
     */
    /**
     * Phase 6 CRM Task 9 — attributes a model always includes in its
     * `update` rows so each row identifies its record (created/deleted rows
     * already carry the full attribute set). Opt-in: a model declares
     * `protected array $auditIdentity = ['id'];`. Empty for every other
     * model, so their audit rows are exactly as before.
     *
     * @return array<string, mixed>
     */
    public function auditIdentityAttributes(): array
    {
        $keys = property_exists($this, 'auditIdentity') ? $this->auditIdentity : [];

        return array_intersect_key($this->getAttributes(), array_flip($keys));
    }

    protected function auditableAttributes(array $attributes): array
    {
        $excluded = array_flip(array_merge($this->getHidden(), ['created_at', 'updated_at']));

        return array_diff_key($attributes, $excluded);
    }

    protected function recordActivity(string $actionType, ?array $oldValues, ?array $newValues): void
    {
        if (app()->runningInConsole() || ! Auth::check()) {
            return;
        }

        ActivityLog::create([
            'user_id' => Auth::id(),
            'account_id' => $this->auditAccountId(),
            'agent_id' => $this->auditAgentId(),
            'module_name' => $this->auditModuleName ?? class_basename($this),
            'action_type' => $actionType,
            'route_path' => request()->path(),
            'ip_address' => request()->ip(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    /**
     * The tenant this row belongs to, for the audit UI's account/agent
     * filters — the model IS the Account itself (Account::effectiveModules()'s
     * own account), has its own account_id column, or (platform-level
     * rows like RouteCategory/SystemRoute/PaymentGatewaySetting) falls
     * back to the ACTING user's own account_id (null for a Super Admin
     * actor, which is correct: a platform-wide config change isn't
     * scoped to any one tenant).
     */
    protected function auditAccountId(): ?int
    {
        if ($this instanceof Account) {
            return $this->id;
        }

        if (array_key_exists('account_id', $this->getAttributes())) {
            return $this->account_id;
        }

        return Auth::user()?->account_id;
    }

    /**
     * The Parent Agent that owns auditAccountId()'s account (null for a
     * direct/platform client, an Agent account itself, or a platform-level
     * row) — reuses Account::findCached() so this adds no new N+1 query
     * pattern, same precedent as Account::effectiveModules().
     */
    protected function auditAgentId(): ?int
    {
        $accountId = $this->auditAccountId();

        if ($accountId === null) {
            return null;
        }

        return Account::findCached($accountId)?->agent_id;
    }
}
