<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

final class BootstrapAppPatcher
{
    private const LARAVEL_APPLICATION = 'Illuminate\\Foundation\\Application';

    private const PACKAGE_APPLICATION = 'MrSonj\\MultiDomainGhost\\Foundation\\Application';

    public static function patch(string $content): ?string
    {
        $packageImportPattern = '/use\s+'.preg_quote(self::PACKAGE_APPLICATION, '/')
            .'(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;/';

        if (preg_match($packageImportPattern, $content, $matches) === 1) {
            $alias = $matches[1] ?? 'Application';

            return preg_match('/\b'.preg_quote($alias, '/').'::configure\s*\(/', $content) === 1
                ? $content
                : null;
        }

        $packageCallPattern = '/\\\\?'.preg_quote(self::PACKAGE_APPLICATION, '/')
            .'::configure\s*\(/';

        if (preg_match($packageCallPattern, $content) === 1) {
            return $content;
        }

        $importPattern = '/use\s+'.preg_quote(self::LARAVEL_APPLICATION, '/')
            .'(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;/';

        if (preg_match($importPattern, $content, $matches) === 1) {
            $alias = $matches[1] ?? 'Application';
            $replacement = 'use '.self::PACKAGE_APPLICATION;

            if ($alias !== 'Application') {
                $replacement .= " as {$alias}";
            }

            $replacement .= ';';
            $patched = preg_replace($importPattern, $replacement, $content, 1);

            if (
                is_string($patched)
                && preg_match('/\b'.preg_quote($alias, '/').'::configure\s*\(/', $patched) === 1
            ) {
                return $patched;
            }

            return null;
        }

        $fullyQualifiedPattern = '/\\\\?'.preg_quote(self::LARAVEL_APPLICATION, '/')
            .'::configure\s*\(/';

        if (preg_match($fullyQualifiedPattern, $content) === 1) {
            return preg_replace(
                $fullyQualifiedPattern,
                '\\'.self::PACKAGE_APPLICATION.'::configure(',
                $content,
                1,
            );
        }

        return null;
    }
}
