import { AxiosError } from 'axios';
import type { ApiErrorResponse } from '../types/auth';

/**
 * All-Module Form & API Validation Audit — every Laravel validation
 * failure this app's API returns is {message, errors: {field: [msg...]}},
 * but every page previously read only `.message`, which is Laravel's own
 * generic "The given data was invalid." sentence whenever more than one
 * field fails at once — the user was never told WHICH field(s). This
 * reads the field-level `errors` object when present and joins every
 * message into one readable string; falls back to `.message`, then the
 * caller-supplied fallback, so it's a drop-in replacement for every
 * page's previous local `extractMessage()`/inline `?? fallback` helper.
 */
export function extractErrorMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  const data = axiosErr.response?.data;
  const errors = data?.errors;

  if (errors && Object.keys(errors).length > 0) {
    return Object.values(errors).flat().join(' ');
  }

  return data?.message ?? fallback;
}

/**
 * Per-field messages from the same {errors: {field: [msg...]}} shape, for
 * a form that wants to show each bad field inline next to its input
 * rather than (or in addition to) one combined banner. Returns {} when
 * the error carries no field-level detail (e.g. a plain 404/403/500).
 */
export function extractFieldErrors(err: unknown): Record<string, string> {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  const errors = axiosErr.response?.data?.errors;

  if (!errors) return {};

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]));
}

/**
 * `error_code` from the same envelope (e.g. 'WHATSAPP_DISCONNECTED') —
 * lets a caller branch on a specific failure reason instead of pattern-
 * matching `.message` text. Undefined for a plain validation 422 or any
 * error response that doesn't set one.
 */
export function extractErrorCode(err: unknown): string | undefined {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.error_code;
}

/**
 * Developer API Key Management UI — one place that turns any rejected
 * request into everything a form needs to render: a safe, actionable
 * sentence, per-field messages, and the HTTP status.
 *
 * Built on the two helpers above rather than beside them: `fieldErrors`
 * is exactly `extractFieldErrors()`, and the 4xx branch defers to the
 * same `{message, errors}` envelope `extractErrorMessage()` reads.
 *
 * Which statuses get a canned sentence and which get the server's own,
 * and why:
 *   401 / 429 / 5xx -> ALWAYS canned. A 5xx `message` in Laravel is the
 *     raw exception text when APP_DEBUG is on (SQL, file paths, class
 *     names), so it must never reach the UI; 401/429 bodies carry no
 *     detail worth showing over a clearer instruction.
 *   403 / 404 / 409 / 422 -> prefer the server's own message. This
 *     codebase returns deliberately written, non-leaking sentences for
 *     these (e.g. ApiKeyController::regenerateSecret()'s "This API key
 *     has been revoked and cannot be given a new secret.", or
 *     EnsureModuleEnabledMiddleware's MODULE_DISABLED copy), and those
 *     are more useful than anything generic — with a canned fallback
 *     when a given endpoint sends none.
 *
 * No new error codes are invented here: `status` and `error_code` are
 * passed through untouched for a caller that wants to branch further.
 */
export interface ApiErrorDescription {
  /** null when the request never reached the server (network/CORS/abort). */
  status: number | null;
  /** Safe to render directly. Never contains an exception message or stack. */
  message: string;
  /** From a 422's `errors` object — map these onto the matching inputs. */
  fieldErrors: Record<string, string>;
  error_code?: string;
  /**
   * From the `X-Request-Id` response header, when the endpoint sets one.
   * LogApiRequestMiddleware sets it on the external /api/v1/* Developer
   * API only, so it is normally absent for this SPA's own /api/developer/*
   * calls — surfaced when present so a 5xx can be quoted to support.
   */
  requestId?: string;
}

const GENERIC_STATUS_MESSAGES: Record<number, string> = {
  401: 'Your session has expired. Please sign in again.',
  403: 'You do not have permission to perform this action.',
  404: 'That record could not be found. It may have been removed — refresh and try again.',
  409: 'That action conflicts with the current state of this record. Refresh and try again.',
  422: 'Please correct the highlighted fields and try again.',
  429: 'Too many requests. Please wait a moment and try again.',
};

const SERVER_MESSAGE_STATUSES = new Set([403, 404, 409, 422]);

export function describeApiError(err: unknown, fallback: string): ApiErrorDescription {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  const response = axiosErr?.response;
  const status = response?.status ?? null;
  const data = response?.data;
  const fieldErrors = extractFieldErrors(err);

  const rawRequestId = response?.headers?.['x-request-id'];
  const requestId = typeof rawRequestId === 'string' && rawRequestId !== '' ? rawRequestId : undefined;

  let message: string;

  if (status === null) {
    message = 'Could not reach the server. Check your connection and try again.';
  } else if (status >= 500) {
    // Deliberately ignores data.message — see the docblock above.
    message = requestId
      ? `Something went wrong. Please try again. (Reference: ${requestId})`
      : 'Something went wrong. Please try again.';
  } else if (SERVER_MESSAGE_STATUSES.has(status)) {
    const serverMessage = data?.message;
    // Laravel's own placeholder for a multi-field 422 says nothing useful;
    // the per-field messages are already rendered inline, so fall through
    // to the clearer instruction instead of repeating it in the banner.
    const isLaravelValidationPlaceholder =
      status === 422 && (!serverMessage || serverMessage === 'The given data was invalid.');

    message = isLaravelValidationPlaceholder
      ? GENERIC_STATUS_MESSAGES[422]
      : (serverMessage ?? GENERIC_STATUS_MESSAGES[status] ?? fallback);
  } else {
    message = GENERIC_STATUS_MESSAGES[status] ?? data?.message ?? fallback;
  }

  return {
    status,
    message,
    fieldErrors,
    error_code: data?.error_code,
    requestId,
  };
}

/**
 * `cooldown_remaining_seconds` from a 429 BULK_COOLDOWN_ACTIVE response
 * (Strict Bulk Messaging Limit & Tier-Based Cooldown) — lets a caller
 * update its own cooldown countdown state immediately from a blocked
 * send attempt, without waiting for the next status poll. Undefined for
 * any other error response.
 */
export function extractCooldownRemainingSeconds(err: unknown): number | undefined {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.cooldown_remaining_seconds;
}
