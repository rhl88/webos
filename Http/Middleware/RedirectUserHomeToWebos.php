<?php

namespace App\Apps\CmsproWebos\Http\Middleware;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * 「用户首页直达 WebOS」（2.1.82）：任一后台管理员在 OS 设置开启后，
 * 前台用户访问用户首页 /user 临时重定向（302）到 WebOS 桌面 /user/cmspro/webos。
 *
 * 安全边界（与 RedirectAdminLoginToWebos 同口径）：
 * - 仅拦 user.index 首页路由：用户端其余页面（dashboard/account/应用页）不受影响；
 * - 未登录不拦：路由组 auth:web 中间件随后会将访客送往用户登录页；
 * - 必须用 302 而非 301：301 会被浏览器/代理永久缓存，关闭开关或停用应用后
 *   旧缓存仍会跳转，传统用户首页将不可达（只能清缓存自救）；
 * - 地址带 skip_webos=1 可临时绕过（与后台首页覆盖一致）；
 * - 判定异常时放行框架用户首页：可用性优先于覆盖体验。
 */
class RedirectUserHomeToWebos
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get')
            && $request->routeIs('user.index')
            && ! $request->boolean('skip_webos')
            && auth('web')->check()) {
            try {
                if (WorkspaceService::anyAdminOverridesUserHome()) {
                    // 与 RedirectAdminLoginToWebos 一致使用字面量路径（不依赖路由名解析，
                    // 测试环境应用路由按需手动注册，字面量更稳妥）
                    return redirect('/user/cmspro/webos');
                }
            } catch (Throwable) {
                // 判定异常（如应用表未就绪）时放行框架用户首页
            }
        }

        return $next($request);
    }
}
