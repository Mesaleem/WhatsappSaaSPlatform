/**
 * Dynamic System & Mail Configuration type definitions. Mirrors
 * MailSettingsController's JSON shape.
 */

export type MailEncryption = 'tls' | 'ssl';

/** GET /api/admin/mail-settings — password is NEVER returned, only password_set. */
export interface MailSettings {
  mailer: 'smtp';
  host: string | null;
  port: number | null;
  username: string | null;
  password_set: boolean;
  encryption: MailEncryption | null;
  from_address: string | null;
  from_name: string | null;
  is_configured: boolean;
}

/**
 * PUT /api/admin/mail-settings — partial update; omitting `password`
 * leaves the previously saved one untouched.
 */
export interface UpdateMailSettingsPayload {
  mailer?: 'smtp';
  host?: string | null;
  port?: number | null;
  username?: string | null;
  password?: string | null;
  encryption?: MailEncryption | null;
  from_address?: string | null;
  from_name?: string | null;
}

/** POST /api/admin/mail-settings/test — same optional fields, merged onto the saved config for one test send. */
export type SendTestMailPayload = UpdateMailSettingsPayload;

export interface SendTestMailResponse {
  message: string;
}
