<?php

namespace App\EventListener;

use App\Service\ImportManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records the outcome of a command launched from /admin/imports (ImportManager sets the job id
 * in the environment), so the job doesn't stay "running" once the process has exited.
 */
#[AsEventListener(ConsoleEvents::TERMINATE)]
final class ImportJobTerminateListener
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(default::' . ImportManager::JOB_ENV . ')%')]
        private readonly ?string $jobId,
    ) {
    }

    public function __invoke(ConsoleTerminateEvent $event): void
    {
        if ($this->jobId === null || $this->jobId === '' || $event->getCommand() === null) {
            return;
        }

        $success = $event->getExitCode() === 0;
        $sql = $success
            ? "UPDATE import_job SET status = 'success', progress = 100, finished_at = NOW() WHERE id = ? AND command = ? AND status = 'running'"
            : "UPDATE import_job SET status = 'failed', finished_at = NOW() WHERE id = ? AND command = ? AND status = 'running'";

        // DBAL rather than the entity manager: the command may have left it closed after an error
        $this->connection->executeStatement($sql, [(int) $this->jobId, $event->getCommand()->getName()]);
    }
}
