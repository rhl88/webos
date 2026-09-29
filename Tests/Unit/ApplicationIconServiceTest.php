<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Services\ApplicationIconService;
use PHPUnit\Framework\TestCase;

class ApplicationIconServiceTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'cmspro_webos_icon_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $manifestPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_it_reads_icon_from_current_manifest_file(): void
    {
        file_put_contents(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode(['icon' => 'fa fa-current'], JSON_UNESCAPED_UNICODE)
        );

        $icon = (new ApplicationIconService())->resolveManifestIcon(
            $this->temporaryDirectory,
            ['icon' => 'fa fa-stored']
        );

        $this->assertSame('fa fa-current', $icon);
    }

    public function test_it_uses_stored_manifest_when_current_file_is_unreadable(): void
    {
        $icon = (new ApplicationIconService())->resolveManifestIcon(
            $this->temporaryDirectory,
            ['icon' => 'fa fa-stored']
        );

        $this->assertSame('fa fa-stored', $icon);
    }
}
