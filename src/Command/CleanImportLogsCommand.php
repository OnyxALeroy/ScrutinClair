<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:clean-import-logs', description: 'Clean old import logs according to retention policy')]
final class CleanImportLogsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete files older than N days', 30)
            ->addOption('max-files', null, InputOption::VALUE_REQUIRED, 'Keep at most N recent files', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        $maxFiles = (int) $input->getOption('max-files');

        $dir = __DIR__ . '/../../var/import-logs';
        if (!is_dir($dir)) {
            $output->writeln('<info>No logs directory</info>');
            return Command::SUCCESS;
        }

        $files = glob($dir . '/import-*.log');
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $now = time();
        $deleted = 0;

        // delete by age
        foreach ($files as $file) {
            $age = $now - filemtime($file);
            if ($age > ($days * 86400)) {
                @unlink($file);
                $deleted++;
            }
        }

        // refresh list and apply maxFiles
        $files = glob($dir . '/import-*.log');
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        if (count($files) > $maxFiles) {
            $toRemove = array_slice($files, $maxFiles);
            foreach ($toRemove as $file) {
                @unlink($file);
                $deleted++;
            }
        }

        $output->writeln(sprintf('<info>Deleted %d files</info>', $deleted));

        return Command::SUCCESS;
    }
}
