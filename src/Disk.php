<?php

namespace Yoto\StorageSync;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;

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
     * Disk constructor.
     *
     * @param FilesystemAdapter $local
     * @param FilesystemAdapter $remote
     * @param string $baseUrl
     * @param string $localPath
     */
    public function __construct(FilesystemAdapter $local, FilesystemAdapter $remote, $baseUrl, $localPath)
    {
        $this->local = $local;
        $this->remote = $remote;
        $this->baseUrl = $baseUrl;
        $this->localPath = $localPath;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }


    /**
     * @param bool $path
     * @param bool $getFromRemoteIfAbsent
     * @return string
     */
    public function getLocalPath($path = false, $getFromRemoteIfAbsent = false)
    {
        $full = $this->localPath;

        if ($path) {
            $full .= '/'.$path;

            // only if path includes dot 
            if ($getFromRemoteIfAbsent && str_contains($path, '.') && !$this->local->exists($path)) {
                $remote = $this->remote->get($path);

                if ($remote) {
                    $this->local->getDriver()->write($path, $remote);
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
        $local = $this->local->get($path);

        if ($local) {
            return $local;
        }

        $remote = $this->remote->get($path);

        if ($remote) {
            $this->local->getDriver()->write($path, $remote);

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
        $local = $this->local->getDriver()->write($path, $contents);

        if ($local) {
            $remote = $this->write($path, $contents, $this->remote);

            if ($remote) {
                return true;
            }

            app('log')->error('Failed to put '.$path.' on remote');
        }

        else {
            app('log')->error('Failed to put '.$path.' on local');
        }

        return false;
    }

    /**
     * Delete file
     *
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        $local = @$this->local->delete($path);
        $remote = @$this->remote->delete($path);

        return $local && $remote;
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

        $local = @$this->local->deleteDirectory($path);
        $remote = @$this->remote->deleteDirectory($path);

        return $local && $remote;
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

        $contents = $disk->get($path);

        if ($contents) {
            $writeResult = $this->local->getDriver()->write($path, $contents);

            if ($writeResult) {
                $localPath = $this->local->getDriver()->getAdapter()->applyPathPrefix($path);
                @chmod($localPath, 0775);
            }

            return $writeResult;
        }

        if ($delete) {
            return @$this->local->delete($path);
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
            $localFile = array_first($local, function($file) use($remoteFile) {
                return $file['path'] === $remoteFile['path'];
            });

            if (!$localFile || ($forceRewrite || $localFile['size'] !== $remoteFile['size'])) {
                $contents = $disk->get($remoteFile['path']);

                if ($contents) {
                    if (!$this->local->getDriver()->write($remoteFile['path'], $contents)) {
                        app('log')->error('Cannot write to '.$remoteFile['path'].' on local from remote path');

                        return false;
                    }

                    // set file permissions
                    $localPath = $this->local->getDriver()->getAdapter()->applyPathPrefix($remoteFile['path']);
                    @chmod($localPath, 0775);

                    if (is_callable($operationCallback)) {
                        $operationCallback('write', $remoteFile['path']);
                    }
                }
            }
        }

        if ($delete) {
            foreach ($local as $localFile) {
                $remoteFile = array_first($remote, function($file) use($localFile) {
                    return $file['path'] === $localFile['path'];
                });

                // if not in local and not first level file, delete it.
                if (!$remoteFile && str_contains($localFile['path'], '/')) {
                    if ($this->local->delete($localFile['path'])) {
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

        $contents = $this->local->get($path);

        if ($contents) {
            return $this->write($path, $contents, $disk);
        }

        if ($delete) {
            return @$disk->delete($path);
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
            $remoteFile = array_first($remote, function($file) use($localFile) {
                return $file['path'] === $localFile['path'];
            });

            if (!$remoteFile || ($forceRewrite || $remoteFile['size'] !== $localFile['size'])) {
                // disallow files
                if (starts_with($localFile['path'], '.') || ends_with($localFile['path'], ['.map'])) {
                    continue;
                }

                $contents = $this->local->get($localFile['path']);

                if ($contents) {
                    if (!$this->write($localFile['path'], $contents, $disk)) {
                        app('log')->error('Cannot write to '.$localFile['path'].' on remote from local path');

                        return false;
                    }

                    if (is_callable($operationCallback)) {
                        $operationCallback('write', $localFile['path']);
                    }
                }
            }
        }

        if ($delete) {
            foreach ($remote as $remoteFile) {
                $localFile = array_first($local, function($file) use($remoteFile) {
                    return $file['path'] === $remoteFile['path'];
                });

                // if not in local and not first level file, delete it.
                if (!$localFile && str_contains($remoteFile['path'], '/')) {
                    $disk->delete($remoteFile['path']);

                    if (is_callable($operationCallback)) {
                        $operationCallback('delete', $remoteFile['path']);
                    }
                }
            }
        }

        return true;
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

        if (ends_with($path, '.woff')) {
            $config['ContentType'] = 'application/font-woff';
        }

        else if (ends_with($path, '.woff2')) {
            $config['ContentType'] = 'application/font-woff2';
        }

        return $disk->getDriver()->write($path, $contents, $config);
    }

    /**
     * Filter files
     *
     * @param  array  $contents
     * @return array
     */
    protected function filterFiles($contents)
    {
        return Collection::make($contents)
            ->filter(function($object) {
                return $object && isset($object['type']) && $object['type'] == 'file' && !empty($object['filename']);
            })
            ->values()
            ->all();
    }
}