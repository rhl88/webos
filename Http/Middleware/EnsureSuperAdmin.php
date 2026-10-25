<?php

namespace App\Apps\CmsproWebos\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * 「管理桌面/用户桌面」预设接口的超级管理员门禁（2.1.104）：
 * OS 设置中「覆盖传统后台」双开关及其对应的管理桌面、用户桌面预设为全局设置，
 * 仅 super_admin 角色可用；普通管理员访问统一 403（响应结构与框架 CheckPermission
 * 一致：code 40101「无访问权限」），普通管理员对全局桌面布局不可见也不可改。
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('admin')->user()?->isSuperAdmin()) {
            return response()->json(ApiResponse::error(40101, '无访问权限'), 403);
        }

        return $next($request);
    }
}
