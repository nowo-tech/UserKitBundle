<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\OwnerImagesKernelTestCase;
use Nowo\UserKitBundle\Tests\Load\Support\LoadKernels;
use Nowo\UserKitBundle\Tests\Load\Support\OwnerImageKernels;
use Nowo\UserKitBundle\Tests\Load\Support\SecondConnection;
use RuntimeException;

use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function ltrim;
use function parse_url;
use function preg_match;
use function proc_close;
use function proc_open;
use function putenv;
use function rtrim;
use function sort;
use function sprintf;
use function str_starts_with;
use function stream_set_timeout;

use const PHP_BINARY;
use const PHP_URL_PATH;

/**
 * 1.19.0 (Beacon spec 239), owner images (1.18.0) on MySQL 8.4 / PostgreSQL 16, DBAL 4 and DBAL 3:
 *
 * - the pointer's first row is written with the id of the entity's sequence where the ORM uses one (PostgreSQL with
 *   DBAL 3: a NOT NULL violation on every first upload in 1.18.0, audit QM-M3);
 * - a pointer write that loses the first-row race inside the application's transaction reads the winner and writes over
 *   it (1.18.0: a unique violation, and an aborted PostgreSQL transaction);
 * - two parallel uploads of one subject are serialised (1.18.0: both read the same previous image; the pointer and the
 *   StorageKit reference could end on different files, so the purge would delete the shown image);
 * - the replaced picture is the one the pointer held under its row lock, also inside an application transaction
 *   (audit QM-D-M1).
 */
final class OwnerImageLoadTest extends OwnerImagesKernelTestCase
{
    private ?SecondConnection $other = null;

    protected function setUp(): void
    {
        $url = LoadKernels::url();
        if ($url === null) {
            self::markTestSkipped('Set USER_KIT_LOAD_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        if (preg_match('/(?:^|_)(?:test|race|load|throwaway)(?:_|$)/', ltrim((string) parse_url($url, PHP_URL_PATH), '/')) !== 1) {
            throw new RuntimeException('Refusing to use a database without test, race, load or throwaway in its name.');
        }
        // The owner images test kernel reads its database from this variable (test process only).
        // @phpstan-ignore frankenphp.classic.noPutenv
        putenv('USER_KIT_TEST_DB_URL=' . $url);
        $this->boot([], null, 'load-parent');
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        parent::tearDown();
        // @phpstan-ignore frankenphp.classic.noPutenv
        putenv('USER_KIT_TEST_DB_URL');
    }

    public function testTheFirstPointerRowIsWritten(): void
    {
        $this->pointers()->set('avatar', 'alice', 'file-a');
        $this->pointers()->set('avatar', 'alice', 'file-b');

        self::assertSame(['alice' => 'file-b'], $this->pointers()->find('avatar', ['alice']));
    }

    public function testAPointerWriteThatLosesTheFirstRowRaceInsideATransactionWritesOverTheWinner(): void
    {
        $peer = $this->other = new SecondConnection((string) LoadKernels::url());
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute(sprintf("INSERT INTO %s (id, kind, subject_id, file_id, updated_at) VALUES (900, 'avatar', 'alice', 'peer', '2026-11-15 10:00:00')", DbalOwnerImagePointerStore::TABLE)));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        $this->pointers()->set('avatar', 'alice', 'ours');
        // 1.18.0: a unique violation; on PostgreSQL the next statement also failed with 25P02.
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . DbalOwnerImagePointerStore::TABLE);
        $connection->commit();

        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(1, $count);
        self::assertSame(['alice' => 'ours'], $this->pointers()->find('avatar', ['alice']));
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testTwoParallelUploadsOfOneSubjectEndOnOneImage(): void
    {
        $first = $this->service(OwnerImagesInterface::class)->upload($this->user('alice'), 'avatar', 'alice', OwnerImageKernels::png(), 'first.png');
        // Without a lock: both read the previous image; the first writes its pointer after 1 s and its reference 1.5 s
        // later, the second writes both after 1.5 s, in between. The pointer then ends on the second's file and the
        // reference on the first's (the purge would delete the shown image).
        $workers = [$this->worker('a', 1000, 1500), $this->worker('b', 1500, 0)];
        foreach ($workers as [, $pipes]) {
            fwrite($pipes[0], "GO\n");
        }
        $answers = [];
        foreach ($workers as [$process, $pipes]) {
            $answers[] = rtrim((string) fgets($pipes[1]));
            proc_close($process);
        }
        sort($answers);
        foreach ($answers as $answer) {
            self::assertFalse(str_starts_with($answer, 'ERR'), $answer);
        }

        $connection = $this->em()->getConnection();
        $pointer    = $connection->fetchOne(sprintf("SELECT file_id FROM %s WHERE kind = 'avatar' AND subject_id = 'alice'", DbalOwnerImagePointerStore::TABLE));
        $referenced = $connection->fetchFirstColumn("SELECT f.uuid FROM storage_kit_file_reference r JOIN storage_kit_file f ON f.id = r.file_id WHERE r.source_type = 'avatar' AND r.source_id = 'alice'");
        self::assertSame([$pointer], $referenced, 'the shown image is the referenced one');
        $live = $connection->fetchFirstColumn("SELECT uuid FROM storage_kit_file WHERE context_type = 'avatar' AND context_id = 'alice' AND orphaned_since IS NULL");
        self::assertSame([$pointer], $live, 'every other picture was removed: ' . implode(' | ', $answers) . ', first ' . $first->fileId);
    }

