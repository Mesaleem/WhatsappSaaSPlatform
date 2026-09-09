<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class MessageTemplate extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    /** Variable Configurator Panel field types the frontend may send per {{token}}. */
    public const VARIABLE_TYPES = ['string', 'number', 'date', 'select'];

    protected $fillable = [
        'account_id',
        'industry_type',
        'title',
        'template_body',
        'status',
        'created_by',
        'variables_schema',
        'is_super_admin_tested',
        'tested_at',
    ];

    protected $with = ['account:id,company_name'];

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
}
