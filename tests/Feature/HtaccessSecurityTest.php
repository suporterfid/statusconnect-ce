<?php

namespace Tests\Feature;

use Tests\TestCase;

class HtaccessSecurityTest extends TestCase
{
    public function test_public_htaccess_file_exists_and_contains_security_blocks(): void
    {
        $htaccessPath = public_path('.htaccess');
        $this->assertFileExists($htaccessPath);

        $content = file_get_contents($htaccessPath);
        $this->assertNotFalse($content);

        // Verify HTTPS rewrite condition
        $this->assertStringContainsString('RewriteCond %{HTTPS} off', $content);

        // Verify sensitive file pattern blocking
        $this->assertStringContainsString('sqlite', $content);
        $this->assertStringContainsString('env', $content);
        $this->assertStringContainsString('log', $content);

        // Verify compression and caching headers
        $this->assertStringContainsString('mod_deflate.c', $content);
        $this->assertStringContainsString('mod_headers.c', $content);
    }
}
