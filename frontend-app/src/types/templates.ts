/**
 * Dynamic Templates & Variables System — mirrors
 * MessageTemplateController / ClientApiKeyController's JSON shapes.
 */

/**
 * Tiered Template Approval Workflow for 3-Tier Hierarchy -- three
 * intermediate review states between "just created" and the
 * pre-existing terminal 'pending' (Super-Admin testing gate). Mirrors
 * MessageTemplate::STATUSES exactly.
 */
export type MessageTemplateStatus =
  | 'pending'
  | 'pending_agent_review'
  | 'pending_admin_review'
  | 'pending_meta_approval'
  | 'approved'
  | 'rejected';

/** Variable Configurator Panel field types — mirrors MessageTemplate::VARIABLE_TYPES. */
export type TemplateVariableType = 'string' | 'number' | 'date' | 'select';

/**
 * Media Templates (QR/Baileys-only) — mirrors MessageTemplate::
 * HEADER_TYPES. 'image'/'document' require header_media_url to be set;
 * see requiresHeaderMedia() and TemplateMessageDispatcher on the backend
 * for how this is actually used at send time, and why it only applies
 * to accounts on the 'qr' engine.
 */
export type TemplateHeaderType = 'text' | 'image' | 'document';

/**
 * One {{token}}'s configured metadata — mirrors the shape the backend
 * stores in message_templates.variables_schema and validates against in
 * SendTemplateMessageRequest. `options` only applies (and is required)
 * when type === 'select'.
 */
export interface TemplateVariableSchemaField {
  key: string;
  label: string;
  type: TemplateVariableType;
  required: boolean;
  options?: string[];
  /**
   * Phase 4 Task 7 — which Meta template component this parameter fills.
   * OPTIONAL and omitted on every schema written before that task, which
   * the backend reads as 'body' (TemplateComponentTranslator::fieldsFor),
   * so existing templates are unaffected. Meaningful only for a
   * Meta-defined template; ignored entirely on the QR path, which renders
   * {{token}} into one flat body string.
   *
   * 'footer' is deliberately NOT a value: Meta accepts no runtime
   * parameter for a template footer, and the backend rejects it as a
   * structural error before any Graph call.
   */
  component?: TemplateVariableComponent;
  /** Required when component === 'button'; mirrors MessageTemplate::BUTTON_SUB_TYPES. */
  button_sub_type?: TemplateButtonSubType;
  /** Required when component === 'button' — the button's own position in the registered Meta template (0-based). Never inferred. */
  button_index?: number;
}

/** Mirrors MessageTemplate::VARIABLE_COMPONENTS. Absent on a field means 'body'. */
export type TemplateVariableComponent = 'body' | 'header' | 'button';

/** Mirrors MessageTemplate::BUTTON_SUB_TYPES. */
export type TemplateButtonSubType = 'url' | 'quick_reply';

