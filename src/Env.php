<?php

/**
 * Minimal .env loader for local development. PHP never reads .env files on its
 * own, so entry points call Env::load() before anything reads getenv().
 *
 * Variables already present in the real environment always win: production
 * (Render) sets them in its dashboard and ships no .env, and a one-off
 * `DATABASE_URL=... php scripts/import.php` still overrides the file. Empty
 * values are skipped so an unfilled key stays unset, exactly as if the line
 * were absent. A missing file is not an error.
 *
 * Supported syntax: KEY=value, optional `export ` prefix, optional matching
 * single or double quotes around the value, and full-line # comments. Inline
 * comments after a value are NOT stripped.
 */
class Env {
    public static function load(string $path): void {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key   = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            $quote = $value[0] ?? '';
            if (strlen($value) >= 2 && ($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || $value === '' || getenv($key) !== false) {
                continue;
            }
            putenv("$key=$value");
        }
    }
}
