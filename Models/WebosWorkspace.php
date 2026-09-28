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
        'admin_user_id',
        'desktop_items',
        'preferences',
        'status',
    ];

    protected $casts = [
        'desktop_items' => 'array',
        'preferences' => 'array',
        'status' => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
