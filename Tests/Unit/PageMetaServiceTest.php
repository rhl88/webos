<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Services\PageMetaService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use Illuminate\Support\Facades\Http;

class PageMetaServiceTest extends WebosTestCase
{
    private PageMetaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PageMetaService();
    }

    /** 仅放行 http(s)：其余协议与空值直接返回空结果且不发起任何请求 */
    public function test_fetch_rejects_non_http_urls(): void
    {
        Http::fake();

        foreach (['', 'file:///etc/passwd', 'ftp://example.com', 'javascript:alert(1)', '/relative/path'] as $url) {
            $this->assertSame(['title' => '', 'icon' => ''], $this->service->fetch($url), "URL {$url} 应被拒绝");
        }
        Http::assertNothingSent();
    }

    /** 正常页面：解析 <title> 文本与 <link rel="icon"> 声明的站点图标（转 base64 data URI） */
    public function test_fetch_parses_title_and_declared_favicon(): void
    {
        Http::fake([
            'https://example.com/' => Http::response(
                '<!DOCTYPE html><html><head><title>  Example 官方站点 &amp; 服务  </title>'
                . '<link rel="shortcut icon" href="/static/logo.png"></head><body>ok</body></html>'
            ),
            'https://example.com/static/logo.png' => Http::response("\x89PNG-fake-bytes", 200, ['Content-Type' => 'image/png']),
        ]);

        $meta = $this->service->fetch('https://example.com/');

        $this->assertSame('Example 官方站点 & 服务', $meta['title']);
        $this->assertSame('data:image/png;base64,' . base64_encode("\x89PNG-fake-bytes"), $meta['icon']);
    }

    /** 页面未声明 favicon 时按约定路径探测根目录 /favicon.ico */
    public function test_fetch_probes_default_favicon_path(): void
    {
        Http::fake([
            'https://plain.example.com/' => Http::response('<html><head><title>Plain Site</title></head></html>'),
            'https://plain.example.com/favicon.ico' => Http::response('ICO', 200, ['Content-Type' => 'image/x-icon']),
        ]);

        $meta = $this->service->fetch('https://plain.example.com/');

        $this->assertSame('Plain Site', $meta['title']);
        $this->assertSame('data:image/x-icon;base64,' . base64_encode('ICO'), $meta['icon']);
    }

    /** 目标站点不可达/超时：返回空值由前端回退域名标题与默认图标，不抛异常 */
    public function test_fetch_falls_back_when_request_fails(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('connection refused');
        });

        $this->assertSame(['title' => '', 'icon' => ''], $this->service->fetch('https://down.example.com/'));
    }

    /** 非 2xx 响应（404/500）与异常 Content-Type 图标均回退空值 */
    public function test_fetch_ignores_error_pages_and_invalid_icons(): void
    {
        Http::fake([
            'https://notfound.example.com/' => Http::response('<html><head><title>404 Page</title></head></html>', 404),
            'https://badicon.example.com/' => Http::response(
                '<html><head><title>Bad Icon</title><link rel="icon" href="/x.exe"></head></html>'
            ),
            'https://badicon.example.com/x.exe' => Http::response('MZ', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $this->assertSame(['title' => '', 'icon' => ''], $this->service->fetch('https://notfound.example.com/'));
        $this->assertSame(['title' => 'Bad Icon', 'icon' => ''], $this->service->fetch('https://badicon.example.com/'));
    }
}
