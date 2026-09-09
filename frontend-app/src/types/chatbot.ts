/**
 * Module 10 — Interactive Chatbot Engine & Keyword Auto-Responder Rules
 * type definitions. Mirrors ChatbotRuleController / ChatbotLogController
 * and ChatbotEngineService's documented response_payload shapes.
 */

export type ChatbotMatchType = 'exact' | 'contains' | 'starts_with' | 'regex' | 'fallback';
export const MATCH_TYPES: ChatbotMatchType[] = ['exact', 'contains', 'starts_with', 'regex', 'fallback'];

export type ChatbotResponseType = 'text' | 'media' | 'interactive';
export const RESPONSE_TYPES: ChatbotResponseType[] = ['text', 'media', 'interactive'];

export type ChatbotMediaType = 'image' | 'document' | 'video' | 'audio';
export const MEDIA_TYPES: ChatbotMediaType[] = ['image', 'document', 'video', 'audio'];

export type ChatbotInteractiveType = 'button' | 'list';

/** response_payload when response_type === 'text'. */
export interface TextResponsePayload {
  text: string;
}

/** response_payload when response_type === 'media'. */
export interface MediaResponsePayload {
  media_type: ChatbotMediaType;
  url: string;
  caption?: string;
  filename?: string;
}

export interface InteractiveButton {
  id: string;
  title: string;
}

export interface InteractiveListRow {
  id: string;
  title: string;
  description?: string;
}

export interface InteractiveListSection {
  title: string;
  rows: InteractiveListRow[];
}

/** response_payload when response_type === 'interactive'. */
export interface InteractiveResponsePayload {
  body: string;
  interactive_type: ChatbotInteractiveType;
  buttons?: InteractiveButton[];
  button_text?: string;
  sections?: InteractiveListSection[];
}

export type ChatbotResponsePayload = TextResponsePayload | MediaResponsePayload | InteractiveResponsePayload;

export interface ChatbotRule {
  id: number;
  account_id: number;
  name: string;
  match_type: ChatbotMatchType;
  keywords: string[];
  response_type: ChatbotResponseType;
  response_payload: Record<string, unknown>;
  /** LOWER value is evaluated FIRST. */
  priority: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

/** POST /api/chatbot/rules, PUT /api/chatbot/rules/{id} */
export interface SaveChatbotRulePayload {
  name: string;
  match_type: ChatbotMatchType;
  keywords: string[];
  response_type: ChatbotResponseType;
  response_payload: Record<string, unknown>;
  priority?: number;
  is_active?: boolean;
}

export type ChatbotLogStatus = 'replied' | 'ignored' | 'failed';

export interface ChatbotLog {
  id: number;
  account_id: number;
  chatbot_rule_id: number | null;
  chatbot_rule?: { id: number; name: string } | null;
  sender_phone: string;
  incoming_message: string;
  reply_sent: string | null;
  status: ChatbotLogStatus;
  created_at: string;
  updated_at: string;
}

export interface ChatbotLogFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: ChatbotLogStatus | '';
  from?: string;
  to?: string;
}
