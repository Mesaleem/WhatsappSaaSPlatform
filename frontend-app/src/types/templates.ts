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
}

export interface MessageTemplate {
  id: number;
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
  /** Only meaningful when header_type is 'image' or 'document'; null otherwise. */
  header_media_url: string | null;
  creator: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

/** GET /api/alerts/message-templates — the trimmed shape a Client Admin's Send Alert dropdown actually needs. */
export interface AvailableTemplate {
  id: number;
  title: string;
  industry_type: string | null;
  template_body: string;
  /** {{variable}} names found in template_body, in first-appearance order — drives the dynamic form fields. */
  variables: string[];
  /** Always resolved (server-side effectiveVariablesSchema()) — one entry per `variables` name, in the same order. */
  variables_schema: TemplateVariableSchemaField[];
}

export interface SaveMessageTemplatePayload {
  title: string;
  industry_type?: string | null;
  template_body: string;
  account_id?: number | null;
  variables_schema?: TemplateVariableSchemaField[];
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
