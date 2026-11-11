<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = trim((string) shell_exec('command -v composer 2>/dev/null'));

if ($composer !== '') {
    passthru(escapeshellarg($composer) . ' dump-autoload --strict-psr --no-ansi -d ' . escapeshellarg($root), $code);

    exit((int) $code);
}

$src = $root . '/src';
$errors = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }

    if (!preg_match('/^\s*namespace\s+App\\\\([^;]+)\s*;/m', $content, $namespaceMatch)) {
        continue;
    }

    if (!preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $content, $classMatch)) {
        continue;
    }

    $subNamespace = $namespaceMatch[1];
    $className = $classMatch[1];
    $expectedRelative = str_replace('\\', '/', $subNamespace) . '/' . $className . '.php';
    $expectedPath = $src . '/' . $expectedRelative;
    $actualPath = $file->getPathname();

    if (realpath($expectedPath) !== realpath($actualPath)) {
        $fqcn = 'App\\' . $subNamespace . '\\' . $className;
        $errors[] = sprintf(
            '%s must be in %s (found %s)',
            $fqcn,
            $expectedRelative,
            str_replace($root . '/', '', $actualPath),
        );
    }
}

if ($errors !== []) {
    fwrite(STDERR, "PSR-4 autoload violations:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "PSR-4 autoload check passed (composer not in PATH, used fallback scanner).\n");
exit(0);