export interface MessageTemplate {
  id: number;
  /** Developer API: unique, human-readable lookup key for POST /api/v1/messages/send-template (e.g. PAYMENT_RECEIPT_V1). Null only on a row from before this feature existed and not yet resaved -- see the backend migration's backfill. */
  template_code: string | null;
  account_id: number | null;
  account: { id: number; company_name: string } | null;
  industry_type: string | null;
  title: string;
  template_body: string;
  /** Raw, as configured — null/[] until a Super Admin has used the Variable Configurator Panel on this template. */
  variables_schema: TemplateVariableSchemaField[] | null;
  status: MessageTemplateStatus;
  /** Strict 1-Template-Per-Client & Testing Gate — approve() refuses to run until this is true. Set by POST /admin/templates/{id}/test on a confirmed successful send. */
  is_super_admin_tested: boolean;
  tested_at: string | null;
  /** Tiered Template Approval Workflow -- set by reject() (Agent or Super Admin); null until the template is rejected, or if rejected with no reason given. */
  rejection_reason: string | null;
  /** Media Templates (QR/Baileys-only) -- defaults to 'text' at the DB level for every template, including ones created before this feature existed. */
  header_type: TemplateHeaderType;
  /**
   * Phase 4 Task 5 — the Meta Cloud API half of a template. Null on every
   * QR template and on every template created before this feature.
   */
  language: string | null;
  category: MetaTemplateCategory | null;
  /** The template's name inside the WABA — distinct from `template_code`, which is this platform's own global key. */
  meta_template_name: string | null;
  meta_template_status: MetaTemplateStatus | null;
  /** Only meaningful when header_type is 'image' or 'document'; null otherwise. */
  header_media_url: string | null;
  creator: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

/** GET /api/alerts/message-templates — the trimmed shape a Client Admin's Send Alert dropdown actually needs. */
export interface AvailableTemplate {
  id: number;
  /** Developer API: unique, human-readable lookup key for POST /api/v1/send-message and /api/v1/messages/send-template -- see MessageTemplate.template_code. */
  template_code: string | null;
  title: string;
  industry_type: string | null;
  template_body: string;
  /** {{variable}} names found in template_body, in first-appearance order — drives the dynamic form fields. */
  variables: string[];
  /** Always resolved (server-side effectiveVariablesSchema()) — one entry per `variables` name, in the same order. */
  variables_schema: TemplateVariableSchemaField[];
}

/** Meta's own template classification — mirrors MessageTemplate::META_CATEGORIES. */
export type MetaTemplateCategory = 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';

/** Meta's own review verdict — mirrors MessageTemplate::META_STATUSES. Separate from this platform's approval `status`. */
export type MetaTemplateStatus = 'PENDING' | 'APPROVED' | 'REJECTED' | 'PAUSED' | 'DISABLED';

export interface SaveMessageTemplatePayload {
  title: string;
  /** Developer API: unique, human-readable lookup key. Omit or send empty/null to auto-generate one from `title`. */
  template_code?: string | null;
  industry_type?: string | null;
  template_body: string;
  account_id?: number | null;
  variables_schema?: TemplateVariableSchemaField[];
  /**
   * Phase 4 Task 5 — Meta-only fields. The backend rejects any of these
   * for an account not on the Meta provider, and requires `language`
   * whenever `meta_template_name` is set.
   */
  language?: string | null;
  category?: MetaTemplateCategory | null;
  meta_template_name?: string | null;
  meta_template_status?: MetaTemplateStatus | null;
  /** Media Templates (QR/Baileys-only). Omit both (or send header_type: 'text') for a plain text template -- unchanged, existing behavior. */
  header_type?: TemplateHeaderType;
  header_media_url?: string | null;
}

/**
 * POST /api/alerts/message-templates/request -- Tiered Template Approval
 * Workflow for 3-Tier Hierarchy, Rules 1 & 2. A plain Client Admin/User
 * submitting a request for their OWN (resolved server-side) account --
 * no account_id field here, unlike SaveMessageTemplatePayload above,
 * since a Client can only ever submit for themselves.
 */
export interface SubmitTemplateRequestPayload {
  title: string;
  industry_type?: string | null;
  template_body: string;
  variables_schema?: TemplateVariableSchemaField[];
  /** Media Templates (QR/Baileys-only) -- e.g. requesting a bill/invoice sent as a PDF. Omit both for a plain text request -- unchanged, existing behavior. */
  header_type?: TemplateHeaderType;
  header_media_url?: string | null;
}

/**
 * GET /api/alerts/message-templates/mine -- the trimmed shape for a
 * plain Client Admin/User's "My Templates" list: unlike AvailableTemplate
 * above (approved-only, resolved variables/schema for the send form),
 * this covers every status so the caller can see what they've requested
 * and whether it's still pending, approved, or rejected (and why).
 */
export interface MyTemplateSummary {
  id: number;
  title: string;
  /** Developer API: unique, human-readable lookup key -- see MessageTemplate.template_code. */
  template_code: string | null;
  industry_type: string | null;
  status: MessageTemplateStatus;
  rejection_reason: string | null;
  created_at: string;
}

export interface SendTemplateMessagePayload {
  template_id: number;
  recipient_phone: string;
  variables: Record<string, string>;
  /**
   * Media Templates (send-time override, QR/Baileys-only) -- ONE
   * optional key, no separate type field alongside it (the backend
   * infers image vs document from the URL's extension). Omit it, or
   * leave the template's own header_media_url unset, and the send is
   * plain text -- identical to before this feature. An unreachable or
   * malformed URL never fails the send; it just falls back to text.
   */
  media_url?: string;
}

export interface SendTemplateMessageResponse {
  message: string;
  rendered_message: string;
}

/**
 * Anti-Spam Bulk Dispatch -- POST /api/alerts/send-template-bulk. One
 * call replaces the old per-recipient client-side loop for the
 * Individual tab's multi-recipient case: the backend enqueues one
 * rate-limited, randomly-delayed background job per phone number and
 * returns immediately, before any of them have actually sent (see
 * SendAlertPage.tsx's handleSubmit and the backend
 * MessageTemplateController::sendBulk() docblock).
 */
export interface SendBulkTemplateMessagePayload {
  template_id: number;
  recipient_phones: string[];
  variables: Record<string, string>;
  /** Same send-time media override contract as SendTemplateMessagePayload.media_url. */
  media_url?: string;
}

export interface SendBulkTemplateMessageResponse {
  message: string;
  queued_count: number;
  batch_count: number;
  /** Wall-clock seconds the anti-spam pacing is expected to take to fully drain this batch. */
  estimated_duration_seconds: number;
}

/**
 * GET /api/alerts/bulk-cooldown-status -- Strict Bulk Messaging Limit
 * & Tier-Based Cooldown. Lets the Send Alert page show its cooldown
 * countdown banner as soon as the page loads, not only after a
 * blocked send attempt.
 */
export interface BulkCooldownStatusResponse {
  on_cooldown: boolean;
  cooldown_remaining_seconds: number;
}

/** POST /api/admin/templates/{id}/test — Super Admin test-fire, always through Account::platformDevice(). */
export interface TestTemplateMessagePayload {
  recipient_phone: string;
  variables: Record<string, string>;
}

export interface TestTemplateMessageResponse {
  message: string;
  rendered_message: string;
  data: MessageTemplate;
}

/** GET /api/account/api-key */
export interface ClientApiKey {
  id: number;
  key_prefix: string;
  created_at: string;
  last_used_at: string | null;
}

/** POST /api/account/api-key/regenerate */
export interface RegenerateClientApiKeyResponse {
  message: string;
  /** Shown exactly ONCE — never retrievable again after this response. */
  plain_text_key: string;
  data: ClientApiKey;
}
