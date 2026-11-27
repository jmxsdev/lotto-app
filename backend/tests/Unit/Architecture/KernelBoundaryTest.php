<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class KernelBoundaryTest extends TestCase
{
    public function test_kernel_no_importa_laravel_ni_app(): void
    {
        $root = dirname(__DIR__, 3).'/packages/kernel/src';

        $this->assertDirectoryExists($root);

        $infractores = [];

        /** @var SplFileInfo $archivo */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $contenido = (string) file_get_contents($archivo->getPathname());

            if (str_contains($contenido, 'Illuminate\\') || str_contains($contenido, 'use App\\')) {
                $infractores[] = $archivo->getPathname();
            }
        }

        $this->assertSame(
            [],
            $infractores,
            'El kernel no puede importar Laravel ni la app: '.implode(', ', $infractores),
        );
    }
}