    /**
     * Audit QM-D-M1: an upload inside the application's transaction (MySQL REPEATABLE READ snapshot taken before) while
     * another request replaced the picture: the picture that request stored is the one replaced and expired. On 449a7af
     * the plain read returned the older snapshot, and that picture stayed live and referenced (it also outlived the
     * erasure).
     */
    public function testAnUploadInsideTheApplicationsTransactionReplacesThePictureStoredMeanwhile(): void
    {
        $images = $this->service(OwnerImagesInterface::class);
        $images->upload($this->user('alice'), 'avatar', 'alice', OwnerImageKernels::png(), 'first.png');
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        // The application read first: its REPEATABLE READ snapshot is taken here.
        $connection->fetchOne(sprintf("SELECT file_id FROM %s WHERE kind = 'avatar' AND subject_id = 'alice'", DbalOwnerImagePointerStore::TABLE));
        // Meanwhile another request uploads (autocommit, committed).
        [$process, $pipes] = $this->worker('b', 0, 0);
        fwrite($pipes[0], "GO\n");
        $answer = rtrim((string) fgets($pipes[1]));
        proc_close($process);
        self::assertFalse(str_starts_with($answer, 'ERR'), $answer);

        $third = $images->upload($this->user('alice'), 'avatar', 'alice', OwnerImageKernels::png(), 'third.png');
        $connection->commit();
        // The request's boundary: what waited for the commit runs.
        $this->service(CommitQueue::class)->release();
        self::assertSame(0, $connection->getTransactionNestingLevel());

        self::assertSame(['alice' => $third->fileId], $this->pointers()->find('avatar', ['alice']));
        self::assertSame([$third->fileId], $connection->fetchFirstColumn("SELECT uuid FROM storage_kit_file WHERE context_type = 'avatar' AND context_id = 'alice' AND orphaned_since IS NULL"), 'only the shown picture is live');
    }

    private function pointers(): OwnerImagePointerStoreInterface
    {
        return $this->service(OwnerImagePointerStoreInterface::class);
    }

    private function sleepStatement(float $seconds): string
    {
        return str_starts_with((string) LoadKernels::url(), 'mysql') ? sprintf('SELECT SLEEP(%F)', $seconds) : sprintf('SELECT pg_sleep(%F)', $seconds);
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    private function worker(string $variant, int $slowFind, int $slowSet): array
    {
        $env = [
            'USER_KIT_TEST_DB_URL'       => (string) LoadKernels::url(),
            'USER_KIT_LOAD_SLOW_FIND_MS' => (string) $slowFind,
            'USER_KIT_LOAD_SLOW_SET_MS'  => (string) $slowSet,
            'PATH'                       => (string) getenv('PATH'),
        ];
        // Test-only helper process; every read below is bounded by stream_set_timeout().
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/upload-worker.php', $variant], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start an upload worker.');
        }
        stream_set_timeout($pipes[1], 120);
        $ready = rtrim((string) fgets($pipes[1]));
        if ($ready !== 'READY') {
            throw new RuntimeException('An upload worker did not start: ' . $ready);
        }

        return [$process, $pipes];
    }
}
