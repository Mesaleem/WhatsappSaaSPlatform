/**
 * Dynamic Templates & Variables System — mirrors
 * MessageTemplateController / ClientApiKeyController's JSON shapes.
 */

export type MessageTemplateStatus = 'pending' | 'approved' | 'rejected';

/** Variable Configurator Panel field types — mirrors MessageTemplate::VARIABLE_TYPES. */
export type TemplateVariableType = 'string' | 'number' | 'date' | 'select';

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
}

export interface SendTemplateMessagePayload {
  template_id: number;
  recipient_phone: string;
  variables: Record<string, string>;
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
