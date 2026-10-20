<?php

namespace App\Apps\CmsproWebos\Http\Middleware;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * 「覆盖传统后台」登录页接管（2.1.79）：开启后未登录访问框架登录页 /admin/login
 * 临时重定向（302）到 WebOS 登录窗口 /admin/cmspro/webos。
 *
 * 安全边界：
 * - 仅拦 GET：POST /admin/login 是框架登录提交路由，拦截会导致永远无法登录；
 * - 已登录访问不拦：由框架原有逻辑处理，且 WebOS 桌面对已登录管理员直接可用；
 * - 必须用 302 而非 301：301 会被浏览器/代理永久缓存，关闭开关或停用应用后
 *   旧缓存仍会跳转，传统登录页将不可达（只能清缓存自救）；
 * - 判定异常时放行框架登录页：登录可用性优先于覆盖体验。
 */
class RedirectAdminLoginToWebos
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('get')
            && $request->routeIs('admin.login')
            && ! auth('admin')->check()) {
            try {
                if (WorkspaceService::anyAdminOverridesAdminHome()) {
                    return redirect('/admin/cmspro/webos');
                }
            } catch (Throwable) {
                // 判定异常（如应用表未就绪）时放行框架登录页
            }
        }

        return $next($request);
    }
}
