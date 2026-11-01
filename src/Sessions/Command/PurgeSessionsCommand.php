<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Command;

use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsPurgedEvent;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

use const DATE_ATOM;

/**
 * Retention purge (storage limitation, GDPR art. 5(1)(e)): deletes the registry rows not seen for
 * `idle_lifetime` + `touch_interval` — expired sessions (gone from the session handler) and revoked sessions that never
 * came back. Run it daily (cron or Symfony Scheduler). Only rows that can no longer be a live session are deleted, so
 * the command needs no confirmation.
 */
#[AsCommand(name: self::NAME, description: 'Delete the expired and revoked rows of the session registry')]
final class PurgeSessionsCommand extends Command
{
    public const NAME = 'nowo:user-kit:sessions:purge';

    public function __construct(
        private readonly UserSessionStore $store,
        private readonly UserSessionManager $sessions,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per statement', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $batch = (int) $input->getOption('batch-size');
        if ($batch < 1 || $batch > 100000) {
            $io->error('--batch-size must be between 1 and 100000.');

            return Command::INVALID;
        }
        $before  = $this->sessions->aliveSince()->modify(sprintf('-%d seconds', $this->sessions->touchInterval()));
        $deleted = $this->store->purge($before, $batch);
        $this->dispatcher?->dispatch(new UserSessionsPurgedEvent($before, $deleted));
        $io->success(sprintf('Deleted %d session row(s) not seen since %s.', $deleted, $before->format(DATE_ATOM)));

        return Command::SUCCESS;
    }
}
