<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * Spatie Permissions guard — REQUIRED so roles resolve correctly
     * for both Sanctum token auth and web.
     */
    protected $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password',

        // profile fields
        'phone',
        'avatar_path',
        'address_line1',
        'address_line2',
        'city',
        'country_code',
        'preferred_language',
        'marketing_opt_in',
        'notifications_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // (optional) hide Spatie pivot if you eager-load roles
        'roles.*.pivot',
    ];

    /**
     * (Optional) Always eager-load roles so UserResource has them
     * without extra queries.
     */
    protected $with = ['roles'];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'is_active'             => 'boolean',
            'marketing_opt_in'      => 'boolean',
            'notifications_enabled' => 'boolean',
        ];
    }

    public function staff()
    {
        return $this->hasOne(Staff::class);
    }

    // Accessor to expose a public URL
    protected $appends = ['avatar_url'];

   public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar_path) {
            return null;
        }

        return asset('storage/' . $this->avatar_path);
    }

    public function servicePackages()
    {
        return $this->hasMany(\App\Models\ServicePackage::class);
    }
}
