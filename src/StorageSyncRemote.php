<?php

namespace Yoto\StorageSync;

use Illuminate\Console\Command;
use Storage;
use StorageSync;

class StorageSyncRemote extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:sync:remote {disk} {--local=default : Local filesystem to sync from. Defaults to local filesystem of chosen disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync remote disk from local';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $disk = $this->argument('disk');
        $localFilesystem = $this->option('local') == 'default' ? null : Storage::disk($this->option('local'));
        $service = StorageSync::disk($disk);

        if ($this->confirm('Are you  sure? [yes|no]')) {
            $this->info('Syncing remote '.$disk.' from local...');

            $result = $service->syncRemoteDirectory('', true, $localFilesystem, false, function ($operation, $path) {
                $this->line($operation . ': ' . $path);
            });

            if ($result) {
                $this->info('Remote disk '.$disk.' successfully synced.');
            }

            else {
                $this->error('Remote disk '.$disk.' sync failed.');
            }
        }
    }
}
