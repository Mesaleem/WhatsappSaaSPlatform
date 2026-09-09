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
