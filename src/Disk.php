<?php

namespace Yoto\StorageSync;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;

class Disk
{
    /**
     * @var \Illuminate\Filesystem\FilesystemAdapter
     */
    protected $local;

    /**
     * @var \Illuminate\Filesystem\FilesystemAdapter
     */
    protected $remote;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $localPath;

    /**
     * @var FilesystemAdapter[]
     */
    protected $remoteReplicas;

    /**
     * Disk constructor.
     *
     * @param FilesystemAdapter $local
     * @param FilesystemAdapter $remote
     * @param string $baseUrl
     * @param string $localPath
     * @param array $remoteReplicas
     */
    public function __construct(FilesystemAdapter $local, FilesystemAdapter $remote, $baseUrl, $localPath, array $remoteReplicas = [])
    {
        $this->local = $local;
        $this->remote = $remote;
        $this->baseUrl = $baseUrl;
        $this->localPath = $localPath;
        $this->remoteReplicas = $remoteReplicas;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }


    /**
     * @param string $path
     * @param bool $getFromRemoteIfAbsent
     * @return string
     */
    public function getLocalPath($path = false, $getFromRemoteIfAbsent = false)
    {
        $full = $this->localPath;

        if ($path) {
            $full .= '/'.$path;

            // only if path includes dot
            if ($getFromRemoteIfAbsent && Str::contains($path, '.') && ($getFromRemoteIfAbsent === 'force' || !$this->local->exists($path))) {
                $remote = $this->read($path, $this->remote);

                if ($remote) {
                    $this->writeLocal($path, $remote);
                }
            }
        }

        return $full;
    }

    /**
     * Check if file exists
     *
     * @param $path
     * @return bool
     */
    public function exists($path)
    {
        if ($this->local->exists($path)) {
            return true;
        }

        else if ($this->remote->exists($path)) {
            return true;
        }

        return false;
    }

    /**
     * Get file contents
     *
     * @param $path
     * @return bool|string
     */
    public function get($path)
    {
        $local = $this->read($path, $this->local);

        if ($local) {
            return $local;
        }

        $remote = $this->read($path, $this->remote);

        if ($remote) {
            $this->writeLocal($path, $remote);

            return $remote;
        }

        return false;
    }

    /**
     * Put data to file
     *
     * @param $path
     * @param $contents
     * @return bool
     */
    public function put($path, $contents)
    {
        $local = $this->writeLocal($path, $contents);

        if (!$local) {
            app('log')->error('Failed to put '.$path.' on local');
            return false;
        }

        $remote = $this->write($path, $contents, $this->remote);

        if (!$remote) {
            app('log')->error('Failed to put '.$path.' on remote');
            return false;
        }

        foreach ($this->remoteReplicas as  $remoteReplica) {
            $remoteReplicaRes = $this->write($path, $contents, $remoteReplica);

            if (!$remoteReplicaRes) {
                app('log')->error('Failed to put '.$path.' on remote replica');
                return false;
            }
        }

        return true;
    }

