<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\PersonalData;

use DateTimeImmutable;
use Nowo\UserKitBundle\PersonalData\Eraser\LastActivityEraser;
use Nowo\UserKitBundle\PersonalData\Eraser\ProductTourEraser;
use Nowo\UserKitBundle\PersonalData\Eraser\UiPreferencesEraser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\Provider\AccountStatusExportProvider;
use Nowo\UserKitBundle\PersonalData\Provider\LastActivityExportProvider;
use Nowo\UserKitBundle\PersonalData\Provider\ProductTourExportProvider;
use Nowo\UserKitBundle\PersonalData\Provider\UiPreferencesExportProvider;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class BuiltinProvidersTest extends TestCase
{
    public function testProfileFieldBasedSections(): void
    {
        $user                 = new PlainUser();
        $user->enabled        = false;
        $user->lastActivityAt = new DateTimeImmutable('2026-10-01');
        $registry             = ProfileRegistryFactory::single(PlainUser::class);
        $accessor             = PropertyAccess::createPropertyAccessor();

        $status   = new AccountStatusExportProvider($registry, $accessor);
        $activity = new LastActivityExportProvider($registry, $accessor);

        $this->assertSame('account_status', $status->getSectionKey());
        $this->assertTrue($status->supports($user));
        $this->assertSame(['enabled' => false], $status->export($user));
        $this->assertSame('last_activity', $activity->getSectionKey());
        $this->assertTrue($activity->supports($user));
        $this->assertSame(['last_activity_at' => $user->lastActivityAt], $activity->export($user));

        $user->lastActivityAt = 'not a date';
        $this->assertSame(['last_activity_at' => null], $activity->export($user));
    }

    public function testUnsupportedUsers(): void
    {
        $registry = ProfileRegistryFactory::single(PlainUser::class, ['account_status' => ['enabled' => false], 'last_activity' => ['enabled' => false]]);
        $accessor = PropertyAccess::createPropertyAccessor();
        $user     = new PlainUser();

        $status   = new AccountStatusExportProvider($registry, $accessor);
        $activity = new LastActivityExportProvider($registry, $accessor);

        $this->assertFalse($status->supports($user));
        $this->assertSame(['enabled' => false], $status->export($user));
        $this->assertFalse($activity->supports(new stdClass()));
        $this->assertSame(['last_activity_at' => null], $activity->export($user));

        $preferences = new UiPreferencesExportProvider();
        $tour        = new ProductTourExportProvider();
        $this->assertSame('ui_preferences', $preferences->getSectionKey());
        $this->assertFalse($preferences->supports($user));
        $this->assertSame([], $preferences->export($user));
        $this->assertSame('product_tour', $tour->getSectionKey());
        $this->assertFalse($tour->supports($user));
        $this->assertSame([], $tour->export($user));

        $request = new ErasureRequest($user);
        foreach ([new LastActivityEraser(), new UiPreferencesEraser(), new ProductTourEraser()] as $eraser) {
            $this->assertFalse($eraser->supports($user));
            $eraser->erase($request); // no-op, no error
        }

        $this->assertSame(['last_activity', 'ui_preferences', 'product_tour'], [
            (new LastActivityEraser())->getKey(),
            (new UiPreferencesEraser())->getKey(),
            (new ProductTourEraser())->getKey(),
        ]);
    }
}

final class PlainUser
{
    public bool $enabled = true;

    public mixed $lastActivityAt = null;
}
