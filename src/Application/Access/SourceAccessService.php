<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Access;

use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Port\UserContextInterface;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\Visibility;

/**
 * Central source ACL (SPECIFICATIONS.md §9.1). Every read path (render, REST, downloads, previews, CLI output for
 * users) goes through this service; caches are never an authorization source.
 *
 * Results are deliberately not memoized across calls: a visibility change must be effective for the next check.
 */
final class SourceAccessService implements SourceAccessInterface
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly UserContextInterface $users,
    ) {
    }

    public function canReadSource(int $sourceId, ?int $wpUserId): bool
    {
        $source = $this->sources->find($sourceId);
        return $source !== null && $this->allows($source, $this->users->rolesOf($wpUserId));
    }

    public function filterAllowedSources(array $sourceIds, ?int $wpUserId): array
    {
        $ids = [];
        foreach ($sourceIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $sources = $this->sources->findMany($ids);
        $roles = $this->users->rolesOf($wpUserId);
        return array_values(array_filter(
            $ids,
            fn (int $id): bool => isset($sources[$id]) && $this->allows($sources[$id], $roles)
        ));
    }

    /**
     * Whether the source is readable by everybody (no login needed). Used to decide whether output may be shared
     * through public caches.
     */
    public function isPublic(Source $source): bool
    {
        return $source->isReadable() && $source->visibility === Visibility::Public;
    }

    /**
     * @param list<string>|null $roles Null for anonymous visitors.
     */
    private function allows(Source $source, ?array $roles): bool
    {
        if (!$source->isReadable()) {
            return false;
        }
        return match ($source->visibility) {
            Visibility::Public => true,
            Visibility::Authenticated => $roles !== null,
            // A roles source with an empty role list is a configuration error and therefore closed.
            Visibility::Roles => $roles !== null && $source->roles !== [] && array_intersect($roles, $source->roles) !== [],
        };
    }
}
