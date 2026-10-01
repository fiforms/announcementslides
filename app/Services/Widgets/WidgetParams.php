<?php

namespace App\Services\Widgets;

use App\Models\Widget;

/**
 * Validates user-supplied widget parameters against the manifest's
 * declarations. This is the trust boundary for non-admin input: whatever
 * passes here is what admin-installed widget code receives, and what the
 * server substitutes into endpoint templates.
 */
class WidgetParams
{
    /**
     * @return array<string, mixed>  only declared keys, defaults filled in
     */
    public static function clean(Widget $widget, mixed $params): array
    {
        $params = is_array($params) ? $params : [];
        $clean = [];
        $errors = [];

        foreach ($widget->manifest['parameters'] ?? [] as $key => $def) {
            try {
                $value = array_key_exists($key, $params) ? $params[$key] : ($def['default'] ?? null);
                $clean[$key] = self::cleanOne($key, $def, $value, $widget->extra_allow[$key] ?? []);
            } catch (WidgetPackageException $e) {
                $errors = [...$errors, ...$e->errors];
            }
        }

        if ($errors) {
            throw new WidgetPackageException($errors);
        }

        return $clean;
    }

    /**
     * @param  string[]  $extraAllow  admin-added allowlist prefixes (url params)
     */
    public static function cleanOne(string $key, array $def, mixed $value, array $extraAllow = []): mixed
    {
        $label = $def['label'] ?? $key;
        $fail = fn (string $why) => throw new WidgetPackageException(["{$label}: {$why}"]);
        $empty = $value === null || $value === '';

        if ($empty) {
            if (!empty($def['required'])) {
                $fail('is required.');
            }
            return match ($def['type']) {
                'boolean' => false,
                'number'  => null,
                default   => '',
            };
        }

        switch ($def['type']) {
            case 'string':
            case 'text':
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    $fail('must be text.');
                }
                $value = (string) $value;
                $max = $def['maxLength'] ?? ($def['type'] === 'text' ? 5000 : 500);
                if (mb_strlen($value) > $max) {
                    $fail("must be at most {$max} characters.");
                }
                if (isset($def['pattern']) && !preg_match(self::patternRegex($def['pattern']), $value)) {
                    $fail('is not in the expected format.');
                }
                return $value;

            case 'url':
                $url = is_string($value) ? UrlPolicy::normalize($value) : null;
                if ($url === null) {
                    $fail('must be an https:// address.');
                }
                $allow = isset($def['allow']) ? [...$def['allow'], ...$extraAllow] : null;
                if (!UrlPolicy::allowed($url, $allow)) {
                    $fail('this address isn\'t on the allowed list (' . implode(', ', $allow) . ').');
                }
                return $url;

            case 'enum':
                foreach ($def['options'] as $option) {
                    if ((string) $option === (string) $value) {
                        return $option;
                    }
                }
                $fail('is not one of the allowed choices.');

            case 'color':
                if (!is_string($value) || !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
                    $fail('must be a #rrggbb color.');
                }
                return strtolower($value);

            case 'number':
                if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                    $fail('must be a number.');
                }
                $n = $value + 0;
                if ((isset($def['min']) && $n < $def['min']) || (isset($def['max']) && $n > $def['max'])) {
                    $fail('is out of range.');
                }
                return $n;

            case 'boolean':
                if (!is_bool($value)) {
                    $fail('must be true or false.');
                }
                return $value;
        }

        $fail('has an unknown type.');
    }

    public static function patternRegex(string $pattern): string
    {
        return '~^(?:' . str_replace('~', '\~', $pattern) . ')$~u';
    }
}
