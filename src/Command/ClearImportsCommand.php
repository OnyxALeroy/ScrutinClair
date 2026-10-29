<?php
namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:clear-imports', description: 'Clear import_job rows and remove import logs (for container restarts)')]
final class ClearImportsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->projectDir . '/var/import-logs';
        if (is_dir($dir)) {
            $files = glob($dir . '/import-*.log');
            if ($files !== false) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }

        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM import_job');
            try {
                $conn->executeStatement('ALTER SEQUENCE import_job_id_seq RESTART WITH 1');
            } catch (\Throwable) {
                // ignore if sequence not present or unsupported
            }
        } catch (\Throwable $e) {
            // ignore errors to avoid breaking the command
            // still print a message for visibility
            $output->writeln('<comment>Warning: failed to clear import_job rows: ' . $e->getMessage() . '</comment>');
        }

        $output->writeln('<info>Import jobs and logs cleared</info>');

        return Command::SUCCESS;
    }
}
