<?php

namespace Amp\File;

use Amp\UvReactor;
use Amp\Promise;
use Amp\Failure;
use Amp\Deferred;

/**
 * @codeCoverageIgnore
 */
class UvDriver implements Driver {
    private $reactor;
    private $loop;

    /**
     * @param \Amp\UvReactor $reactor
     */
    public function __construct(UvReactor $reactor) {
        $this->reactor = $reactor;
        $this->loop = $this->reactor->getLoop();
    }

    /**
     * {@inheritdoc}
     */
    public function stat($path) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_stat($this->loop, $path, function($fh, $stat) use ($promisor) {
            if ($fh) {
                $stat["isdir"] = (bool) ($stat["mode"] & \UV::S_IFDIR);
                $stat["isfile"] = empty($stat["isdir"]);
            } else {
                $stat = null;
            }
            $this->reactor->delRef();
            $promisor->succeed($stat);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function lstat($path) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_lstat($this->loop, $path, function($fh, $stat) use ($promisor) {
            if ($fh) {
                $stat["isdir"] = (bool) ($stat["mode"] & \UV::S_IFDIR);
                $stat["isfile"] = empty($stat["isdir"]);
            } else {
                $stat = null;
            }
            $this->reactor->delRef();
            $promisor->succeed($stat);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function symlink($target, $link) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        uv_fs_symlink($this->loop, $target, $link, \UV::S_IRWXU | \UV::S_IRUSR, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function rename($from, $to) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_rename($this->loop, $from, $to, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function unlink($path) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_unlink($this->loop, $path, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($path, $mode = 0644) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_mkdir($this->loop, $path, $mode, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir($path) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_rmdir($this->loop, $path, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function scandir($path) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        uv_fs_readdir($this->loop, $path, 0, function($fh, $data) use ($promisor, $path) {
            $this->reactor->delRef();
            if (empty($fh)) {
                $promisor->fail(new \RuntimeException(
                    "Failed reading contents from {$path}"
                ));
            } else {
                $promisor->succeed($data);
            }
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function chmod($path, $mode) {
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_chmod($this->loop, $path, $mode, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function chown($path, $uid, $gid) {
        // @TODO Return a failure in windows environments
        $this->reactor->addRef();
        $promisor = new Deferred;
        \uv_fs_chown($this->loop, $path, $uid, $gid, function($fh) use ($promisor) {
            $this->reactor->delRef();
            $promisor->succeed((bool)$fh);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function touch($path) {
        $this->reactor->addRef();
        $atime = $mtime = time();
        $promisor = new Deferred;
        \uv_fs_utime($this->loop, $path, $mtime, $atime, function() use ($promisor) {
            // The uv_fs_utime() callback does not receive any args at this time
            $this->reactor->delRef();
            $promisor->succeed(true);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path) {
        return \Amp\resolve($this->doGet($path), $this->reactor);
    }

    private function doGet($path): \Generator {
        $this->reactor->addRef();
        $promise = $this->doFsOpen($path, $flags = \UV::O_RDONLY, $mode = 0);
        if (!$fh = (yield $promise)) {
            $this->reactor->delRef();
            throw new \RuntimeException(
                "Failed opening file handle: {$path}"
            );
        }

        $promisor = new Deferred;
        $stat = (yield $this->doFsStat($fh));
        if (empty($stat)) {
            $this->reactor->delRef();
            $promisor->fail(new \RuntimeException(
                "stat operation failed on open file handle"
            ));
        } elseif (!$stat["isfile"]) {
            \uv_fs_close($this->loop, $fh, function() use ($promisor) {
                $this->reactor->delRef();
                $promisor->fail(new \RuntimeException(
                    "cannot buffer contents: path is not a file"
                ));
            });
        } else {
            $buffer = (yield $this->doFsRead($fh, $offset = 0, $stat["size"]));
            if ($buffer === false ) {
                \uv_fs_close($this->loop, $fh, function() use ($promisor) {
                    $this->reactor->delRef();
                    $promisor->fail(new \RuntimeException(
                        "read operation failed on open file handle"
                    ));
                });
            } else {
                \uv_fs_close($this->loop, $fh, function() use ($promisor, $buffer) {
                    $this->reactor->delRef();
                    $promisor->succeed($buffer);
                });
            }
        }

        yield new \Amp\CoroutineResult(yield $promisor->promise());
    }

    private function doFsOpen($path, $flags, $mode) {
        $promisor = new Deferred;
        \uv_fs_open($this->loop, $path, $flags, $mode, function($fh) use ($promisor, $path) {
            $promisor->succeed($fh);
        });

        return $promisor->promise();
    }

    private function doFsStat($fh) {
        $promisor = new Deferred;
        \uv_fs_fstat($this->loop, $fh, function($fh, $stat) use ($promisor) {
            if ($fh) {
                $stat["isdir"] = (bool) ($stat["mode"] & \UV::S_IFDIR);
                $stat["isfile"] = !$stat["isdir"];
                $promisor->succeed($stat);
            } else {
                $promisor->succeed();
            }
        });

        return $promisor->promise();
    }

    private function doFsRead($fh, $offset, $len) {
        $promisor = new Deferred;
        \uv_fs_read($this->loop, $fh, $offset, $len, function($fh, $nread, $buffer) use ($promisor) {
            $promisor->succeed(($nread < 0) ? false : $buffer);
        });

        return $promisor->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function put($path, $contents) {
        return \Amp\resolve($this->doPut($path, $contents), $this->reactor);
    }

    private function doPut($path, $contents): \Generator {
        $flags = \UV::O_WRONLY | \UV::O_CREAT;
        $mode = \UV::S_IRWXU | \UV::S_IRUSR;
        $this->reactor->addRef();
        $promise = $this->doFsOpen($path, $flags, $mode);
        if (!$fh = (yield $promise)) {
            $this->reactor->delRef();
            throw new \RuntimeException(
                "Failed opening write file handle"
            );
        }

        $promisor = new Deferred;
        $len = strlen($contents);
        \uv_fs_write($this->loop, $fh, $contents, $offset = 0, function($fh, $result) use ($promisor, $len) {
            \uv_fs_close($this->loop, $fh, function() use ($promisor, $result, $len) {
                $this->reactor->delRef();
                if ($result < 0) {
                    $promisor->fail(new \RuntimeException(
                        uv_strerror($result)
                    ));
                } else {
                    $promisor->succeed($len);
                }
            });
        });

        yield new \Amp\CoroutineResult(yield $promisor->promise());
    }
}
