<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Arch;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A token scan over `src/` for the bespoke bans: names used as code (constants, functions,
 * classes), never words in comments or strings.
 */
final class SourceTokens
{
    /**
     * Every file under `$dir` (relative path => source).
     *
     * @return array<string, string>
     */
    public static function files(string $dir): array
    {
        $files = [];
        $root = (string) realpath($dir);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * The files that use `$name` as code, and how often.
     *
     * @param  array<string, string>  $files
     * @return array<string, int>
     */
    public static function uses(array $files, string $name): array
    {
        $found = [];

        foreach ($files as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && strcasecmp(ltrim((string) substr(strrchr('\\'.$token[1], '\\') ?: '', 1)), $name) === 0) {
                    $found[$path] = ($found[$path] ?? 0) + 1;
                }
            }
        }

        return $found;
    }

    /**
     * The files that call `$function(` with a string literal first argument starting with `$prefix`.
     *
     * @param  array<string, string>  $files
     * @return list<string>
     */
    public static function callsWithLiteral(array $files, string $function, string $prefix): array
    {
        $found = [];

        foreach ($files as $path => $source) {
            $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], $function) === 0
                    && ($tokens[$i + 1] ?? null) === '('
                    && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                    && str_starts_with(strtolower(trim($tokens[$i + 2][1], '\'"')), $prefix)) {
                    $found[] = $path;
                }
            }
        }

        return $found;
    }
}
