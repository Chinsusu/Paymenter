<?php

namespace App\Models;

use App\Observers\PropertyObserver;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([PropertyObserver::class])]
class Property extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    private const ENCRYPTED_VALUE_PREFIX = 'encrypted:';

    public const SENSITIVE_KEYS = [
        'hav_proxy_ipv4_dc_username',
        'hav_proxy_ipv4_dc_password',
        'hav_proxy_ipv4_dc_connection_uri',
    ];

    public $guarded = [];

    public static function isSensitiveKey(?string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): string {
                if (!self::isSensitiveKey($this->key) || !str_starts_with((string) $value, self::ENCRYPTED_VALUE_PREFIX)) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_VALUE_PREFIX)));
                } catch (DecryptException) {
                    return '';
                }
            },
            set: function (?string $value): string {
                if (!self::isSensitiveKey($this->key) || str_starts_with((string) $value, self::ENCRYPTED_VALUE_PREFIX)) {
                    return (string) $value;
                }

                return self::ENCRYPTED_VALUE_PREFIX . Crypt::encryptString((string) $value);
            },
        );
    }

    public function parent_property()
    {
        return $this->belongsTo(CustomProperty::class, 'custom_property_id');
    }

    public function model()
    {
        return $this->morphTo();
    }
}
