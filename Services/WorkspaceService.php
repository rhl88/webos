<?php

namespace App\Apps\CmsproWebos\Services;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceService
{
    public const DEFAULT_PREFERENCES = [
        'wallpaper' => 'webos-default',
        'taskbar_alignment' => 'left',
        'taskbar_position' => 'bottom',
        'clock_format' => '24h',
        'show_seconds' => false,
        'motion' => true,
    ];

    public function getForAdmin(int $adminUserId): WebosWorkspace
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['admin_user_id' => $adminUserId],
            [
                'desktop_items' => [],
                'preferences' => self::DEFAULT_PREFERENCES,
                'status' => 1,
            ]
        );

        $workspace->preferences = array_replace(
            self::DEFAULT_PREFERENCES,
            $workspace->preferences ?? []
        );

        return $workspace;
    }

    /**
     * @param array{desktop_items?:array<int,array<string,mixed>>,preferences?:array<string,mixed>} $payload
     */
    public function saveForAdmin(int $adminUserId, array $payload): WebosWorkspace
    {
        $workspace = $this->getForAdmin($adminUserId);

        if (array_key_exists('desktop_items', $payload)) {
            $workspace->desktop_items = $this->sanitizeDesktopItems($payload['desktop_items'] ?? []);
        }

        if (array_key_exists('preferences', $payload)) {
            $workspace->preferences = $this->sanitizePreferences(
                $workspace->preferences ?? [],
                $payload['preferences'] ?? []
            );
        }

        $workspace->save();

        return $workspace->refresh();
    }

    /** @return array<int,array<string,mixed>> */
    protected function sanitizeDesktopItems(array $items): array
    {
        return collect(array_slice($items, 0, 48))
            ->map(fn (array $item): array => $this->sanitizeDesktopItem($item))
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    protected function sanitizeDesktopItem(array $item): array
    {
        $path = trim((string) ($item['path'] ?? ''));
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('桌面入口路径必须是站内路径');
        }

        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['id'] ?? '')) ?: Str::uuid()->toString();
        $icon = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($item['icon'] ?? 'fa fa-cube'));

        return [
            'id' => Str::limit($id, 80, ''),
            'menu_id' => isset($item['menu_id']) ? max(0, (int) $item['menu_id']) : null,
            'title' => Str::limit(trim((string) ($item['title'] ?? '未命名应用')), 60, ''),
            'path' => Str::limit($path, 500, ''),
            'icon' => Str::limit($icon ?: 'fa fa-cube', 100, ''),
            'group_title' => Str::limit(trim((string) ($item['group_title'] ?? '应用')), 60, ''),
            'x' => min(99, max(0, (int) ($item['x'] ?? 0))),
            'y' => min(99, max(0, (int) ($item['y'] ?? 0))),
        ];
    }

    /** @return array<string,mixed> */
    protected function sanitizePreferences(array $current, array $incoming): array
    {
        $allowed = Arr::only($incoming, array_keys(self::DEFAULT_PREFERENCES));
        $preferences = array_replace(self::DEFAULT_PREFERENCES, $current, $allowed);

        $preferences['wallpaper'] = in_array($preferences['wallpaper'], ['webos-default', 'deep-blue'], true)
            ? $preferences['wallpaper']
            : self::DEFAULT_PREFERENCES['wallpaper'];
        $preferences['taskbar_alignment'] = in_array($preferences['taskbar_alignment'], ['left', 'center'], true)
            ? $preferences['taskbar_alignment']
            : self::DEFAULT_PREFERENCES['taskbar_alignment'];
        $preferences['taskbar_position'] = in_array(
            $preferences['taskbar_position'],
            ['top', 'bottom', 'left', 'right'],
            true
        ) ? $preferences['taskbar_position'] : self::DEFAULT_PREFERENCES['taskbar_position'];
        $preferences['clock_format'] = $preferences['clock_format'] === '12h' ? '12h' : '24h';
        $preferences['show_seconds'] = (bool) $preferences['show_seconds'];
        $preferences['motion'] = (bool) $preferences['motion'];

        return $preferences;
    }
}
