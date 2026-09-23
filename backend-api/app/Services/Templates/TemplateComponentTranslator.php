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
        // Phase 4 Task 7: body parameters map onto the BODY fields only.
        // Every schema written before that task omits `component`, so
        // bodyFields() returns all of them and this is unchanged.
        $schemaKeys = array_map(
            static fn (array $field) => $field['key'],
            self::fieldsFor($template, 'body'),
        );

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

    /**
     * The schema fields that supply a parameter for one Meta component.
     * A field with no `component` key is a BODY field -- the default that
     * keeps every pre-Task-7 schema behaving identically.
     *
     * @return list<array<string, mixed>>
     */
    public static function fieldsFor(MessageTemplate $template, string $component): array
    {
        return array_values(array_filter(
            $template->effectiveVariablesSchema(),
            static fn (array $field) => ($field['component'] ?? 'body') === $component,
        ));
    }

    /**
     * Phase 4 Task 6/7 -- the INVERSE of toVariables(): turns this app's
     * flat, NAMED `variables: {key: value}` shape into Meta's own
     * component wire format for an outbound template send.
     *
     * Emitted in Meta's conventional order -- header, body, then buttons
     * ascending by index:
     *
     *   [{"type":"header","parameters":[{"type":"text","text":"..."}]},
     *    {"type":"body","parameters":[{"type":"text","text":"John"}, ...]},
     *    {"type":"button","sub_type":"url","index":"0","parameters":[...]}]
     *
     * Ordering WITHIN body is what makes the named->positional mapping
     * correct, and it is the same ordering toVariables() reads back:
     * effectiveVariablesSchema() in its configured order, filtered to the
     * body fields. The Nth body field is Meta's Nth positional parameter.
     *
     * A media header is driven entirely by the template's OWN, existing
     * fields (header_type + header_media_url / a send-time override) --
     * no schema variable and no new media storage is involved.
     *
     * Returns [] when the template supplies no parameters at all, so the
     * caller can omit `components` -- Meta rejects an empty array on a
     * template that takes none.
     *
     * @param array<string, string> $variables
     * @return list<array<string, mixed>>
     */
    public static function toComponents(MessageTemplate $template, array $variables, ?string $headerMediaUrl = null): array
    {
        $components = [];

        // ---- HEADER -------------------------------------------------
        // Media header first: a Meta template header is either media or
        // text, never both, so a configured media header wins.
        $mediaComponent = self::mediaHeaderComponent($template, $headerMediaUrl);

        if ($mediaComponent !== null) {
            $components[] = $mediaComponent;
        } else {
            $headerParameters = self::textParameters(self::fieldsFor($template, 'header'), $variables);

            if ($headerParameters !== []) {
                $components[] = ['type' => 'header', 'parameters' => $headerParameters];
            }
        }

        // ---- BODY ---------------------------------------------------
        $bodyParameters = self::textParameters(self::fieldsFor($template, 'body'), $variables);

        if ($bodyParameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
        }

        // ---- BUTTONS ------------------------------------------------
        // Grouped by the index the SCHEMA declares -- never a hardcoded
        // position -- and emitted ascending so the payload is stable.
        $buttonFields = self::fieldsFor($template, 'button');
        $byIndex = [];

        foreach ($buttonFields as $field) {
            $byIndex[(int) ($field['button_index'] ?? 0)][] = $field;
        }

        ksort($byIndex);

        foreach ($byIndex as $index => $fields) {
            $parameters = self::textParameters($fields, $variables);

            if ($parameters === []) {
                continue;
            }

            $components[] = [
                'type' => 'button',
                // Meta sends index as a string in its own documentation.
                'sub_type' => (string) ($fields[0]['button_sub_type'] ?? 'url'),
                'index' => (string) $index,
                'parameters' => $parameters,
            ];
        }

        return $components;
    }

    /**
     * A Meta header media parameter, built from the template's OWN
     * existing fields. Returns null unless this template actually declares
     * a media header and a URL is available.
     *
     * [Inference, flagged]: Meta's documented runtime form for a media
     * header parameter is a public `link` (the alternative is a media `id`
     * from Meta's resumable upload API, which would require the media
     * storage/registration system this task explicitly excludes). The link
     * form is used here precisely because it needs nothing new.
     *
     * @return array<string, mixed>|null
     */
    private static function mediaHeaderComponent(MessageTemplate $template, ?string $headerMediaUrl): ?array
    {
        if (! in_array($template->header_type, ['image', 'document'], true)) {
            return null;
        }

        $url = filled($headerMediaUrl) ? $headerMediaUrl : $template->header_media_url;

        if (! filled($url) || ! preg_match('#^https?://#i', (string) $url)) {
            return null;
        }

        $type = $template->header_type;
        $media = ['link' => (string) $url];

        if ($type === 'document') {
            $path = parse_url((string) $url, PHP_URL_PATH) ?: '';
            $media['filename'] = basename($path) ?: 'document';
        }

        return [
            'type' => 'header',
            'parameters' => [[
                'type' => $type,
                $type => $media,
            ]],
        ];
    }

    /**
     * The media link an already-built component set attaches, if any --
     * read back out of the payload rather than recomputed, so the audit
     * log can never disagree with what was actually sent. null when this
     * send carries no media header.
     *
     * @param list<array<string, mixed>> $components
     */
    public static function headerMediaUrlIn(array $components): ?string
    {
        foreach ($components as $component) {
            if (($component['type'] ?? null) !== 'header') {
                continue;
            }

            foreach (($component['parameters'] ?? []) as $parameter) {
                $type = $parameter['type'] ?? null;

                if ($type !== null && $type !== 'text' && isset($parameter[$type]['link'])) {
                    return (string) $parameter[$type]['link'];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, string> $variables
     * @return list<array{type: string, text: string}>
     */
    private static function textParameters(array $fields, array $variables): array
    {
        $parameters = [];

        foreach ($fields as $field) {
            $parameters[] = [
                'type' => 'text',
                'text' => (string) ($variables[$field['key']] ?? ''),
            ];
        }

        return $parameters;
    }

    /**
     * Schema keys with no usable value supplied, across EVERY component.
     * Meta requires each declared positional parameter to be present and
     * non-empty -- it rejects the send outright otherwise -- so this is
     * checked before the Graph call rather than paying for a guaranteed
     * API rejection.
     *
     * Deliberately stricter than the plain-text path, which substitutes ''
     * for an unsupplied optional variable: an empty positional parameter
     * is not a valid Meta template send.
     *
     * @param array<string, string> $variables
     * @return list<string>
     */
    public static function missingComponentValues(MessageTemplate $template, array $variables): array
    {
        $missing = [];

        foreach ($template->effectiveVariablesSchema() as $field) {
            $key = $field['key'] ?? null;

            if ($key === null) {
                continue;
            }

            if (! array_key_exists($key, $variables) || trim((string) $variables[$key]) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Structural problems that make a schema untranslatable into a valid
     * Meta component set. Reported before the Graph call so an
     * unsendable definition fails locally with a clear reason instead of
     * as an opaque Meta rejection.
     *
     * @return list<string>
     */
    public static function componentStructureErrors(MessageTemplate $template): array
    {
        $errors = [];
        $headerTextFields = self::fieldsFor($template, 'header');

        foreach ($template->effectiveVariablesSchema() as $field) {
            $component = $field['component'] ?? 'body';
            $key = $field['key'] ?? '(unnamed)';

            if (! in_array($component, MessageTemplate::VARIABLE_COMPONENTS, true)) {
                // Covers 'footer' explicitly: Meta accepts no runtime
                // parameter for a footer at all.
                $errors[] = "\"{$key}\" declares an unsupported component \"{$component}\".";

                continue;
            }

            if ($component !== 'button') {
                continue;
            }

            $subType = $field['button_sub_type'] ?? null;

            if (! in_array($subType, MessageTemplate::BUTTON_SUB_TYPES, true)) {
                $errors[] = "\"{$key}\" is a button parameter but declares no valid button_sub_type.";
            }

            $index = $field['button_index'] ?? null;

            if (! is_int($index) && ! (is_string($index) && ctype_digit($index))) {
                $errors[] = "\"{$key}\" is a button parameter but declares no numeric button_index.";
            }
        }

        // Meta allows at most ONE header parameter per template.
        if (count($headerTextFields) > 1) {
            $errors[] = 'A Meta template may carry at most one header parameter.';
        }

        // A media header and a header text parameter are mutually
        // exclusive -- the registered template has one kind of header.
        if ($headerTextFields !== [] && in_array($template->header_type, ['image', 'document'], true)) {
            $errors[] = 'This template declares a media header, so it cannot also take a header text parameter.';
        }

        // Every field in one button index must agree on its sub type.
        $subTypesByIndex = [];
        foreach (self::fieldsFor($template, 'button') as $field) {
            $subTypesByIndex[(string) ($field['button_index'] ?? 0)][] = $field['button_sub_type'] ?? null;
        }

        foreach ($subTypesByIndex as $index => $subTypes) {
            if (count(array_unique($subTypes)) > 1) {
                $errors[] = "Button index {$index} declares more than one button_sub_type.";
            }
        }

        return $errors;
    }
}
