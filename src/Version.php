<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

/**
 * Central place for the project version string so it can be reused by build tooling later.
 */
final class Version
{
    public const CURRENT = '0.1.0-dev';
    public const BUILD_INFO_FILE = 'build-info.php';

    /**
     * @var array{build_date: ?string, git_sha: ?string}|null
     */
    private static ?array $cachedBuildInfo = null;

    /**
     * @return array{build_date: ?string, git_sha: ?string}
     */
    public static function buildInfo(): array
    {
        if (self::$cachedBuildInfo !== null) {
            return self::$cachedBuildInfo;
        }

        $empty = [
            'build_date' => null,
            'git_sha' => null,
        ];

        if (!class_exists('Phar')) {
            return self::$cachedBuildInfo = $empty;
        }

        try {
            $pharPath = \Phar::running(false);
        } catch (\PharException $exception) {
            return self::$cachedBuildInfo = $empty;
        }

        if ($pharPath === '') {
            return self::$cachedBuildInfo = $empty;
        }

        $buildInfoPath = 'phar://' . $pharPath . '/' . self::BUILD_INFO_FILE;
        if (!is_file($buildInfoPath)) {
            return self::$cachedBuildInfo = $empty;
        }

        /** @var mixed $data */
        $data = @include $buildInfoPath;
        if (!is_array($data)) {
            return self::$cachedBuildInfo = $empty;
        }

        $buildDate = isset($data['build_date']) && is_string($data['build_date']) ? $data['build_date'] : null;
        $gitSha = isset($data['git_sha']) && is_string($data['git_sha']) ? $data['git_sha'] : null;

        return self::$cachedBuildInfo = [
            'build_date' => $buildDate,
            'git_sha' => $gitSha,
        ];
    }
}
