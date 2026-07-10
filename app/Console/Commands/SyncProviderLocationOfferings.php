<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Exception;
use Illuminate\Console\Command;

class SyncProviderLocationOfferings extends Command
{
    protected $signature = 'provider:sync-location-offerings {--server= : A single provider server ID}';

    protected $description = 'Synchronize provider location inventory into sellable location offerings';

    public function handle(): int
    {
        $servers = Server::query()
            ->where('enabled', true)
            ->when($this->option('server'), fn ($query, $serverId) => $query->whereKey($serverId))
            ->get();

        $synced = 0;
        foreach ($servers as $server) {
            if (!ExtensionHelper::hasFunction($server, 'syncLocationOfferings')) {
                continue;
            }

            try {
                $count = (int) ExtensionHelper::call($server, 'syncLocationOfferings', [$server]);
                $synced += $count;
                $this->line(sprintf('%s: %s location offerings synchronized.', $server->name, $count));
            } catch (Exception $exception) {
                $this->error(sprintf('%s: %s', $server->name, $exception->getMessage()));
            }
        }

        $this->info(sprintf('Synchronized %s provider location offerings.', $synced));

        return self::SUCCESS;
    }
}
