<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Traits\LogsActivity;

class MessageTemplate extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Template Manager';
    /**
     * Tiered Template Approval Workflow for 3-Tier Hierarchy -- three
     * intermediate review states inserted between "just created" and
     * the pre-existing terminal 'pending' (Super-Admin testing gate).
     * See TemplateService's docblock for the full routing design and
     * the migration that widened this column's DB-level constraint.
     */
    public const STATUSES = [
        'pending',
        'pending_agent_review',
        'pending_admin_review',
        'pending_meta_approval',
        'approved',
        'rejected',
    ];

    /** Group Messaging Step 1 — matches the lowercase convention STATUSES above already uses on this same table. */
    public const HEADER_TYPES = ['text', 'image', 'document'];

    /** Variable Configurator Panel field types the frontend may send per {{token}}. */
    public const VARIABLE_TYPES = ['string', 'number', 'date', 'select'];

    /**
     * Phase 4 Task 7 -- which Meta template component a variable supplies
     * a parameter for.
     *
     * This is a NARROW, ADDITIVE extension of the existing per-field
     * schema object, not a schema redesign: the key is optional and every
     * field that omits it is treated as 'body', so every variables_schema
     * written before this task keeps its exact previous meaning and
     * produces a byte-identical payload.
     *
     * 'footer' is deliberately absent: Meta templates accept NO runtime
     * parameters for a footer, so a field declaring one is a structural
     * error rather than something to silently drop (see
     * TemplateComponentTranslator::componentStructureErrors()).
     */
    public const VARIABLE_COMPONENTS = ['body', 'header', 'button'];

    /** Meta's own button component sub-types that accept a runtime parameter. */
    public const BUTTON_SUB_TYPES = ['url', 'quick_reply'];

    /**
     * Phase 4 Task 5 -- Meta's own template classification. UPPERCASE to
     * match the Cloud API's wire format exactly, unlike STATUSES above
     * which is this platform's own lowercase workflow vocabulary.
     */
    public const META_CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    /**
     * Meta's own review verdict for a submitted template, kept SEPARATE
     * from this platform's `status`: a template can be 'approved' here
     * (an admin cleared it for use) while Meta still has it PENDING, and
     * Meta can later PAUSE or DISABLE one that was already APPROVED.
     */
    public const META_STATUSES = ['PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED'];

    protected $fillable = [
        'account_id',
        // Developer API -- human-readable, globally-unique lookup key for
        // POST /api/v1/messages/send-template (see the
        // add_template_code_to_message_templates_table migration and
        // generateTemplateCode() below).
        'template_code',
        'industry_type',
        'title',
        'template_body',
        // Phase 4 Task 5 -- the Meta-specific half of a Cloud API
        // template. All nullable; a QR template leaves every one of them
        // null and behaves exactly as before.
        'language',
        'category',
        'meta_template_name',
        'meta_template_status',
        'status',
        'created_by',
        'variables_schema',
        'is_super_admin_tested',
        'tested_at',
        // Group Messaging Step 1 — header_type defaults to 'text' at
        // the DB level; rejection_reason is nullable and not yet written by
        // any controller (MessageTemplateController::reject() is unchanged).
        'header_type',
        'rejection_reason',
        // Media Templates (QR/Baileys-only) — see requiresHeaderMedia()
        // below and TemplateMessageDispatcher for how this is actually
        // used at send time.
        'header_media_url',
    ];

    /**
     * agent_id/account_type added by the Tiered Template Approval
     * Workflow feature -- TemplateService and MessageTemplateController's
     * Agent-ownership scoping both read $template->account->agent_id, so
     * this eager-load's column restriction must include it or that check
     * silently sees null on every row.
     */
    protected $with = ['account:id,company_name,agent_id,account_type'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'variables_schema' => 'array',
            'is_super_admin_tested' => 'boolean',
            'tested_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A template usable by $accountId right now: approved, and either
     * global (account_id null) or scoped to this exact account. This is
     * the single rule both the internal "available templates" dropdown
     * (Send Alert page) and the external /v1/messages/send-template
     * endpoint enforce — see TemplateMessageDispatcher, which re-checks
     * this same condition server-side rather than trusting a client-sent
     * template_id at face value.
     */
    public function scopeApprovedFor(Builder $query, ?int $accountId): Builder
    {
        return $query->where('status', 'approved')
            ->where(function (Builder $q) use ($accountId) {
                $q->whereNull('account_id');
                if ($accountId) {
                    $q->orWhere('account_id', $accountId);
                }
            });
    }

    /**
     * Media Templates (QR/Baileys-only) — true when this template is
     * configured to carry an image or document header AND actually has a
     * media URL set. Deliberately checks BOTH: header_type alone (e.g. a
     * template switched back to 'text' without clearing an old
     * header_media_url) must not cause a media send. Used by
     * TemplateMessageDispatcher to decide whether to build a media
     * payload for BaileysDriver or send plain text — see that class for
     * why this only ever applies to the 'qr' engine.
     */
    public function requiresHeaderMedia(): bool
    {
        return in_array($this->header_type, ['image', 'document'], true) && filled($this->header_media_url);
    }

    /**
     * Unique {{variable}} names in template_body, in first-appearance
     * order — the same {{token}} syntax TemplateRenderer::render()
     * substitutes. Drives both the frontend's dynamic form-field
     * generation and this backend's "did the caller supply every
     * variable the template needs" validation.
     *
     * @return list<string>
     */
    public function variableNames(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->template_body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * The Variable Configurator Panel schema this template actually
     * validates against right now. If a Super Admin has configured one
     * (variables_schema non-empty), that is authoritative — it carries
     * per-field type/required/options. Otherwise, auto-derive a schema
     * straight from the template's {{tokens}} (type "string", required
     * true for each), so a template saved before this feature existed —
     * or one whose author never opened the Configurator Panel — still
     * validates correctly instead of silently accepting anything.
     *
     * SendTemplateMessageRequest and TemplateMessageDispatcher both call
     * this ONE method — there is no second, divergent "legacy" check
     * anywhere else.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>
     */
    public function effectiveVariablesSchema(): array
    {
        $schema = $this->variables_schema;

        if (is_array($schema) && count($schema) > 0) {
            return $schema;
        }

        return array_map(
            fn (string $key) => [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
                'type' => 'string',
                'required' => true,
            ],
            $this->variableNames(),
        );
    }

    /**
     * Dynamic Template Engine — the one place per-variable type/required
     * validation rules are built from an effectiveVariablesSchema() array.
     * Shared by SendTemplateMessageRequest::rules() (the live client send
     * path) and MessageTemplateController::test() (the Super Admin
     * pre-approval test-fire path) so a variable can never be validated
     * differently by the two entry points — extracted verbatim from
     * SendTemplateMessageRequest's original inline loop, not rewritten.
     *
     * @param list<array{key: string, label: string, type: string, required: bool, options?: list<string>}> $schema
     * @return array<string, array<int, mixed>>
     */
    public static function variableValidationRules(array $schema, string $fieldPrefix = 'variables'): array
    {
        $rules = [];

        foreach ($schema as $field) {
            $key = $fieldPrefix.'.'.$field['key'];
            $fieldRules = [];

            $fieldRules[] = ! empty($field['required']) ? 'required' : 'nullable';

            $fieldRules[] = match ($field['type'] ?? 'string') {
                'number' => 'numeric',
                'date' => 'date',
                'select' => Rule::in($field['options'] ?? []),
                default => 'string',
            };

            if (($field['type'] ?? 'string') !== 'select') {
                $fieldRules[] = 'max:500';
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Developer API -- auto-suggest/auto-generate a unique,
     * human-readable template_code from a title. Produces the same
     * UPPER_SNAKE_CASE slug shape the
     * add_template_code_to_message_templates_table migration backfills
     * existing rows with, so a template created going forward through
     * MessageTemplateController::store()/update()/submitRequest() can
     * never drift into a different slug convention than that one-time
     * backfill used. Falls back to TMP_{n} when the title produces an
     * empty slug, and disambiguates any collision (including against
     * the TMP_ fallback) with a numeric suffix.
     *
     * $ignoreId excludes a row from the uniqueness check -- pass the
     * template's own id when regenerating a code for an existing
     * template (update()'s "clear the code, re-derive from the current
     * title" path), same as Rule::unique(...)->ignore($id) does for the
     * client-supplied case.
     */
    public static function generateTemplateCode(string $title, ?int $ignoreId = null): string
    {
        $base = strtoupper((string) Str::of($title)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_'));

        if ($base === '') {
            $base = 'TMP_'.(((int) static::max('id')) + 1);
        }

        $code = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('template_code', $code)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $code = $base.'_'.$suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * Phase 4 Task 5 -- true once this template carries a Meta-side
     * definition (a template registered in a WABA). Used to keep such a
     * template usable only by an account actually on the Meta provider:
     * a QR account has no WABA, so the name would resolve to nothing.
     */
    public function isMetaDefined(): bool
    {
        return filled($this->meta_template_name);
    }

    /**
     * {{tokens}} present in template_body that the CONFIGURED
     * variables_schema does not declare.
     *
     * This is the structural check requirement 6 asks for, and it closes a
     * real, customer-visible hole: effectiveVariablesSchema() only
     * auto-derives from the body when variables_schema is EMPTY. Once a
     * schema is configured it is authoritative, so any body token missing
     * from it is never collected, never required, and never substituted --
     * TemplateRenderer deliberately leaves an unmatched token untouched,
     * so the recipient receives the literal text "{{amount}}".
     *
     * Returns [] when no schema is configured (nothing can be undeclared
     * if everything is auto-derived).
     *
     * @return list<string>
     */
    public function undeclaredVariableNames(): array
    {
        $schema = $this->variables_schema;

        if (! is_array($schema) || count($schema) === 0) {
            return [];
        }

        $declared = array_map(static fn (array $field) => $field['key'] ?? null, $schema);

        return array_values(array_diff($this->variableNames(), array_filter($declared)));
    }

    /**
     * Same structural check, for a body/schema pair that has not been
     * persisted yet -- used by MessageTemplateController at validation
     * time, before the row exists.
     *
     * @param array<int, array<string, mixed>>|null $schema
     * @return list<string>
     */
    public static function undeclaredVariableNamesFor(string $templateBody, ?array $schema): array
    {
        if (! is_array($schema) || count($schema) === 0) {
            return [];
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $templateBody, $matches);
        $tokens = array_values(array_unique($matches[1] ?? []));
        $declared = array_filter(array_map(static fn (array $field) => $field['key'] ?? null, $schema));

        return array_values(array_diff($tokens, $declared));
    }
}
