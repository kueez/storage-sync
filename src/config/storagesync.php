<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Sync
    |
    | Here you may define the mapping of your local and remote storage disks
    | disk names corresponds to names in config/filesystems.php
    |--------------------------------------------------------------------------
    */

    'disks' => [
        'posts' => [
            'local' => 'posts_local',
            'remote' => env('POSTS_DISK', 'posts_local_public')
        ],

        'static' => [
            'local' => 'static_local',
            'remote' => env('STATIC_DISK', 'static_local_public')
        ]
    ]

];
