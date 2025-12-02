<?php

declare(strict_types=1);

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
$phar->stopBuffering();
chmod($pharPath, 0755);

echo "Built " . $pharPath . PHP_EOL;
