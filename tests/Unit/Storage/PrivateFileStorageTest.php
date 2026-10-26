<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Attachments\MimePolicy;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Infrastructure\Storage\FinfoMimeDetector;
use SW\Calendar\Infrastructure\Storage\PrivateFileStorage;

final class PrivateFileStorageTest extends TestCase
{
    private string $root;
    private PrivateFileStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/swc-storage-test-' . bin2hex(random_bytes(6));
        $this->storage = new PrivateFileStorage($this->root);
        $this->storage->prepare();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        exec('rm -rf ' . escapeshellarg($this->root . '-outside'));
    }

    public function testAtomicStoreReadAndDelete(): void
    {
        $temp = $this->storage->temporaryFile();
        file_put_contents($temp, 'hello');
        $key = $this->storage->newKey();
        $object = $this->storage->putFromTemp($temp, $key);
        self::assertFileDoesNotExist($temp);
        self::assertSame(hash('sha256', 'hello'), $object->sha256);
        self::assertSame(5, $this->storage->size($key));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->root . '/' . $key)), -4));
        $stream = $this->storage->openReadStream($key);
        self::assertSame('hello', stream_get_contents($stream));
        fclose($stream);
        $this->storage->delete($key);
        self::assertNull($this->storage->size($key));
        self::assertFileExists($this->root . '/.htaccess');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousKeys(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'encoded traversal' => ['ab/cd/..%2F..%2Fwp-config.php'],
            'absolute' => ['/etc/passwd'],
            'wrong shape' => ['ab/cd/short'],
            'null byte' => ["ab/cd/" . str_repeat('a', 64) . "\0.php"],
            'php extension' => ['ab/cd/' . str_repeat('a', 64) . '.php'],
        ];
    }

    #[DataProvider('maliciousKeys')]
    public function testRejectsKeysNotGeneratedByTheStorage(string $key): void
    {
        $this->expectException(CalendarException::class);
        $this->storage->openReadStream($key);
    }

    public function testSymlinkedDirectoryIsRejected(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside);
        $key = $this->storage->newKey();
        [$a, $b] = explode('/', $key);
        mkdir($this->root . '/' . $a, 0700, true);
        symlink($outside, $this->root . '/' . $a . '/' . $b);
        file_put_contents($outside . '/' . basename($key), 'secret');
        $this->expectException(CalendarException::class);
        $this->storage->openReadStream($key);
    }

    public function testSymlinkedFileIsRejected(): void
    {
        $key = $this->storage->newKey();
        mkdir(dirname($this->root . '/' . $key), 0700, true);
        symlink('/etc/hostname', $this->root . '/' . $key);
        $this->expectException(CalendarException::class);
        $this->storage->openReadStream($key);
    }

    public function testMimeDetectionIgnoresNamesAndRejectsActiveContent(): void
    {
        $detector = new FinfoMimeDetector();
        $file = $this->root . '/tmp/x.pdf';
        file_put_contents($file, '<html><body><script>alert(1)</script></body></html>');
        $detected = MimePolicy::reconcile($detector->detect($file), 'application/pdf', 'invoice.pdf');
        self::assertTrue(MimePolicy::isForbidden($detected));
        file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        self::assertTrue(MimePolicy::isForbidden($detector->detect($file)));
        file_put_contents($file, "%PDF-1.4\n%%EOF");
        self::assertSame('application/pdf', $detector->detect($file));
        self::assertSame('invoice.pdf', MimePolicy::downloadName('invoice.exe', 'application/pdf'));
        self::assertSame('a_b.pdf', MimePolicy::downloadName('../a\\b.pdf', 'application/pdf'));
    }
}
