<?php

namespace App\Apps\CmsproWebos\Services;

class ApplicationIconService
{
    public function resolveManifestIcon(string $appDirectory, array $storedManifest = []): ?string
    {
        $manifest = $this->readManifest($appDirectory . DIRECTORY_SEPARATOR . 'manifest.json')
            ?? $storedManifest;
        $icon = $manifest['icon'] ?? null;

        return is_string($icon) && trim($icon) !== '' ? trim($icon) : null;
    }

    protected function readManifest(string $manifestPath): ?array
    {
        if (! is_readable($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $manifest = json_decode($content, true);

        return is_array($manifest) ? $manifest : null;
    }
}
