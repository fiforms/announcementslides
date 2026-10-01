<?php

namespace App\Services\Widgets;

/**
 * Validates and normalizes a widget package's manifest.json. Everything a
 * widget is allowed to do on the server side — which parameters users can
 * set, which upstream endpoints the server will fetch for it, which admin
 * settings (API keys) it reads — is declared here and checked at install.
 *
 * Throws WidgetPackageException listing every problem found.
 */
class WidgetManifest
{
    public const PARAM_TYPES = ['string', 'text', 'url', 'enum', 'color', 'number', 'boolean'];
    public const EXPECT_TYPES = ['ical', 'json', 'text'];
    // Runtime arguments are supplied by widget code at fetch time (e.g. a
    // forecast's lat/lon from an earlier geocode), so they get the narrow
    // types only — never a URL or free text.
    public const ARG_TYPES = ['string', 'enum', 'number', 'boolean'];

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * @param  string[]  $files  package-relative file paths
     */
    public static function validate(mixed $m, array $files): array
    {
        $errors = [];
        if (!is_array($m) || array_is_list($m)) {
            throw new WidgetPackageException(['manifest.json must be a JSON object.']);
        }

        $str = fn ($key, $max) => is_string($m[$key] ?? null) && trim($m[$key]) !== '' && mb_strlen($m[$key]) <= $max;

        if (!is_string($m['id'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $m['id'])) {
            $errors[] = '"id" must be 1–40 lowercase letters, digits or dashes.';
        }
        if (!$str('name', 80)) {
            $errors[] = '"name" is required (up to 80 characters).';
        }
        if (!is_string($m['version'] ?? null) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,40})?$/', $m['version'])) {
            $errors[] = '"version" must look like 1.2.3.';
        }
        if (isset($m['description']) && !$str('description', 1000)) {
            $errors[] = '"description" must be a string up to 1000 characters.';
        }

        $entry = $m['entry'] ?? null;
        if (!is_string($entry) || !preg_match('/\.m?js$/', $entry) || !in_array($entry, $files, true)) {
            $errors[] = '"entry" must name a .js file in the package.';
        }
        foreach (['icon' => true, 'preview' => false] as $key => $required) {
            $value = $m[$key] ?? null;
            if ($value === null && !$required) {
                continue;
            }
            if (!is_string($value) || !preg_match('/\.(png|webp|jpe?g)$/i', $value) || !in_array($value, $files, true)) {
                $errors[] = "\"{$key}\" must name a .png, .webp or .jpg file in the package.";
            }
        }

        $size = $m['defaultSize'] ?? ['w' => 400, 'h' => 300];
        if (!is_array($size) || !self::intIn($size['w'] ?? null, 10, 1920) || !self::intIn($size['h'] ?? null, 10, 1080)) {
            $errors[] = '"defaultSize" must be {"w": 10–1920, "h": 10–1080}.';
        }
        if (isset($m['aspectLocked']) && !is_bool($m['aspectLocked'])) {
            $errors[] = '"aspectLocked" must be true or false.';
        }

        $params = $m['parameters'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            $errors[] = '"parameters" must be an object.';
            $params = [];
        }
        foreach ($params as $key => $p) {
            $errors = [...$errors, ...self::validateParam((string) $key, $p)];
        }

