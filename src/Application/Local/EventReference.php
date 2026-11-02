<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

/** Stateless identity: source id + opaque adapter id. Existing numeric occurrence ids stay valid. */
final class EventReference
{
    public static function local(int $sourceId, string $id): string
    {
        return 'local.' . $sourceId . '.' . rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    /** @return array{source: int, id: string}|null */
    public static function parse(string $reference): ?array
    {
        if (preg_match('/^local\.([1-9][0-9]*)\.([A-Za-z0-9_-]{1,254})$/D', $reference, $parts) !== 1) {
            return null;
        }
        $source = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $id = base64_decode(strtr($parts[2], '-_', '+/'), true);
        if ($source === false || $id === false || $id === '' || strlen($id) > 190 || self::local($source, $id) !== $reference) {
            return null;
        }
        return ['source' => $source, 'id' => $id];
    }
}
