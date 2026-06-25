<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasGlobalId, HasFactory;

    protected $primaryKey = 'notification_preference_id';

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'channel',
        'type',
        'enabled',
        'options',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'options' => 'array',
    ];

    /**
     * 支持的通知通道
     */
    public const CHANNELS = ['database', 'mail', 'broadcast'];

    /**
     * 支持的通知类型
     */
    public const TYPES = [
        'general',
        'tenant_suspended',
        'credit_low',
        'subscription_expiring',
        'payment_success',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 检查用户是否启用了某通道的通知
     */
    public static function isEnabled(int $userId, string $channel, ?string $type = null): bool
    {
        // 先查类型特定的偏好
        if ($type) {
            $pref = static::where('user_id', $userId)
                ->where('channel', $channel)
                ->where('type', $type)
                ->first();
            if ($pref) {
                return $pref->enabled;
            }
        }

        // 再查全局默认偏好
        $globalPref = static::where('user_id', $userId)
            ->where('channel', $channel)
            ->whereNull('type')
            ->first();
        if ($globalPref) {
            return $globalPref->enabled;
        }

        // 默认启用
        return true;
    }

    /**
     * 设置用户通知偏好
     */
    public static function setPreference(int $userId, string $channel, ?string $type, bool $enabled): self
    {
        return static::updateOrCreate(
            [
                'user_id' => $userId,
                'channel' => $channel,
                'type' => $type,
            ],
            ['enabled' => $enabled]
        );
    }

    /**
     * 获取用户所有偏好
     */
    public static function getUserPreferences(int $userId): array
    {
        $prefs = static::where('user_id', $userId)->get();
        $result = [];

        foreach (self::CHANNELS as $channel) {
            $result[$channel] = [
                'global' => true,
                'types' => [],
            ];
        }

        foreach ($prefs as $pref) {
            if ($pref->type === null) {
                $result[$pref->channel]['global'] = $pref->enabled;
            } else {
                $result[$pref->channel]['types'][$pref->type] = $pref->enabled;
            }
        }

        return $result;
    }

    /**
     * 初始化用户默认偏好
     */
    public static function initDefaults(int $userId): void
    {
        foreach (self::CHANNELS as $channel) {
            static::firstOrCreate([
                'user_id' => $userId,
                'channel' => $channel,
                'type' => null,
            ], ['enabled' => true]);
        }
    }
}
