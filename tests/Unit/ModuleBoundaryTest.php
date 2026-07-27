<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ModuleBoundaryTest extends TestCase
{
    public function test_domain_modules_do_not_import_another_modules_namespace(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../app/Modules'),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $relativePath = str_replace('\\', '/', $file->getPathname());

            preg_match('/namespace App\\\\Modules\\\\([^;\\\\]+)/', $contents, $namespace);
            preg_match_all('/use App\\\\Modules\\\\([^;\\\\]+)/', $contents, $imports);

            foreach ($imports[1] as $importedModule) {
                $this->assertSame(
                    $namespace[1] ?? null,
                    $importedModule,
                    "Cross-module concrete import found in {$relativePath}",
                );
            }
        }
    }
}
