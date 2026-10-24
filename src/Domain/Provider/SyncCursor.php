<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Opaque provider continuation state (Google syncToken, Graph deltaLink, ICS ETag/Last-Modified). Cursors may be bound
 * to the window they were created for; a provider must not reuse a cursor for a different window.
 */
final class SyncCursor
{
    /**
     * @param array<string, string> $values Opaque values; persisted encrypted.
     */
    public function __construct(
        public readonly array $values = [],
        public readonly ?SyncWindow $boundWindow = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'values' => $this->values,
            'window' => $this->boundWindow === null ? null : [
                $this->boundWindow->start->format(DATE_ATOM),
                $this->boundWindow->end->format(DATE_ATOM),
            ],
        ]);
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return self::empty();
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['values'] ?? null)) {
            return self::empty();
        }
        $window = null;
        if (is_array($data['window'] ?? null) && count($data['window']) === 2) {
            try {
                $window = new SyncWindow(new \DateTimeImmutable((string) $data['window'][0]), new \DateTimeImmutable((string) $data['window'][1]));
            } catch (\Exception) {
                $window = null;
            }
        }
        $values = [];
        foreach ($data['values'] as $key => $value) {
            $values[(string) $key] = (string) $value;
        }
        return new self($values, $window);
    }
}
