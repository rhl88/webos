<?php

namespace App\Apps\CmsproWebos\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 内置浏览器页面元信息代理。
 *
 * 跨域 iframe 出于同源策略无法读取目标页的 title 与 favicon，由后端代抓后返回：
 * - title：解析 <title> 文本（兼容 GBK/GB2312 中文编码页），最长 60 字符
 * - icon：优先解析 <link rel="icon">，缺省按约定路径探测 /favicon.ico，转 base64 data URI 内联回传
 * 结果按 URL 缓存 1 天；抓取失败返回空值，前端回退显示域名与默认图标。
 */
class PageMetaService
{
    /** favicon 原始字节上限：控制接口响应体积，超出视为异常资源放弃 */
    private const MAX_ICON_BYTES = 30720;

    /** 页面 HTML 只解析前 100KB：title/favicon 均位于头部区域，避免大页面对象化开销 */
    private const MAX_HTML_BYTES = 102400;

    /** @return array{title:string,icon:string} */
    public function fetch(string $url): array
    {
        $url = trim($url);
        // 仅允许 http(s) 地址：后端发起请求必须限制协议，防止 file/ftp 等注入
        if (! preg_match('#^https?://[^\s]+$#i', $url)) {
            return ['title' => '', 'icon' => ''];
        }

        return Cache::remember('webos:page-meta:' . md5($url), now()->addDay(), fn (): array => $this->request($url));
    }

    /** @return array{title:string,icon:string} */
    protected function request(string $url): array
    {
        try {
            $response = Http::timeout(4)
                ->connectTimeout(2)
                ->maxRedirects(2)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; CmsProWebosBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                ])
                ->get($url);
        } catch (Throwable) {
            return ['title' => '', 'icon' => ''];
        }

        if (! $response->successful()) {
            return ['title' => '', 'icon' => ''];
        }

        $html = mb_substr($response->body(), 0, self::MAX_HTML_BYTES);

        return [
            'title' => $this->extractTitle($html),
            'icon' => $this->extractIcon($html, $url),
        ];
    }

    /** 解析 <title> 文本：GBK/GB2312/BIG5 页面先转 UTF-8，再清理标签与多余空白 */
    protected function extractTitle(string $html): string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return '';
        }
        $title = trim($matches[1]);
        if (! preg_match('//u', $title)) {
            $converted = @mb_convert_encoding($title, 'UTF-8', 'GBK,GB2312,BIG5,UTF-8');
            $title = is_string($converted) && $converted !== '' ? $converted : $title;
        }
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr((string) preg_replace('/\s+/u', ' ', $title), 0, 60);
    }

    /** 解析 favicon：页面声明优先，缺省探测根目录 /favicon.ico */
    protected function extractIcon(string $html, string $baseUrl): string
    {
        $iconUrl = '';
        if (preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]*>/i', $html, $matches)
            && preg_match('/href=["\']([^"\']+)["\']/i', $matches[0], $href)) {
            $iconUrl = trim($href[1]);
        }
        if ($iconUrl === '') {
            $parts = parse_url($baseUrl);
            $host = $parts['host'] ?? '';
            if ($host === '') {
                return '';
            }
            $iconUrl = ($parts['scheme'] ?? 'https') . '://' . $host . '/favicon.ico';
        }

        return $this->downloadIcon($this->absoluteUrl($iconUrl, $baseUrl));
    }

    /** 相对地址转绝对地址（支持 //host、/path、相对路径三种形态） */
    protected function absoluteUrl(string $url, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        $parts = parse_url($baseUrl);
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        return $base . '/' . ltrim($url, '/');
    }

    /** 下载图标并转 base64 data URI：限制大小与图片类型，防止把错误页当图标回传 */
    protected function downloadIcon(string $url): string
    {
        if (! preg_match('#^https?://[^\s]+$#i', $url)) {
            return '';
        }
        try {
            $response = Http::timeout(3)->connectTimeout(2)->maxRedirects(2)->get($url);
        } catch (Throwable) {
            return '';
        }
        if (! $response->successful()) {
            return '';
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_ICON_BYTES) {
            return '';
        }
        $type = strtolower($response->header('Content-Type'));
        if ($type !== '' && ! preg_match('#^image/(png|jpe?g|gif|webp|x-icon|vnd\.microsoft\.icon|svg\+xml)#i', $type)) {
            return '';
        }
        $mime = match (true) {
            str_contains($type, 'svg') => 'image/svg+xml',
            str_contains($type, 'x-icon') || str_contains($type, 'microsoft.icon') || $type === '' => 'image/x-icon',
            default => strtok($type, ';') ?: 'image/x-icon',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($body);
    }
}
