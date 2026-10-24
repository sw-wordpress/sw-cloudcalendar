<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Storage;

use SW\Calendar\Application\Attachments\StoredObject;
use SW\Calendar\Application\Port\AttachmentStorageInterface;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;

/**
 * Private attachment storage below a single root directory.
 *
 * - Keys are random hex names generated here (`ab/cd/<64 hex>[.p]`) and validated by a strict pattern on every use,
 *   so no external value ever becomes part of a path.
 * - Every resolved path is checked for containment in the real root and for symlinks on the way.
 * - Writes go to a temporary file in the same file system and are renamed atomically.
 */
final class PrivateFileStorage implements AttachmentStorageInterface
{
    private const KEY_PATTERN = '~^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}(\.p)?$~';

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Creates the root (0700) with deny files for web servers that honour them. Protection is verified separately.
     *
     * @throws CalendarException STORAGE_UNSAFE
     */
    public function prepare(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The private storage directory cannot be created.');
        }
        if (is_link($this->root)) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The private storage directory must not be a symlink.');
        }
        $files = [
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        ];
        foreach ($files as $name => $content) {
            $path = $this->root . '/' . $name;
            if (!is_file($path)) {
                file_put_contents($path, $content);
            }
        }
        foreach (['tmp'] as $dir) {
            if (!is_dir($this->root . '/' . $dir)) {
                mkdir($this->root . '/' . $dir, 0700, true);
            }
        }
    }

    public function newKey(string $suffix = ''): string
    {
        $name = bin2hex(random_bytes(32));
        return substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . $name . ($suffix === 'p' ? '.p' : '');
    }

    public function temporaryFile(): string
    {
        $this->prepare();
        return $this->root . '/tmp/' . bin2hex(random_bytes(16)) . '.part';
    }

    public function putFromTemp(string $temporaryFile, string $storageKey): StoredObject
    {
        $target = $this->path($storageKey);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The storage directory cannot be created.');
        }
        $this->assertContained($directory);
        if (!is_file($temporaryFile) || is_link($temporaryFile)) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The temporary file is missing.');
        }
        $hash = hash_file('sha256', $temporaryFile);
        $size = filesize($temporaryFile);
        if ($hash === false || $size === false) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The temporary file cannot be read.');
        }
        $staging = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@rename($temporaryFile, $staging) && !(copy($temporaryFile, $staging) && unlink($temporaryFile))) { // phpcs:ignore -- rename across devices falls back to copy.
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The file cannot be moved into the storage.');
        }
        chmod($staging, 0600);
        if (!rename($staging, $target)) {
            @unlink($staging); // phpcs:ignore -- best effort cleanup.
            throw new CalendarException(ErrorCode::StorageUnsafe, 'The file cannot be stored atomically.');
        }
        return new StoredObject($storageKey, (int) $size, $hash);
    }

    public function openReadStream(string $storageKey): mixed
    {
        $path = $this->path($storageKey);
        if (!is_file($path) || is_link($path)) {
            throw new CalendarException(ErrorCode::NotFound, 'Stored file not found.');
        }
        $this->assertContained(dirname($path));
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CalendarException(ErrorCode::NotFound, 'Stored file cannot be opened.');
        }
        return $handle;
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path) && !is_link($path)) {
            unlink($path);
        }
    }

    public function size(string $storageKey): ?int
    {
        try {
            $path = $this->path($storageKey);
        } catch (CalendarException) {
            return null;
        }
        clearstatcache(true, $path);
        return is_file($path) && !is_link($path) ? (int) filesize($path) : null;
    }

    public function localPath(string $storageKey): string
    {
        return $this->path($storageKey);
    }

    /** Removes temporary files of crashed downloads. */
    public function purgeTemporary(int $olderThanSeconds = 86400): int
    {
        $count = 0;
        foreach (glob($this->root . '/tmp/*.part') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $olderThanSeconds) {
                unlink($file);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @throws CalendarException STORAGE_UNSAFE for keys that were not generated by this class.
     */
    private function path(string $storageKey): string
    {
        if (preg_match(self::KEY_PATTERN, $storageKey) !== 1) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'Invalid storage key.');
        }
        return $this->root . '/' . $storageKey;
    }

    private function assertContained(string $directory): void
    {
        $realRoot = realpath($this->root);
        $real = realpath($directory);
        if ($realRoot === false || $real === false || ($real !== $realRoot && !str_starts_with($real . '/', $realRoot . '/'))) {
            throw new CalendarException(ErrorCode::StorageUnsafe, 'Storage path escapes the storage root.');
        }
        // No symlinked directory between root and target.
        $relative = substr($real, strlen($realRoot));
        $current = $this->root;
        foreach (array_filter(explode('/', $relative)) as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new CalendarException(ErrorCode::StorageUnsafe, 'Symlinks are not allowed inside the storage.');
            }
        }
    }
}
