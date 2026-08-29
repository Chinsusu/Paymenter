<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptProxyCredentials extends Command
{
    protected $signature = 'proxy:encrypt-credentials';

    protected $description = 'Encrypt existing HAV Proxy IPv4 DC credentials stored in service properties';

    public function handle(): int
    {
        $updated = 0;
        DB::table('properties')
            ->whereIn('key', ['hav_proxy_ipv4_dc_username', 'hav_proxy_ipv4_dc_password', 'hav_proxy_ipv4_dc_connection_uri'])
            ->orderBy('id')
            ->each(function (object $property) use (&$updated) {
                $rawValue = $property->value;
                if (str_starts_with((string) $rawValue, 'encrypted:')) {
                    return;
                }

                // Avoid creating audit records that contain the legacy plaintext value.
                DB::table('properties')->where('id', $property->id)->update([
                    'value' => 'encrypted:' . Crypt::encryptString((string) $rawValue),
                    'updated_at' => now(),
                ]);
                $updated++;
            });

        $this->info(sprintf('Encrypted %s proxy credential properties.', $updated));

        return self::SUCCESS;
    }
}
