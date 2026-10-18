<?php
/**
 * Reusable scheme detection for YeCha theme layouts.
 * Probes every signal the edge actually sends (never hard-codes https), in order:
 *   $_SERVER['HTTPS'] · X-Forwarded-Proto · CF-Visitor's "scheme" · SERVER_PORT=443
 * Falls back to http when nothing says otherwise (a genuinely plain-HTTP site stays http).
 */
function yc_theme_scheme(): string
{
    static $scheme = null;
    if ($scheme !== null) { return $scheme; }
    $proto = '';
    $s = $_SERVER;
    if (!empty($s['HTTPS']) && strtolower((string)$s['HTTPS']) !== 'off') { $proto = 'https'; }
    if ($proto === '' && !empty($s['HTTP_X_FORWARDED_PROTO'])) {
        $p = strtolower(trim(explode(',', (string)$s['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($p === 'https' || $p === 'http') { $proto = $p; }
    }
    if ($proto === '' && !empty($s['HTTP_X_FORWARDED_SSL'])) {
        $p = strtolower(trim((string)$s['HTTP_X_FORWARDED_SSL']));
        if ($p === 'on' || $p === '1' || $p === 'https') { $proto = 'https'; }
    }
    if ($proto === '' && !empty($s['HTTP_CF_VISITOR'])) {
        $j = json_decode((string)$s['HTTP_CF_VISITOR'], true);
        if (is_array($j) && !empty($j['scheme'])) {
            $p = strtolower((string)$j['scheme']);
            if ($p === 'https' || $p === 'http') { $proto = $p; }
        }
    }
    if ($proto === '' && (string)($s['SERVER_PORT'] ?? '') === '443') { $proto = 'https'; }
    if ($proto === '' && !empty($s['REQUEST_SCHEME'])) {
        $p = strtolower((string)$s['REQUEST_SCHEME']);
        if ($p === 'https' || $p === 'http') { $proto = $p; }
    }
    return $scheme = ($proto === 'https' ? 'https' : 'http');
}

/** Absolute origin, e.g. "https://gushuwu.com" (empty host -> empty string). */
function yc_theme_origin(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $host !== '' ? yc_theme_scheme() . '://' . $host : '';
}
