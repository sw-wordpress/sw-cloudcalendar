<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Domain\View\DisplayMode;

/**
 * Validates and types raw shortcode/block/REST parameters. Values are never interpolated into SQL; invalid values are
 * reported and dropped (shortcode/block) or rejected (REST, `strict`).
 */
final class FilterSchema
{
    public const MAX_LIMIT = 100;
    public const MAX_DAYS = 1095;
    public const MAX_TEXT = 150;
    public const MAX_IDS = 50;

    /** Parameters handled outside the filter (view id, block/editor context). */
    private const PASSTHROUGH = ['id', 'class_name', 'classname', 'align', 'lock', 'metadata', 'style'];

    /**
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw): FilterResult
    {
        $errors = [];
        $unknown = [];
        $values = [];
        foreach ($raw as $key => $value) {
            $key = strtolower((string) $key);
            if (in_array($key, self::PASSTHROUGH, true) || $value === null || $value === '') {
                continue;
            }
            switch ($key) {
                case 'source':
                    $ids = $this->ids($value);
                    $this->accept($values, $errors, 'source', $ids, 'invalid_ids');
                    break;
                case 'exclude':
                    $ids = $this->ids($value);
                    $this->accept($values, $errors, 'exclude', $ids, 'invalid_ids');
                    break;
                case 'view':
                    $mode = DisplayMode::tryFrom(strtolower(trim((string) $value)));
                    $this->accept($values, $errors, 'view', $mode, 'invalid_mode');
                    break;
                case 'limit':
                    $int = $this->int($value, 1, self::MAX_LIMIT);
                    $this->accept($values, $errors, 'limit', $int, 'out_of_range');
                    break;
                case 'days':
                    $int = $this->int($value, 1, self::MAX_DAYS);
                    $this->accept($values, $errors, 'days', $int, 'out_of_range');
                    break;
                case 'start':
                case 'end':
                    $date = $this->date($value);
                    $this->accept($values, $errors, $key, $date, 'invalid_date');
                    break;
                case 'month':
                    $month = is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) === 1 ? $value : null;
                    $this->accept($values, $errors, 'month', $month, 'invalid_month');
                    break;
                case 'past':
                case 'navigation':
                case 'show_images':
                case 'show_attachments':
                    $bool = $this->bool($value);
                    $this->accept($values, $errors, $key, $bool, 'invalid_boolean');
                    break;
                case 'search':
                case 'location':
                    $text = $this->text($value);
                    $this->accept($values, $errors, $key, $text, 'too_long');
                    break;
                case 'sort':
                    $sort = strtolower(trim((string) $value));
                    $this->accept($values, $errors, 'sort', in_array($sort, ['asc', 'desc'], true) ? $sort : null, 'invalid_sort');
                    break;
                case 'template':
                    $int = $this->int($value, 1, PHP_INT_MAX);
                    $this->accept($values, $errors, 'template', $int, 'invalid_id');
                    break;
                case 'page':
                    // Negative pages go backwards in time (earlier events); 0 does not exist.
                    $int = $this->int($value, -10000, 10000);
                    $this->accept($values, $errors, 'page', $int === 0 ? null : $int, 'out_of_range');
                    break;
                case 'per_page':
                    $int = $this->int($value, 1, self::MAX_LIMIT);
                    $this->accept($values, $errors, 'per_page', $int, 'out_of_range');
                    break;
                case 'category':
                    // Local categories are not part of V1 (docs/decisions.md D-12): reject instead of silently ignoring.
                    $errors[$key] = 'not_supported';
                    break;
                default:
                    $unknown[] = $key;
            }
        }
        if (isset($values['start'], $values['end']) && $values['end'] < $values['start']) {
            $errors['end'] = 'end_before_start';
            unset($values['end']);
        }
        if (isset($values['start'], $values['end']) && $this->daysBetween($values['start'], $values['end']) > self::MAX_DAYS) {
            $errors['end'] = 'range_too_long';
            unset($values['end']);
        }

        return new FilterResult(
            new RenderFilter(
                $values['source'] ?? null,
                $values['view'] ?? null,
                $values['limit'] ?? null,
                $values['days'] ?? null,
                $values['start'] ?? null,
                $values['end'] ?? null,
                $values['past'] ?? null,
                $values['search'] ?? null,
                $values['location'] ?? null,
                $values['exclude'] ?? [],
                $values['sort'] ?? null,
                $values['template'] ?? null,
                $values['show_images'] ?? null,
                $values['show_attachments'] ?? null,
                $values['month'] ?? null,
                $values['page'] ?? 1,
                $values['per_page'] ?? null,
                $values['navigation'] ?? null,
            ),
            $errors,
            $unknown,
        );
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function accept(array &$values, array &$errors, string $key, mixed $value, string $errorCode): void
    {
        if ($value === null) {
            $errors[$key] = $errorCode;
            return;
        }
        $values[$key] = $value;
    }

    /**
     * @return list<int>|null
     */
    private function ids(mixed $value): ?array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        foreach ($parts as $part) {
            $part = is_int($part) ? $part : trim((string) $part);
            if ($part === '') {
                continue;
            }
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                return null;
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        return $ids === [] || count($ids) > self::MAX_IDS ? null : $ids;
    }

    private function int(mixed $value, int $min, int $max): ?int
    {
        $int = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $int === false ? null : $int;
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
        if (mb_strlen($text) > self::MAX_TEXT) {
            return null;
        }
        return $text === '' ? null : $text;
    }

    private function daysBetween(string $start, string $end): int
    {
        return (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days;
    }
}
