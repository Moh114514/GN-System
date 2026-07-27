<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requiredFiles = [
    'AGENTS.md',
    'README.md',
    'docs/README.md',
    'docs/project-status.md',
    'docs/architecture/overview.md',
    'docs/architecture/module-boundaries.md',
    'docs/adr/README.md',
    'docs/adr/template.md',
    'docs/development/documentation.md',
    'docs/source/README.md',
];

$errors = [];

foreach ($requiredFiles as $requiredFile) {
    if (! is_file($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $requiredFile))) {
        $errors[] = "Missing required documentation file: {$requiredFile}";
    }
}

$adrNumbers = [];
foreach (glob($root.'/docs/adr/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $adrFile) {
    $number = substr(basename($adrFile), 0, 4);
    $adrNumbers[$number][] = $adrFile;
}

foreach ($adrNumbers as $number => $files) {
    if (count($files) > 1) {
        $names = implode(', ', array_map('basename', $files));
        $errors[] = "Duplicate ADR number {$number}: {$names}";
    }
}

$directories = [
    $root,
    $root.'/docs',
    $root.'/.github',
];
$markdownFiles = [];

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                return ! in_array($file->getFilename(), [
                    '.git',
                    'node_modules',
                    'vendor',
                    'storage',
                ], true);
            },
        ),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            $markdownFiles[$file->getRealPath()] = true;
        }
    }
}

foreach (array_keys($markdownFiles) as $markdownFile) {
    $contents = file_get_contents($markdownFile);
    if ($contents === false) {
        $errors[] = 'Unable to read '.relativePath($root, $markdownFile);

        continue;
    }

    preg_match_all('/!?\[[^\]]*]\(([^)]+)\)/', $contents, $matches);

    foreach ($matches[1] as $rawTarget) {
        $target = trim($rawTarget);
        if ($target === '' || str_starts_with($target, '#')) {
            continue;
        }

        if (preg_match('/^(?:https?:|mailto:|tel:)/i', $target) === 1) {
            continue;
        }

        if (str_starts_with($target, '<') && str_ends_with($target, '>')) {
            $target = substr($target, 1, -1);
        } elseif (preg_match('/^(\S+)\s+["\'].*["\']$/', $target, $parts) === 1) {
            $target = $parts[1];
        }

        $path = rawurldecode(explode('#', $target, 2)[0]);
        if ($path === '') {
            continue;
        }

        $resolved = dirname($markdownFile).DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (! file_exists($resolved)) {
            $errors[] = sprintf(
                'Broken Markdown link in %s: %s',
                relativePath($root, $markdownFile),
                $rawTarget,
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Documentation checks failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

printf(
    "Documentation checks passed (%d Markdown files, %d ADRs).\n",
    count($markdownFiles),
    count($adrNumbers),
);

function relativePath(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}
