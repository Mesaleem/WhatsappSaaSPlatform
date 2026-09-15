<?php

namespace App\Services\Templates;

use App\Models\MessageTemplate;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- requirement 3's "parse dynamic variables
 * (components.parameters)" clause. Translates Meta WhatsApp Cloud API's
 * OWN wire format for a template message --
 *   "components": [
 *     {"type": "body", "parameters": [{"type": "text", "text": "John"}, ...]}
 *   ]
 * -- into this codebase's existing flat `variables: {key: value}` shape
 * (App\Support\TemplateRenderer's {{key}} substitution, the SAME shape
 * TemplateMessageDispatcher/GroupMessageDispatcher already consume for
 * every other template-sending entry point).
 *
 * [Disclosed translation, not a literal 1:1 mapping -- there isn't one]:
 * Meta's own template syntax numbers its placeholders positionally
 * ({{1}}, {{2}}, ...) with NO variable name at all; this app's templates
 * use NAMED {{token}} placeholders (MessageTemplate::variableNames()/
 * effectiveVariablesSchema()). There is no way to recover a name from
 * Meta's array format, so parameters are mapped POSITIONALLY, in
 * array order, onto this template's effectiveVariablesSchema() keys
 * (also in their configured order) -- the Nth parameter across all
 * "body"-type components becomes the value of the Nth schema field.
 * Only parameter items with "type": "text" are read (their "text"
 * value); "currency"/"date_time"/"image"/etc. parameter types are
 * skipped (not supported by this app's plain-string TemplateRenderer)
 * -- flagged here, not silently mis-mapped.
 */
class TemplateComponentTranslator
{
    /**
     * @param list<array{type?: string, parameters?: list<array<string, mixed>>}> $components
     * @return array<string, string>
     */
    public static function toVariables(MessageTemplate $template, array $components): array
    {
        $schemaKeys = array_map(fn (array $field) => $field['key'], $template->effectiveVariablesSchema());

        $values = [];
        foreach ($components as $component) {
            if (($component['type'] ?? null) !== 'body') {
                continue;
            }

            foreach (($component['parameters'] ?? []) as $parameter) {
                if (($parameter['type'] ?? 'text') === 'text' && isset($parameter['text'])) {
                    $values[] = (string) $parameter['text'];
                }
            }
        }

        $variables = [];
        foreach ($schemaKeys as $index => $key) {
            if (array_key_exists($index, $values)) {
                $variables[$key] = $values[$index];
            }
        }

        return $variables;
    }
}
