<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = trim((string) shell_exec('command -v composer 2>/dev/null'));

if ($composer !== '') {
    $command = escapeshellarg($composer)
        . ' dump-autoload --optimize --strict-psr --dry-run --no-ansi -d '
        . escapeshellarg($root)
        . ' 2>&1';

    $output = [];
    $code = 0;
    exec($command, $output, $code);
    $text = implode("\n", $output);

    $dryRunUnsupported = $code !== 0
        && (preg_match('/unknown option [`\']?--dry-run/i', $text) === 1
            || preg_match('/The "--dry-run" option does not exist/i', $text) === 1);

    if (!$dryRunUnsupported) {
        if ($text !== '') {
            fwrite($code === 0 ? STDOUT : STDERR, $text . "\n");
        }

        exit($code);
    }

    fwrite(STDERR, "Composer does not support --dry-run for dump-autoload; using fallback scanner.\n");
}

exit(runManualPsr4Check($root));

/**
 * @return int 0 on success, 1 on violations
 */
function runManualPsr4Check(string $root): int
{
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

        $expectedReal = realpath($expectedPath);
        $actualReal = realpath($actualPath);

        if ($expectedReal === false || $actualReal === false || $expectedReal !== $actualReal) {
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

        return 1;
    }

    fwrite(STDOUT, "PSR-4 autoload check passed (fallback scanner).\n");

    return 0;
}
