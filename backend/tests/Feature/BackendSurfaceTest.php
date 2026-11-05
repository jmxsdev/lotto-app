<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackendSurfaceTest extends TestCase
{
    public function test_checkrole_alias_is_not_registered(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayNotHasKey('checkrole', $aliases);
    }
}
