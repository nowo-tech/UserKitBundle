<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Doctrine;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\UserKitBundle\Doctrine\UserKitExactKeyColumns;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SEC-006: the declaration names both halves of each owner identity, on the *configured* session table names, and
 * declares the columns of a feature that is off inactive instead of omitting them.
 */
final class UserKitExactKeyColumnsTest extends TestCase
{
    public function testItDeclaresBothHalvesOfEveryOwnerIdentity(): void
    {
        $columns = (new UserKitExactKeyColumns('', true, true, true))->exactKeyColumns();

        self::assertSame([
            'user_kit_session.user_type',
            'user_kit_session.user_id',
            'user_kit_session_epoch.user_type',
            'user_kit_session_epoch.user_id',
            'user_kit_owner_image.kind',
            'user_kit_owner_image.subject_id',
        ], array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns));

        foreach ($columns as $column) {
            self::assertTrue($column->active, $column->describe());
            self::assertNull($column->condition);
            self::assertSame(UserKitExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose, $column->describe());
        }
    }

    public function testThePrefixIsResolvedForTheSessionTablesOnly(): void
    {
        // The table prefix listener covers the session entities; the pointer table has no prefix and no host can
        // rename it, so its name must stay as it is.
        $columns = (new UserKitExactKeyColumns('app_', true, true, true))->exactKeyColumns();
        $tables  = [];
        foreach ($columns as $column) {
            $tables[$column->table] = true;
        }

        self::assertSame([
            'app_user_kit_session'       => true,
            'app_user_kit_session_epoch' => true,
            'user_kit_owner_image'       => true,
        ], $tables);
    }

    public function testEverythingIsInactiveInTheDefaultConfiguration(): void
    {
        // Every feature is opt-in: nothing is registered and no table exists.
        $columns = (new UserKitExactKeyColumns())->exactKeyColumns();

        self::assertCount(6, $columns);
        foreach ($columns as $column) {
            self::assertFalse($column->active, $column->describe());
            self::assertSame(
                str_starts_with($column->table, 'user_kit_owner_image')
                    ? UserKitExactKeyColumns::OWNER_IMAGE_CONDITION
                    : UserKitExactKeyColumns::SESSIONS_CONDITION,
                $column->condition,
                $column->describe(),
            );
        }
    }

    public function testTheEpochColumnsNameTheSettingThatIsActuallyOff(): void
    {
        // Registry on, epoch off: the epoch table is the one that does not exist, and its own setting is reported.
        foreach ((new UserKitExactKeyColumns('', true, false, false))->exactKeyColumns() as $column) {
            if ($column->table === 'user_kit_session_epoch') {
                self::assertFalse($column->active);
                self::assertSame(UserKitExactKeyColumns::EPOCH_CONDITION, $column->condition);
            }
        }

        // Registry off: the epoch store is not registered either, so the registry is what is missing.
        foreach ((new UserKitExactKeyColumns('', false, true, false))->exactKeyColumns() as $column) {
            self::assertFalse($column->active, $column->describe());
            if ($column->table === 'user_kit_session_epoch') {
                self::assertSame(UserKitExactKeyColumns::SESSIONS_CONDITION, $column->condition);
            }
        }
    }

    public function testTheOwnerImageColumnsAreGatedOnTheirOwnFeature(): void
    {
        foreach ((new UserKitExactKeyColumns('', false, false, true))->exactKeyColumns() as $column) {
            self::assertSame($column->table === 'user_kit_owner_image', $column->active, $column->describe());
        }
    }

    public function testItIsSafeToCallMoreThanOnce(): void
    {
        // REQ-RUNTIME-002: nothing is memoised, so a worker that keeps the service gets the same answer every time.
        $declaration = new UserKitExactKeyColumns('app_', true, true, true);

        self::assertEquals($declaration->exactKeyColumns(), $declaration->exactKeyColumns());
    }
}
