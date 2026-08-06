<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ModuleBoundaryTest extends TestCase
{
    public function test_cross_module_imports_are_limited_to_application_contracts_and_data(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../app/Modules'),
        );

        $violations = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                $violations[] = "Unable to read {$file->getPathname()}";

                continue;
            }
            $relativePath = str_replace('\\', '/', $file->getPathname());

            preg_match('/namespace App\\\\Modules\\\\([^;\\\\]+)([^;]*);/', $contents, $namespace);
            preg_match_all('/use App\\\\Modules\\\\([^;\\\\]+)\\\\([^;]+);/', $contents, $imports, PREG_SET_ORDER);

            foreach ($imports as $import) {
                $currentModule = $namespace[1] ?? null;
                $currentNamespace = $namespace[2] ?? '';
                $importedModule = $import[1];
                $importedNamespace = $import[2];

                if ($currentModule === $importedModule) {
                    continue;
                }

                $allowed = str_starts_with($currentNamespace, '\\Application\\')
                    && (
                        str_starts_with($importedNamespace, 'Application\\Contracts\\')
                        || str_starts_with($importedNamespace, 'Application\\Data\\')
                    );

                if (! $allowed) {
                    $violations[] = sprintf(
                        'Disallowed cross-module import [%s\\%s] found in %s',
                        $importedModule,
                        $importedNamespace,
                        $relativePath,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Module boundary violations:\n- ".implode("\n- ", $violations),
        );
    }
}