        $settings = $m['settings'] ?? [];
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $errors[] = '"settings" must be an object.';
            $settings = [];
        }
        foreach ($settings as $key => $s) {
            if (!preg_match(self::KEY, (string) $key) || !is_array($s)) {
                $errors[] = "Setting \"{$key}\" must have a lowercase key and an object value.";
            }
        }

        $endpoints = $m['endpoints'] ?? [];
        if (!is_array($endpoints) || ($endpoints !== [] && array_is_list($endpoints))) {
            $errors[] = '"endpoints" must be an object.';
            $endpoints = [];
        }
        foreach ($endpoints as $key => $e) {
            $errors = [...$errors, ...self::validateEndpoint((string) $key, $e, $params, $settings)];
        }

        if ($errors) {
            throw new WidgetPackageException($errors);
        }

        return [
            'id'           => $m['id'],
            'name'         => trim($m['name']),
            'version'      => $m['version'],
            'description'  => isset($m['description']) ? trim($m['description']) : null,
            'entry'        => $entry,
            'icon'         => $m['icon'],
            'preview'      => $m['preview'] ?? null,
            'defaultSize'  => ['w' => $size['w'], 'h' => $size['h']],
            'aspectLocked' => (bool) ($m['aspectLocked'] ?? false),
            'parameters'   => $params,
            'settings'     => $settings,
            'endpoints'    => $endpoints,
        ];
    }

    private static function validateParam(string $key, mixed $p): array
    {
        $where = "Parameter \"{$key}\"";
        if (!preg_match(self::KEY, $key)) {
            return ["{$where} must have a lowercase key (letters, digits, underscores)."];
        }
        if (!is_array($p) || !in_array($p['type'] ?? null, self::PARAM_TYPES, true)) {
            return ["{$where} must have a type: " . implode(', ', self::PARAM_TYPES) . '.'];
        }

        $errors = [];
        foreach (['label', 'help'] as $field) {
            if (isset($p[$field]) && (!is_string($p[$field]) || mb_strlen($p[$field]) > 200)) {
                $errors[] = "{$where}: \"{$field}\" must be a string up to 200 characters.";
            }
        }

        switch ($p['type']) {
            case 'enum':
                if (!is_array($p['options'] ?? null) || !$p['options'] || !array_is_list($p['options'])
                    || array_filter($p['options'], fn ($o) => !is_string($o) && !is_int($o))) {
                    $errors[] = "{$where}: enum needs a non-empty \"options\" list.";
                }
                break;
            case 'string':
                if (isset($p['pattern']) && (!is_string($p['pattern']) || @preg_match(WidgetParams::patternRegex($p['pattern']), '') === false)) {
                    $errors[] = "{$where}: \"pattern\" is not a valid regular expression.";
                }
                break;
            case 'number':
                foreach (['min', 'max', 'step'] as $field) {
                    if (isset($p[$field]) && !is_int($p[$field]) && !is_float($p[$field])) {
                        $errors[] = "{$where}: \"{$field}\" must be a number.";
                    }
                }
                break;
            case 'url':
                if (isset($p['allow'])) {
                    if (!is_array($p['allow']) || !array_is_list($p['allow'])) {
                        $errors[] = "{$where}: \"allow\" must be a list of https:// URL prefixes.";
                        break;
                    }
                    foreach ($p['allow'] as $prefix) {
                        if (!is_string($prefix) || UrlPolicy::normalize($prefix) === null || str_contains($prefix, '?')) {
                            $errors[] = "{$where}: allow entry \"{$prefix}\" must be an https:// URL prefix without a query.";
                        }
                    }
                }
                break;
        }
        if (isset($p['maxLength']) && !self::intIn($p['maxLength'], 1, 5000)) {
            $errors[] = "{$where}: \"maxLength\" must be 1–5000.";
        }

        if (!$errors && array_key_exists('default', $p) && $p['default'] !== null && $p['default'] !== '') {
            try {
                WidgetParams::cleanOne($key, $p, $p['default']);
            } catch (WidgetPackageException) {
                $errors[] = "{$where}: \"default\" doesn't satisfy its own rules.";
            }
        }

        return $errors;
    }

    private static function validateEndpoint(string $key, mixed $e, array $params, array $settings): array
    {
        $where = "Endpoint \"{$key}\"";
        if (!preg_match(self::KEY, $key)) {
            return ["{$where} must have a lowercase key."];
        }
        if (!is_array($e) || !is_string($e['url'] ?? null) || !in_array($e['expect'] ?? null, self::EXPECT_TYPES, true)) {
            return ["{$where} needs a \"url\" template and \"expect\" (" . implode(', ', self::EXPECT_TYPES) . ').'];
        }

        $errors = [];
        $template = $e['url'];

        $args = $e['args'] ?? [];
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            $errors[] = "{$where}: \"args\" must be an object.";
            $args = [];
        }
        foreach ($args as $argKey => $arg) {
            if (!is_array($arg) || !in_array($arg['type'] ?? null, self::ARG_TYPES, true)) {
                $errors[] = "{$where}: arg \"{$argKey}\" must have a type: " . implode(', ', self::ARG_TYPES) . '.';
                continue;
            }
            if ($arg['type'] === 'string' && !isset($arg['pattern'])) {
                $errors[] = "{$where}: string arg \"{$argKey}\" needs a \"pattern\".";
            }
            $errors = [...$errors, ...array_map(fn ($m) => "{$where}: {$m}", self::validateParam((string) $argKey, $arg))];
        }

        preg_match_all('/\{([a-z0-9_:]+)\}/', $template, $matches);
        foreach ($matches[1] as $placeholder) {
            if (str_starts_with($placeholder, 'secret:')) {
                if (!isset($settings[substr($placeholder, 7)])) {
                    $errors[] = "{$where}: {{$placeholder}} isn't a declared setting.";
                }
            } elseif (str_starts_with($placeholder, 'arg:')) {
                if (!isset($args[substr($placeholder, 4)])) {
                    $errors[] = "{$where}: {{$placeholder}} isn't a declared arg.";
                }
            } elseif (!isset($params[$placeholder])) {
                $errors[] = "{$where}: {{$placeholder}} isn't a declared parameter.";
            }
        }

        if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/', $template, $whole)) {
            // A whole-URL template: only a url parameter may supply it.
            if (($params[$whole[1]]['type'] ?? null) !== 'url') {
                $errors[] = "{$where}: a whole-URL template must be a single url parameter.";
            }
        } else {
            // Fixed host: placeholders may only appear in the path/query.
            if (!preg_match('#^https://[^/?\#{}]+(/.*)?$#', $template)
                || UrlPolicy::normalize(preg_replace('/\{[^}]*\}/', 'x', $template)) === null) {
                $errors[] = "{$where}: url must be https:// with a fixed host (placeholders only in the path or query).";
            }
        }

        if (isset($e['ttl']) && !self::intIn($e['ttl'], 0, 86400)) {
            $errors[] = "{$where}: \"ttl\" must be 0–86400 seconds.";
        }
        if (isset($e['days']) && !self::intIn($e['days'], 1, 366)) {
            $errors[] = "{$where}: \"days\" must be 1–366.";
        }

        return $errors;
    }

    private static function intIn(mixed $v, int $min, int $max): bool
    {
        return is_int($v) && $v >= $min && $v <= $max;
    }
}
