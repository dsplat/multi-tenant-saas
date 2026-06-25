<?php

namespace MultiTenantSaas\Models;

use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileUpload extends Model
{
    use BelongsToTenant, HasGlobalId, HasFactory, SoftDeletes;

    protected $primaryKey = 'file_upload_id';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'hash',
        'category',
        'is_public',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_public' => 'boolean',
            'metadata' => 'array',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function isDocument(): bool
    {
        $docTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        return in_array($this->mime_type, $docTypes);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
