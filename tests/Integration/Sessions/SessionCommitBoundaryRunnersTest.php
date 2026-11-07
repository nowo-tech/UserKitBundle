<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * The boundaries outside HTTP, run for real: a console command and a Messenger worker commit a password change in a
 * transaction, with no flush after the commit, and the end of the command or message releases it.
 */
final class SessionCommitBoundaryRunnersTest extends TransactionalSessionsTestCase
{
    private function changePassword(Member $member): void
    {
        $this->connection()->beginTransaction();
        $member = $this->fresh($member);
        $member->setPassword('changed');
        $this->em()->flush();
        $this->connection()->commit();
        self::assertSame([], $this->log()->revoked, 'Not before the boundary.');
    }

    public function testTheEndOfACommandReleases(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->addCommand(new class($this, $alice) extends Command {
            public function __construct(
                private readonly SessionCommitBoundaryRunnersTest $test,
                private readonly Member $member,
            ) {
                parent::__construct('test:change-password');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->test->changeInCommand($this->member);

                return Command::SUCCESS;
            }
        });

        $exit = $application->run(new ArrayInput(['command' => 'test:change-password']), $output = new BufferedOutput());

        self::assertSame(0, $exit, $output->fetch());
        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
    }

    /**
     * Called by the command of {@see self::testTheEndOfACommandReleases()}.
     */
    public function changeInCommand(Member $member): void
    {
        $this->changePassword($member);
    }

    public function testTheEndOfAMessengerMessageReleases(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->runWorker(function () use ($alice): void {
            $this->changePassword($alice);
        });

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
    }

    public function testAFailedMessengerMessageReleasesWhatItCommitted(): void
    {
        $this->setUpDatabase('cache');
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->log()->clear();

        $this->runWorker(function () use ($alice): never {
            $this->changePassword($alice);

            throw new RuntimeException('After the commit.');
        });

        self::assertSame([RevocationReason::PasswordChanged->value], $this->revokedReasons());
    }

    /**
     * A real worker on the kernel's dispatcher, one message.
     */
    private function runWorker(callable $work): void
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $bus = new MessageBus([new class($work) implements MiddlewareInterface {
            /** @var callable */
            private $work;

            public function __construct(callable $work)
            {
                $this->work = $work;
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->work)();

                return $envelope;
            }
        }]);

        (new Worker(['test' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }
}
