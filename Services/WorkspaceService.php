<?php

namespace App\Apps\CmsproWebos\Services;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceService
{
    public const DEFAULT_PREFERENCES = [
        'wallpaper' => 'webos-default',
        'wallpaper_url' => '',
        'icon_size' => 'medium',
        'taskbar_alignment' => 'left',
        'taskbar_position' => 'bottom',
        'clock_format' => '24h',
        'show_seconds' => false,
        'motion' => true,
        'window_width' => 78,
        'window_height' => 80,
        'override_admin_home' => false,
        'usage_stats' => [],
    ];

    public function getForAdmin(int $adminUserId): WebosWorkspace
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['admin_user_id' => $adminUserId],
            [
                'desktop_items' => [],
                'taskbar_items' => [],
                'preferences' => self::DEFAULT_PREFERENCES,
                'status' => 1,
            ]
        );

        $workspace->preferences = array_replace(
            self::DEFAULT_PREFERENCES,
            $workspace->preferences ?? []
        );

        // 旧数据兼容：迁移前无任务栏记录时，沿用桌面项前 4 位作为初始任务栏固定项
        if (! is_array($workspace->taskbar_items)) {
            $workspace->taskbar_items = array_slice($workspace->desktop_items ?? [], 0, 4);
        }

        return $workspace;
    }

    /**
     * @param array{desktop_items?:array<int,array<string,mixed>>,taskbar_items?:array<int,array<string,mixed>>,preferences?:array<string,mixed>} $payload
     */
    public function saveForAdmin(int $adminUserId, array $payload): WebosWorkspace
    {
        $workspace = $this->getForAdmin($adminUserId);

        if (array_key_exists('desktop_items', $payload)) {
            $workspace->desktop_items = $this->sanitizeDesktopItems($payload['desktop_items'] ?? []);
        }

        if (array_key_exists('taskbar_items', $payload)) {
            $workspace->taskbar_items = $this->sanitizeTaskbarItems($payload['taskbar_items'] ?? []);
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

    /** 任务栏固定项：结构同桌面项但无坐标，最多 12 个 */
    protected function sanitizeTaskbarItems(array $items): array
    {
        return collect(array_slice($items, 0, 12))
            ->map(fn (array $item): array => Arr::except($this->sanitizeDesktopItem($item), ['x', 'y']))
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
        $appId = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($item['app_id'] ?? ''));

        return [
            'id' => Str::limit($id, 80, ''),
            'menu_id' => isset($item['menu_id']) ? max(0, (int) $item['menu_id']) : null,
            'app_id' => Str::limit($appId, 100, ''),
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
        $preferences['wallpaper_url'] = $this->sanitizeWallpaperUrl((string) ($preferences['wallpaper_url'] ?? ''));
        // 桌面图标三档尺寸（右键「查看」切换）：非法值回退中图标
        $preferences['icon_size'] = in_array($preferences['icon_size'] ?? '', ['small', 'medium', 'large'], true)
            ? $preferences['icon_size']
            : self::DEFAULT_PREFERENCES['icon_size'];
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
        $preferences['window_width'] = $this->sanitizePercent(
            $preferences['window_width'],
            self::DEFAULT_PREFERENCES['window_width']
        );
        $preferences['window_height'] = $this->sanitizePercent(
            $preferences['window_height'],
            self::DEFAULT_PREFERENCES['window_height']
        );
        $preferences['override_admin_home'] = (bool) $preferences['override_admin_home'];
        $preferences['usage_stats'] = $this->sanitizeUsageStats($preferences['usage_stats']);

        return $preferences;
    }

    /**
     * 重置工作区：桌面/任务栏恢复初始默认布局，偏好恢复默认
     * （不影响已上传的自定义壁纸文件）。返回重置后的工作区模型。
     * 超级管理员初始固定「应用中心」；非超管桌面/任务栏为空（应用中心仅超管可用，与前端初始化一致）。
     */
    public function resetForAdmin(int $adminUserId): WebosWorkspace
    {
        $workspace = $this->getForAdmin($adminUserId);
        $isSuperAdmin = (bool) Auth::guard('admin')->user()?->isSuperAdmin();

        $defaultEntry = [
            'id' => 'webos-app-center',
            'menu_id' => null,
            'app_id' => 'cmspro.webos',
            'title' => '应用中心',
            'path' => '/admin/cmspro/webos?app=market',
            'icon' => 'fa fa-shopping-bag',
            'group_title' => 'WebOS',
            'x' => 0,
            'y' => 0,
        ];
        $workspace->desktop_items = $isSuperAdmin ? [$defaultEntry] : [];
        // 任务栏项结构与桌面项一致但无坐标
        $workspace->taskbar_items = $isSuperAdmin ? [Arr::except($defaultEntry, ['x', 'y'])] : [];
        $workspace->preferences = self::DEFAULT_PREFERENCES;
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * 自定义壁纸路径只允许本应用壁纸目录下的相对路径，防止注入外部或越权地址。
     */
    protected function sanitizeWallpaperUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return preg_match('#^apps/cmspro\.webos/wallpapers/[A-Za-z0-9_-]+\.(jpg|jpeg|png|gif|webp)$#', $url)
            ? $url
            : '';
    }

    /**
     * 窗口默认尺寸按可用桌面区域的百分比存储，限制在 40% - 100% 之间。
     */
    protected function sanitizePercent(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return (int) min(100, max(40, (int) round((float) $value)));
    }

    /** @return array<string,array{count:int,last_opened_at:string}> */
    protected function sanitizeUsageStats(mixed $statistics): array
    {
        if (! is_array($statistics)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice($statistics, 0, 80, true) as $key => $statistic) {
            if (
                ! is_string($key)
                || ! preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $key)
                || ! is_array($statistic)
            ) {
                continue;
            }

            $lastOpenedAt = (string) ($statistic['last_opened_at'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastOpenedAt)) {
                continue;
            }

            $sanitized[$key] = [
                'count' => min(999999, max(1, (int) ($statistic['count'] ?? 0))),
                'last_opened_at' => $lastOpenedAt,
            ];
        }

        return $sanitized;
    }
}
