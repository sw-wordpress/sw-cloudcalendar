<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Source\AttachmentPolicy;
use SW\Calendar\Domain\Source\Confirmation;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Infrastructure\Crypto\SecretVault;

final class PolicyAndVaultTest extends TestCase
{
    public function testPublicOnlyFailsClosed(): void
    {
        $policy = new AttachmentPolicy(syncAttachments: true, publicOnly: true);
        self::assertTrue($policy->permits(PublicationState::PublicVerified, Visibility::Public));
        foreach ([PublicationState::Unknown, PublicationState::Private, PublicationState::Unsupported] as $state) {
            self::assertFalse($policy->permits($state, Visibility::Authenticated));
        }
    }

    public function testPrivateFilesOnPublicSourceNeedConfirmation(): void
    {
        $policy = new AttachmentPolicy(syncAttachments: true, publicOnly: false);
        self::assertTrue($policy->permits(PublicationState::Private, Visibility::Authenticated));
        self::assertFalse($policy->permits(PublicationState::Private, Visibility::Public));
        self::assertTrue($policy->requiresPrivateConfirmation(Visibility::Public));
        $confirmed = new AttachmentPolicy(syncAttachments: true, publicOnly: false, privateAttachmentsConfirmation: new Confirmation(1, new \DateTimeImmutable(), 3));
        self::assertTrue($confirmed->permits(PublicationState::Private, Visibility::Public));
        self::assertFalse($confirmed->permits(PublicationState::Unsupported, Visibility::Public));
        self::assertFalse((new AttachmentPolicy())->permits(PublicationState::PublicVerified, Visibility::Public), 'Attachment sync is off by default');
    }

    public function testSettingsValidation(): void
    {
        $errors = [];
        $settings = SourceSettings::fromArray([
            'sync_interval_hours' => 49, 'past_days' => 5000, 'excluded_event_types' => ['focusTime', 'evil'],
            'attachments' => ['max_file_size_mb' => 999, 'allowed_mime_types' => ['image/svg+xml', 'application/pdf']],
        ], 1095, $errors);
        self::assertSame(['sync_interval_hours', 'past_days', 'excluded_event_types', 'attachments.max_file_size_mb', 'attachments.allowed_mime_types'], $errors);
        self::assertSame(['application/pdf'], $settings->attachments->allowedMimeTypes);
        self::assertSame(6, $settings->syncIntervalHours, 'Default interval is 6 hours');
        self::assertSame(2, SourceSettings::fromArray(['sync_interval_minutes' => 90])->syncIntervalHours, 'Legacy minutes are rounded up to hours');
        self::assertSame(24, SourceSettings::fromArray(['sync_interval_hours' => '24'])->syncIntervalHours);
    }

    public function testVaultEncryptsWithContextAndRotates(): void
    {
        $old = new SecretVault('old-key', null, 'salts');
        $cipher = $old->encrypt('refresh-token', 'connection:1');
        self::assertStringNotContainsString('refresh-token', $cipher);
        self::assertSame('refresh-token', $old->decrypt($cipher, 'connection:1'));

        $rotated = new SecretVault('new-key', 'old-key', 'salts');
        self::assertTrue($rotated->needsRotation($cipher));
        self::assertSame('refresh-token', $rotated->decrypt($cipher, 'connection:1'));
        self::assertFalse($rotated->usesFallback);
        self::assertTrue((new SecretVault(null, null, 'salts'))->usesFallback);

        $this->expectException(CalendarException::class);
        $old->decrypt($cipher, 'connection:2');
    }

    public function testSwitchingFromSaltFallbackToDedicatedKeyKeepsSecretsReadable(): void
    {
        $cipher = (new SecretVault(null, null, 'salts'))->encrypt('refresh-token', 'connection:1');
        $dedicated = new SecretVault(base64_encode(random_bytes(32)), null, 'salts');
        self::assertSame('refresh-token', $dedicated->decrypt($cipher, 'connection:1'));
        self::assertTrue($dedicated->needsRotation($cipher), 'Re-encrypted with the dedicated key on the next read');
    }

    public function testVaultDetectsTampering(): void
    {
        $vault = new SecretVault('k', null, 's');
        $cipher = $vault->encrypt('secret', 'c');
        $parts = explode(':', $cipher);
        $raw = base64_decode($parts[2]);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(CalendarException::class);
        $vault->decrypt($parts[0] . ':' . $parts[1] . ':' . base64_encode($raw), 'c');
    }
}
