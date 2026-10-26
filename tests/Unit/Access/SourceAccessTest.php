<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Access\SourceAccessService;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Tests\Support\InMemorySources;
use SW\Calendar\Tests\Support\StaticUsers;
use SW\Calendar\Tests\Support\TestData;

final class SourceAccessTest extends TestCase
{
    private function service(InMemorySources $sources): SourceAccessService
    {
        return new SourceAccessService($sources, new StaticUsers([1 => ['subscriber'], 2 => ['editor'], 3 => []]));
    }

    public function testAclMatrix(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source(1, Visibility::Public));
        $sources->add(TestData::source(2, Visibility::Authenticated));
        $sources->add(TestData::source(3, Visibility::Roles, ['editor']));
        $access = $this->service($sources);
        // [guest, logged in without role, user with role]
        $expected = [1 => [true, true, true], 2 => [false, true, true], 3 => [false, false, true]];
        foreach ($expected as $source => [$guest, $other, $member]) {
            self::assertSame($guest, $access->canReadSource($source, null), "guest/$source");
            self::assertSame($other, $access->canReadSource($source, 1), "subscriber/$source");
            self::assertSame($member, $access->canReadSource($source, 2), "editor/$source");
        }
        self::assertTrue($access->canReadSource(2, 3), 'Logged in without any role is still authenticated');
        self::assertFalse($access->canReadSource(3, 99), 'Unknown user ids are anonymous');
    }

    public function testFilteringKeepsOrderAndDropsUnknownAndInactive(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source(1, Visibility::Public));
        $sources->add(TestData::source(2, Visibility::Public, [], SourceStatus::Disabled));
        $sources->add(TestData::source(3, Visibility::Public, [], SourceStatus::Draft));
        $sources->add(TestData::source(4, Visibility::Roles, []));
        $sources->add(TestData::source(5, Visibility::Public, [], SourceStatus::Error));
        $access = $this->service($sources);
        self::assertSame([5, 1], $access->filterAllowedSources([5, 1, 2, 3, 4, 77, 1, -1], 2), 'Error sources keep serving their last data; roles without a role list are closed');
        self::assertSame([], $access->filterAllowedSources([], null));
    }
}
