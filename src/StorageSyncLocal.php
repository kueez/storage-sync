<?php

namespace Yoto\StorageSync;

use Illuminate\Console\Command;

class StorageSyncLocal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:sync:local {disk} {--remote=default : Remote filesystem to sync from. Defaults to remote filesystem of chosen disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync local disk from remote';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $disk = $this->argument('disk');
        $remoteFilesystem = $this->option('remote') == 'default' ? null : app('filesystem')->disk($this->option('remote'));
        $service = app('storagesync')->disk($disk);

        if ($this->confirm('Are you  sure? [yes|no]')) {
            $this->info('Syncing local '.$disk.' from remote...');

            $result = $service->syncDirectory('', true, $remoteFilesystem, false, function ($operation, $path) {
                $this->line($operation . ': ' . $path);
            });

            if ($result) {
                $this->info('Local disk '.$disk.' successfully synced.');
            }

            else {
                $this->error('Local disk '.$disk.' sync failed.');
            }
        }
    }
}
