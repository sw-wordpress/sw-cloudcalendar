<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

/**
 * Validated sync and filter settings of a source.
 */
final class SourceSettings
{
    /** Sync interval in hours (docs/decisions.md D-28). */
    public const MIN_INTERVAL_HOURS = 1;
    public const MAX_INTERVAL_HOURS = 48;
    public const DEFAULT_INTERVAL_HOURS = 6;
    /** Default hard limit for each window direction; a server constant may raise it (SPECIFICATIONS.md §7.2). */
    public const DEFAULT_MAX_WINDOW_DAYS = 1095;
    /** Google event types that are personal status entries, not publishable appointments. */
    public const DEFAULT_EXCLUDED_EVENT_TYPES = ['workingLocation', 'focusTime', 'outOfOffice'];
    public const KNOWN_EVENT_TYPES = ['default', 'birthday', 'fromGmail', 'workingLocation', 'focusTime', 'outOfOffice'];

    /**
     * @param list<string> $excludedEventTypes
     */
    public function __construct(
        public readonly bool $syncEnabled = true,
        public readonly int $syncIntervalHours = self::DEFAULT_INTERVAL_HOURS,
        public readonly int $pastDays = 90,
        public readonly int $futureDays = 365,
        public readonly bool $excludePrivateEvents = false,
        public readonly array $excludedEventTypes = self::DEFAULT_EXCLUDED_EVENT_TYPES,
        public readonly AttachmentPolicy $attachments = new AttachmentPolicy(),
        public readonly ?Confirmation $publicationConfirmation = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    public static function fromArray(array $data, int $maxWindowDays = self::DEFAULT_MAX_WINDOW_DAYS, array &$errors = []): self
    {
        $default = new self();
        if (!array_key_exists('sync_interval_hours', $data) && is_numeric($data['sync_interval_minutes'] ?? null)) {
            // Settings stored before the interval was configured in hours.
            $data['sync_interval_hours'] = max(self::MIN_INTERVAL_HOURS, min(self::MAX_INTERVAL_HOURS, (int) ceil((int) $data['sync_interval_minutes'] / 60)));
        }
        $interval = self::intIn($data, 'sync_interval_hours', $default->syncIntervalHours, self::MIN_INTERVAL_HOURS, self::MAX_INTERVAL_HOURS, $errors);
        $past = self::intIn($data, 'past_days', $default->pastDays, 0, $maxWindowDays, $errors);
        $future = self::intIn($data, 'future_days', $default->futureDays, 1, $maxWindowDays, $errors);
        $types = $default->excludedEventTypes;
        if (array_key_exists('excluded_event_types', $data)) {
            $types = [];
            foreach ((array) $data['excluded_event_types'] as $type) {
                if (!in_array($type, self::KNOWN_EVENT_TYPES, true)) {
                    $errors[] = 'excluded_event_types';
                    continue;
                }
                $types[] = (string) $type;
            }
            $types = array_values(array_unique($types));
        }
        $attachmentErrors = [];
        $attachments = AttachmentPolicy::fromArray(is_array($data['attachments'] ?? null) ? $data['attachments'] : [], $attachmentErrors);
        foreach ($attachmentErrors as $error) {
            $errors[] = 'attachments.' . $error;
        }

        return new self(
            (bool) ($data['sync_enabled'] ?? $default->syncEnabled),
            $interval,
            $past,
            $future,
            (bool) ($data['exclude_private_events'] ?? $default->excludePrivateEvents),
            $types,
            $attachments,
            Confirmation::fromArray($data['publication_confirmation'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function intIn(array $data, string $key, int $default, int $min, int $max, array &$errors): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = filter_var($data[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            $errors[] = $key;
            return $default;
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_hours' => $this->syncIntervalHours,
            'past_days' => $this->pastDays,
            'future_days' => $this->futureDays,
            'exclude_private_events' => $this->excludePrivateEvents,
            'excluded_event_types' => $this->excludedEventTypes,
            'attachments' => $this->attachments->toArray(),
            'publication_confirmation' => $this->publicationConfirmation?->toArray(),
        ];
    }
}
