<?php
/**
 * Minimal .env loader.
 *
 * Parses the project-root .env file once per request and exposes values through
 * env(). Values are cached in a static array so repeated calls are cheap. Real
 * environment variables (getenv) take precedence over the file so the same code
 * works on cPanel/Apache where vars may be set in the vhost.
 */

if (!function_exists('env')) {

    function env_load(): array
    {
        static $vars = null;
        if ($vars !== null) {
            return $vars;
        }
        $vars = [];
        $path = __DIR__ . '/../.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip optional surrounding quotes.
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                        $value = substr($value, 1, -1);
                    }
                }
                $vars[$key] = $value;
            }
        }
        return $vars;
    }

    /**
     * Fetch a configuration value. Order of precedence: real env var, .env file,
     * then the supplied default.
     */
    function env(string $key, $default = null)
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        $vars = env_load();
        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}
