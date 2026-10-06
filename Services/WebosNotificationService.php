<?php

namespace App\Apps\CmsproWebos\Services;

use App\Services\NotificationService;

/**
 * WebOS 通知服务扩展：以子类方式公开全量待办条目。
 *
 * 背景：all-todos 接口需要全局 NotificationService 的 aggregateTodoEntries()（protected），
 * 直接在全局框架服务上新增公开方法会引入框架级改动且线上未部署导致
 * 「Call to undefined method App\Services\NotificationService::allTodos()」报错；
 * 按「一切扩展通过 app/Apps/{AppName}/ 实现」规范，改为应用内子类公开同等能力。
 */
class WebosNotificationService extends NotificationService
{
    /**
     * 全量待办条目（聚合 + 权限过滤 + 去重容错，不做已读过滤）。
     *
     * 已读判定由调用方（WebosController::allTodos）结合 AdminTodoRead 的 seen_count 计算。
     *
     * 兜底：线上框架服务为旧版（无 protected aggregateTodoEntries）时降级为 todos()
     * （仅未读待办，语义降级但不报 500）；部署新版框架文件后自动恢复全量语义。
     */
    public function allTodos(): array
    {
        if (method_exists($this, 'aggregateTodoEntries')) {
            return $this->aggregateTodoEntries();
        }

        return $this->todos();
    }
}
