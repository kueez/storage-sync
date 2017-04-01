<?php

namespace Yoto\StorageSync;

use Illuminate\Support\Facades\Facade;

class StorageSyncFacade extends Facade {

    /**
     * Return facade accessor
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'storagesync';
    }
}