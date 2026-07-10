<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\HasGlobalId;

class SystemSetting extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'setting_id';

    protected $fillable = [
        'group',
        'key',
        'value',
        'is_encrypted',
        'description',
    ];

    protected $attributes = [
        'is_encrypted' => false,
    ];

    protected function casts(): array
    {
        return [
            'setting_id' => 'integer',
            'is_encrypted' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->is_encrypted && $model->isDirty('value')) {
                $value = $model->attributes['value'] ?? null;

                if ($value === null) {
                    return;
                }

                try {
                    $model->attributes['value'] = Crypt::encryptString($value);
                } catch (\Exception $e) {
                    logger()->error('Failed to encrypt system setting', [
                        'group' => $model->group,
                        'key' => $model->key,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        });
    }

    public function getValueAttribute($value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->is_encrypted) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Exception $e) {
                logger()->error('Failed to decrypt system setting', [
                    'group' => $this->group,
                    'key' => $this->key,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($this->isJson($value)) {
            return json_decode($value, true);
        }

        if ($value === 'true' || $value === '1' || $value === 1) {
            return true;
        }
        if ($value === 'false' || $value === '0' || $value === 0) {
            return false;
        }

        return $value;
    }

    public function setValueAttribute($value): void
    {
        if (is_bool($value)) {
            $value = json_encode($value);
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $this->attributes['value'] = $value;
    }

    private function isJson(string $string): bool
    {
        if (! is_string($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $setting = static::where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(
        string $group,
        string $key,
        mixed $value,
        bool $isEncrypted = false,
        ?string $description = null
    ): self {
        return static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $value,
                'is_encrypted' => $isEncrypted,
                'description' => $description,
            ]
        );
    }

    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    public static function setGroup(string $group, array $settings): void
    {
        foreach ($settings as $key => $config) {
            $value = is_array($config) ? $config['value'] : $config;
            $isEncrypted = is_array($config) ? ($config['is_encrypted'] ?? false) : false;
            $description = is_array($config) ? ($config['description'] ?? null) : null;

            static::set($group, $key, $value, $isEncrypted, $description);
        }
    }

    public static function remove(string $group, string $key): bool
    {
        return static::where('group', $group)
            ->where('key', $key)
            ->delete() > 0;
    }
}
