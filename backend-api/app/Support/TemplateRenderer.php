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
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($html, $replacements);
    }
}
