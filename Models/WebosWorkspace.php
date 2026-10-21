<?php

namespace App\Apps\CmsproWebos\Models;

use App\Models\AdminUser;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebosWorkspace extends BaseModel
{
    protected $table = 'app_cmspro_webos_workspaces';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'terminal',
        'admin_user_id',
        'desktop_items',
        'taskbar_items',
        'preset_sync_ids',
        'preferences',
        'status',
    ];

    protected $casts = [
        'desktop_items' => 'array',
        'taskbar_items' => 'array',
        'preset_sync_ids' => 'array',
        'preferences' => 'array',
        'status' => 'integer',
    ];

    /**
     * 同步台账（已推送的后台预设桌面项 ID）仅服务端内部使用，接口直接序列化模型，
     * 需隐藏避免内部实现细节外泄。
     */
    protected $hidden = ['preset_sync_ids'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
