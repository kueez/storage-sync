<?php

namespace Yoto\StorageSync;

use Illuminate\Console\Command;

class StorageSyncRemote extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:sync:remote {disk} {--remote=default : Remote filesystem to sync to. Defaults to remote filesystem of chosen disk}';

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
        $remoteFilesystem = $this->option('remote') == 'default' ? null : app('filesystem')->disk($this->option('remote'));
        $service = app('storagesync')->disk($disk);

        if ($this->confirm('Are you  sure? [yes|no]')) {
            $this->info('Syncing remote '.$disk.' from local...');

            $result = $service->syncRemoteDirectory('', true, $remoteFilesystem, false, function ($operation, $path) {
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
