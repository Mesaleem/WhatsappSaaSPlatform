<?php

namespace App\Services\Auth;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Account emails (signup code, login alert, password reset / changed) sent
 * through the platform's Mail Settings, like the subscription reminders.
 * Every email shares one simple, branded layout.
 */
class AccountMailer
{
    private const MAILER_NAME = 'account_mail';

    public function isConfigured(): bool
    {
        $setting = MailSetting::currentCached();

        return (bool) ($setting && $setting->isConfigured());
    }

    public function brand(): string
    {
        return MailSetting::currentCached()?->from_name ?: 'WapHub';
    }

    /** The frontend address links in emails point to. */
    public function frontendUrl(string $path = ''): string
    {
        return rtrim((string) (config('services.frontend.url') ?: config('app.url')), '/').$path;
    }

    /**
     * Send one email. $body is trusted HTML built by the caller (escape any user data with e()).
     * Returns false when mail is not configured or sending failed; never throws.
     */
    public function send(string $to, string $subject, string $heading, string $body, ?array $button = null): bool
    {
        $setting = MailSetting::currentCached();
        if (! $setting || ! $setting->isConfigured()) {
            Log::warning('Account email not sent: mail settings are not configured.', ['subject' => $subject]);

            return false;
        }

        $brand = $this->brand();
        $html = '<div style="background:#f1f5f9;padding:32px 12px;font-family:Arial,Helvetica,sans-serif">'
            .'<div style="max-width:520px;margin:auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
            .'<div style="background:linear-gradient(135deg,#10b981,#0f766e);padding:20px 28px;color:#ffffff;font-size:20px;font-weight:bold">'.e($brand).'</div>'
            .'<div style="padding:28px;color:#0f172a;font-size:14px;line-height:1.6">'
            .'<h2 style="margin:0 0 12px;font-size:20px;color:#0f172a">'.e($heading).'</h2>'
            .$body
            .($button ? '<p style="margin:26px 0"><a href="'.e($button['url']).'" style="background:#059669;color:#ffffff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:bold;display:inline-block">'.e($button['label']).'</a></p>' : '')
            .'</div>'
            .'<div style="padding:16px 28px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px">This is an automatic security email from '.e($brand).'. Please do not reply.</div>'
            .'</div></div>';

        try {
            Config::set('mail.mailers.'.self::MAILER_NAME, $setting->toMailerConfig());
            Mail::mailer(self::MAILER_NAME)->html($html, function ($message) use ($to, $setting, $brand, $subject) {
                $message->to($to)->from($setting->from_address, $brand)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Account email failed.', ['subject' => $subject, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** "Chrome on Windows" from a User-Agent string; good enough for a security email. */
    public static function describeDevice(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Brave') => 'Brave',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'A browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'an unknown device',
        };

        return "{$browser} on {$os}";
    }
}
