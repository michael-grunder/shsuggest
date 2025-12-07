<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class SystemContextProvider
{
    public function describe(): string
    {
        $parts = [];

        $osFamily = PHP_OS_FAMILY;
        if ($osFamily !== '') {
            $parts[] = 'OS family: ' . $osFamily;
        }

        $kernel = $this->buildKernelSummary();
        if ($kernel !== null) {
            $parts[] = 'Kernel: ' . $kernel;
        }

        $distribution = $this->detectDistribution();
        if ($distribution !== null) {
            $parts[] = 'Distribution: ' . $distribution;
        }

        $shell = $this->detectShell();
        if ($shell !== null) {
            $parts[] = 'Shell: ' . $shell;
        }

        if ($parts === []) {
            return '';
        }

        return implode(PHP_EOL, $parts);
    }

    private function buildKernelSummary(): ?string
    {
        $system = trim((string) php_uname('s'));
        $release = trim((string) php_uname('r'));
        $machine = trim((string) php_uname('m'));

        if ($system === '' && $release === '' && $machine === '') {
            return null;
        }

        $segments = array_values(array_filter([$system, $release, $machine], static function (string $value): bool {
            return $value !== '';
        }));

        return $segments === [] ? null : implode(' ', $segments);
    }

    private function detectDistribution(): ?string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $pretty = $this->extractOsReleaseValue('PRETTY_NAME');
            if ($pretty !== null) {
                return $pretty;
            }

            $name = $this->extractOsReleaseValue('NAME');
            $version = $this->extractOsReleaseValue('VERSION');

            if ($name !== null && $version !== null) {
                return $name . ' ' . $version;
            }

            if ($name !== null) {
                return $name;
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $product = $this->extractPlistVersion('/System/Library/CoreServices/SystemVersion.plist', 'ProductName');
            $version = $this->extractPlistVersion('/System/Library/CoreServices/SystemVersion.plist', 'ProductVersion');

            if ($product !== null && $version !== null) {
                return $product . ' ' . $version;
            }

            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    private function detectShell(): ?string
    {
        $candidates = [
            getenv('SHSUGGEST_SHELL') ?: null,
            getenv('SHELL') ?: null,
            $_SERVER['SHELL'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            $base = basename($trimmed);
            if ($base === '') {
                $base = $trimmed;
            }

            return $base;
        }

        return null;
    }

    private function extractOsReleaseValue(string $key): ?string
    {
        $path = '/etc/os-release';
        if (!is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, $key . '=')) {
                continue;
            }

            $value = substr($line, strlen($key) + 1);
            $value = trim($value);
            $value = trim($value, "\"'");

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractPlistVersion(string $path, string $key): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $pattern = sprintf('#<key>%s</key>\s*<string>([^<]+)</string>#i', preg_quote($key, '#'));
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }
}
