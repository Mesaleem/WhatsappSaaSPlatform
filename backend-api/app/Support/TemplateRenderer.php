<?php

namespace App\Support;

class TemplateRenderer
{
    /**
     * Simple {{token}} variable substitution — deliberately not a real
     * templating engine (Blade::render() on arbitrary stored strings
     * would be a code-execution risk since template bodies are
     * user-authored content, not trusted view files). Used both by the
     * Mail Template Manager's client-side-mirrored preview logic (the
     * frontend reimplements this same {{token}} replacement in
     * JavaScript for zero-latency live preview — see NotificationsPage's
     * docblock) and server-side just before a broadcast email is
     * actually sent, so the two never drift apart in behavior.
     *
     * Unmatched tokens are left as-is (e.g. an unused {{coupon_code}} in
     * a template not given that variable) rather than blanked out, so a
     * misconfigured broadcast is visibly wrong instead of silently
     * losing text.
     *
     * @param array<string, string> $vars
     */
    public static function render(string $html, array $vars): string
    {
        // `{{ name }}` (with spaces) is a variable too: MessageTemplate's field
        // detection and the frontend previews both accept it, so an exact-match
        // replace sent "Hi {{ name }}" while the preview showed "Hi Asha".
        // One pass, so a value that itself contains {{...}} is never re-expanded.
        return (string) preg_replace_callback(
            '/\{\{\s*([^{}]*?)\s*\}\}/',
            static fn (array $m): string => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0],
            $html,
        );
    }
}
