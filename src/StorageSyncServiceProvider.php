<?php

namespace Yoto\StorageSync;

use Illuminate\Support\ServiceProvider;

class StorageSyncServiceProvider extends ServiceProvider {

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $configPath = __DIR__ . '/config/storagesync.php';
        $publishPath = config_path('storagesync.php');

        $this->publishes([$configPath => $publishPath], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $configPath = __DIR__ . '/config/storagesync.php';
        $this->mergeConfigFrom($configPath, 'storagesync');

        $this->app->singleton('storagesync', function ($app) {
            $disks = $app['config']->get('storagesync.disks');

            return new StorageSync($disks);
        });

        $this->app->alias('storagesync', StorageSync::class);

        $this->commands([
            StorageSyncLocal::class,
            StorageSyncRemote::class,
        ]);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['storagesync'];
    }

}
