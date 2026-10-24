<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface UserContextInterface
{
    /**
     * Roles of an existing user, or null if the id is null/0 or the user does not exist (anonymous).
     *
     * @return list<string>|null
     */
    public function rolesOf(?int $userId): ?array;
}
