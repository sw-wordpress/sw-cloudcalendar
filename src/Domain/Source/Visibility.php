<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

/**
 * WordPress visibility of a whole calendar source (SPECIFICATIONS.md §9.1). There are no per event permissions.
 */
enum Visibility: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
    case Roles = 'roles';
}
