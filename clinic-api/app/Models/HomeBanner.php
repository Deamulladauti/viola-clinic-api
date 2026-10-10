<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HomeBanner extends Model
{
    protected $fillable = [
        'media_type',
        'media_path',
    ];

    protected $appends = [
        'media_url',
    ];

    public function getMediaUrlAttribute(): ?string
    {
        return $this->media_path
            ? Storage::disk('public')->url($this->media_path)
            : null;
    }
}