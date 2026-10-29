<?php

// SPDX-License-Identifier: GPL-3.0-or-later
namespace App\Plugins\GoAccess\Support;

final class Report
{
    public const DEFAULT_PATH = '/opt/librenms/app/Plugins/GoAccess/nginx-access.html';
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function resolve(mixed $path): string
    {
        if (! is_string($path) || strlen($path) > 4096 || $path === '' || $path[0] !== '/'
            || str_starts_with($path, '//') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new \RuntimeException('Enter an absolute Linux path without traversal, control characters, or stream wrappers.');
        }
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            throw new \RuntimeException('The report must have an .html or .htm extension.');
        }

        clearstatcache(true, $path);
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new \RuntimeException('The report does not exist or is not a readable regular file. Check PHP worker permissions.');
        }
        // A symlink named report.html must not expose a non-HTML target.
        if (! in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            throw new \RuntimeException('The resolved report target must also be an HTML file.');
        }

        return $resolved;
    }

    public static function document(mixed $path): string
    {
        $resolved = self::resolve($path);
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The report could not be opened.');
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_BYTES) {
                throw new \RuntimeException('The report must be a regular file no larger than 20 MiB.');
            }
            $html = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($html === false || strlen($html) > self::MAX_BYTES || trim($html) === '') {
                throw new \RuntimeException('The report is empty, unreadable, or larger than 20 MiB.');
            }
        } finally {
            fclose($handle);
        }

        // The policy precedes all report bytes. No same-origin privilege is granted
        // by the enclosing iframe. GoAccess's Hogan compiler uses new Function(),
        // which needs unsafe-eval within this opaque-origin frame only.
        $policy = "default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; "
            . "img-src data:; font-src data:; connect-src 'none'; frame-src 'none'; "
            . "object-src 'none'; base-uri 'none'; form-action 'none'";

        return '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'
            . htmlspecialchars($policy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $html;
    }
}