    /**
     * Delete file
     *
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        $responses = [];
        $responses[] = $this->deleteQuietly($path, $this->local);
        $responses[] = $this->deleteQuietly($path, $this->remote);

        foreach($this->remoteReplicas as $remoteReplica) {
            $responses[] = $this->deleteQuietly($path, $remoteReplica);
        }

        foreach($responses as $response) {
            if (!$response) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete directory
     *
     * @param $path
     * @return bool
     * @throws StorageSyncException
     */
    public function deleteDirectory($path)
    {
        if (empty($path) || $path == '/') {
            throw new StorageSyncException('Cannot delete root directory.');
        }

        $responses = [];
        $responses[] = $this->deleteDirectoryQuietly($path, $this->local);
        $responses[] = $this->deleteDirectoryQuietly($path, $this->remote);

        foreach($this->remoteReplicas as $remoteReplica) {
            $responses[] = $this->deleteDirectoryQuietly($path, $remoteReplica);
        }

        foreach($responses as $response) {
            if (!$response) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sync remote file to local
     *
     * @param $path
     * @param bool $delete
     * @param null|FilesystemAdapter $disk
     * @return bool
     */
    public function sync($path, $delete = false, FilesystemAdapter $disk = null)
    {
        if (!$disk) {
            $disk = $this->remote;
        }

        $contents = $this->read($path, $disk);

        if ($contents) {
            $writeResult = $this->writeLocal($path, $contents);

            if ($writeResult) {
                $localPath = $this->local->path($path);
                @chmod($localPath, 0775);
            }

            return $writeResult;
        }

        if ($delete) {
            return $this->deleteQuietly($path, $this->local);
        }

        return false;
    }

    /**
     * Sync files from remote to local directory
     *
     * @param $path
     * @param bool $delete
     * @param null|FilesystemAdapter $disk
     * @param bool $forceRewrite
     * @param callable|null $operationCallback
     * @return bool
     */
    public function syncDirectory($path, $delete = false, FilesystemAdapter $disk = null, $forceRewrite = false, $operationCallback = null)
    {
        if (!$disk) {
            $disk = $this->remote;
        }

        $remote = $this->filterFiles($disk->getDriver()->listContents($path, true));
        $local = $this->filterFiles($this->local->getDriver()->listContents($path, true));

        foreach ($remote as $remoteFile) {
            $localFile = Arr::first($local, function($file) use($remoteFile) {
                return $file['path'] === $remoteFile['path'];
            });

            if (!$localFile || ($forceRewrite || intval($localFile['size']) !== intval($remoteFile['size']))) {
                $contents = $this->read($remoteFile['path'], $disk);

                if ($contents) {
                    if (!$this->writeLocal($remoteFile['path'], $contents)) {
                        app('log')->error('Cannot write to '.$remoteFile['path'].' on local from remote path');

                        return false;
                    }

                    // set file permissions
                    $localPath = $this->local->path($remoteFile['path']);
                    @chmod($localPath, 0775);

                    if (is_callable($operationCallback)) {
                        $operationCallback('write', $remoteFile['path']);
                    }
                }
            }
        }

        if ($delete) {
            foreach ($local as $localFile) {
                $remoteFile = Arr::first($remote, function($file) use($localFile) {
                    return $file['path'] === $localFile['path'];
                });

                if (!$remoteFile && Str::contains($localFile['path'], '/')) {
                    if ($this->deleteQuietly($localFile['path'], $this->local)) {
                        if (is_callable($operationCallback)) {
                            $operationCallback('delete', $localFile['path']);
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Sync local file to remote
     *
     * @param $path
     * @param bool $delete
     * @param null|\Illuminate\Filesystem\FilesystemAdapter $disk
     * @return bool
     */
    public function syncRemote($path, $delete = false, FilesystemAdapter $disk = null)
    {
        if (!$disk) {
            $disk = $this->remote;
        }

        $contents = $this->read($path, $this->local);

        if ($contents) {
            $responses = [];
            $responses[] = $this->write($path, $contents, $disk);

            foreach($this->remoteReplicas as $remoteReplica) {
                $responses[] = $this->write($path, $contents, $remoteReplica);
            }

            foreach($responses as $response) {
                if (!$response) {
                    return false;
                }
            }

            return true;
        }

        if ($delete) {
            $responses = [];
            $responses[] = $this->deleteQuietly($path, $disk);

            foreach($this->remoteReplicas as $remoteReplica) {
                $responses[] = $this->deleteQuietly($path, $remoteReplica);
            }

            foreach($responses as $response) {
                if (!$response) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Sync files from local to remote directory
     *
     * @param $path
     * @param bool $delete
     * @param null|\Illuminate\Filesystem\FilesystemAdapter $disk
     * @param bool $forceRewrite
     * @param null $operationCallback
     * @return bool
     */
    public function syncRemoteDirectory($path, $delete = false, FilesystemAdapter $disk = null, $forceRewrite = false, $operationCallback = null)
    {
        if (!$disk) {
            $disk = $this->remote;
        }

        $remote = $this->filterFiles($disk->getDriver()->listContents($path, true));
        $local = $this->filterFiles($this->local->getDriver()->listContents($path, true));

        foreach ($local as $localFile) {
            $remoteFile = Arr::first($remote, function($file) use($localFile) {
                return $file['path'] === $localFile['path'];
            });

            if (!$remoteFile || ($forceRewrite || intval($remoteFile['size']) !== intval($localFile['size']))) {
                if (Str::startsWith($localFile['path'], '.') || Str::endsWith($localFile['path'], ['.map'])) {
                    continue;
                }

                $contents = $this->read($localFile['path'], $this->local);

                if ($contents) {
                    if (!$this->write($localFile['path'], $contents, $disk)) {
                        app('log')->error('Cannot write to '.$localFile['path'].' on remote from local path');
                        return false;
                    }

                    foreach($this->remoteReplicas as $remoteReplica) {
                        if (!$this->write($localFile['path'], $contents, $remoteReplica)) {
                            app('log')->error('Cannot write to '.$localFile['path'].' on remote replica from local path');
                            return false;
                        }
                    }

                    if (is_callable($operationCallback)) {
                        $operationCallback('write', $localFile['path']);
                    }
                }
            }
        }

        if ($delete) {
            foreach ($remote as $remoteFile) {
                $localFile = Arr::first($local, function($file) use($remoteFile) {
                    return $file['path'] === $remoteFile['path'];
                });

                if (!$localFile && Str::contains($remoteFile['path'], '/')) {
                    $this->deleteQuietly($remoteFile['path'], $disk);

                    foreach($this->remoteReplicas as $remoteReplica) {
                        $this->deleteQuietly($remoteFile['path'], $remoteReplica);
                    }

                    if (is_callable($operationCallback)) {
                        $operationCallback('delete', $remoteFile['path']);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Get local disk instance
     *
     * @return FilesystemAdapter
     */
    public function getLocalDisk()
    {
        return $this->local;
    }

    /**
     * Get remote disk instance
     *
     * @return FilesystemAdapter
     */
    public function getRemoteDisk()
    {
        return $this->remote;
    }

    /**
     * Write file to disk
     *
     * @param $path
     * @param $contents
     * @param FilesystemAdapter $disk
     * @return bool
     */
    protected function write($path, $contents, FilesystemAdapter $disk)
    {
        $config = [];

        if (Str::endsWith($path, '.woff')) {
            $config['ContentType'] = 'application/font-woff';
        } elseif (Str::endsWith($path, '.woff2')) {
            $config['ContentType'] = 'application/font-woff2';
        }

        try {
            $disk->getDriver()->write($path, $contents, $config);

            return true;
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * Write file to local disk
     *
     * @param $path
     * @param $contents
     * @return bool
     */
    protected function writeLocal($path, $contents)
    {
        try {
            $this->local->getDriver()->write($path, $contents);

            return true;
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * Read file contents from disk
     *
     * @param $path
     * @param FilesystemAdapter $disk
     * @return string|false
     */
    protected function read($path, FilesystemAdapter $disk)
    {
        try {
            return $disk->getDriver()->read($path);
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * Delete file from disk without raising errors
     *
     * @param $path
     * @param FilesystemAdapter $disk
     * @return bool
     */
    protected function deleteQuietly($path, FilesystemAdapter $disk)
    {
        try {
            $disk->getDriver()->delete($path);

            return true;
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * Delete directory from disk without raising errors
     *
     * @param $path
     * @param FilesystemAdapter $disk
     * @return bool
     */
    protected function deleteDirectoryQuietly($path, FilesystemAdapter $disk)
    {
        try {
            $disk->getDriver()->deleteDirectory($path);

            return true;
        } catch (FilesystemException $exception) {
            return false;
        }
    }

    /**
     * Filter files
     *
     * @param  iterable  $contents
     * @return array
     */
    protected function filterFiles($contents)
    {
        return Collection::make($contents)
            ->filter(function($object) {
                return $object instanceof StorageAttributes && $object->isFile() && !empty(pathinfo($object->path(), PATHINFO_FILENAME));
            })
            ->map(function(FileAttributes $file) {
                return ['path' => $file->path(), 'size' => $file->fileSize()];
            })
            ->values()
            ->all();
    }
}
