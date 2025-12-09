<?php

declare(strict_types=1);

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

if (!class_exists(\Mike\Shsuggest\Version::class) && is_file(__DIR__ . '/src/Version.php')) {
    require __DIR__ . '/src/Version.php';
}

$root = __DIR__;
$pharPath = $root . '/shsuggest.phar';

if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "phar.readonly is enabled. Run with: php -d phar.readonly=0 build-phar.php\n");
    exit(1);
}

@unlink($pharPath);

try {
    $phar = new Phar($pharPath);
} catch (Exception $exception) {
    fwrite(STDERR, 'Unable to create PHAR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$phar->startBuffering();

$paths = ['bin', 'src', 'vendor'];
foreach ($paths as $path) {
    $absolute = $root . '/' . $path;
    if (!is_dir($absolute)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            continue;
        }

        $localName = substr($file->getPathname(), strlen($root) + 1);
        $phar->addFile($file->getPathname(), $localName);
    }
}

foreach (['composer.json', 'composer.lock'] as $file) {
    $absolute = $root . '/' . $file;
    if (is_file($absolute)) {
        $phar->addFile($absolute, $file);
    }
}

$stub = "#!/usr/bin/env php\n" . Phar::createDefaultStub('bin/shsuggest');
$phar->setStub($stub);

$phar->addFromString(
    \Mike\Shsuggest\Version::BUILD_INFO_FILE,
    buildMetadataFile()
);

$phar->stopBuffering();
chmod($pharPath, 0755);

echo "Built " . $pharPath . PHP_EOL;

function buildMetadataFile(): string
{
    $data = [
        'version' => \Mike\Shsuggest\Version::CURRENT,
        'build_date' => gmdate('Y-m-d'),
        'git_sha' => determineGitSha(),
    ];

    return "<?php\nreturn " . var_export($data, true) . ";\n";
}

function determineGitSha(): ?string
{
    $command = sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg(__DIR__));
    $output = [];
    $status = 0;
    @exec($command, $output, $status);

    if ($status !== 0 || $output === []) {
        return null;
    }

    $sha = trim((string) $output[0]);

    return $sha !== '' ? $sha : null;
}
