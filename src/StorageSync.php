<?php

namespace Yoto\StorageSync;


class StorageSync
{
    /**
     * @var \Illuminate\Filesystem\FilesystemManager
     */
    protected $manager;

    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var array|Disk[]
     */
    protected $disks;

    /**
     * StorageSync constructor.
     *
     * @param array $disks
     */
    public function __construct(array $disks)
    {
        $this->manager = app('filesystem');
        $this->mapping = [];
        $this->disks = [];

        foreach ($disks as $name => $config) {
            $this->mapping[$name] = $config;
        }
    }

    /**
     * Get disk
     *
     * @param $name
     * @return Disk
     * @throws StorageSyncException
     */
    public function disk($name)
    {
        if (!isset($this->disks[$name])) {
            if (!isset($this->mapping[$name])) {
                throw new StorageSyncException('StorageSync: disk '.$name.' does not exist.');
            }

            $localDisk = $this->mapping[$name]['local'];
            $remoteDisk = $this->mapping[$name]['remote'];

            if ($remoteDisk == $localDisk) {
                throw new StorageSyncException('StorageSync: local and remote cannot be the same disk.');
            }

            $local = $this->manager->disk($localDisk);
            $remote = $this->manager->disk($remoteDisk);
            $baseUrl = config('filesystems.disks.'.$remoteDisk.'.base_url');
            $localPath = config('filesystems.disks.'.$localDisk.'.root');
            $remoteReplicas = [];

            if (!empty($this->mapping[$name]['enable_remote_replicas']) &&
                $this->mapping[$name]['enable_remote_replicas'] &&
                !empty($this->mapping[$name]['remote_replicas'])) {
                foreach ($this->mapping[$name]['remote_replicas'] as $remoteReplica) {
                    $remoteReplicas[] = $this->manager->disk($remoteReplica);
                }
            }

            $this->disks[$name] = new Disk($local, $remote, $baseUrl, $localPath, $remoteReplicas);
        }

        return $this->disks[$name];
    }
}
