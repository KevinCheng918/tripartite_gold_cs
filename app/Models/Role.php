<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    public const ADMIN_ROLE_NAME = 'admin';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps()->select(['users.id', 'users.name', 'users.email', 'users.status']);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return array<int, string> permission keywords currently loaded on this role
     */
    public function getPermissionKeywordsAttribute(): array
    {
        return $this->permissions->pluck('permission_keyword')->all();
    }
}
