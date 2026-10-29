<?php

namespace App\Command;

use App\Service\SenatPersonnalitesImporter;
use App\Service\SenatScrutinsImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-senat',
    description: 'Importe les sénateurs, groupes et scrutins du Sénat.',
)]
final class ImportSenatCommand extends Command
{
    public function __construct(
        private readonly SenatPersonnalitesImporter $personnalites,
        private readonly SenatScrutinsImporter $scrutins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from-year', null, InputOption::VALUE_REQUIRED, 'Première année importée.', '2006')
            ->addOption('to-year', null, InputOption::VALUE_REQUIRED, 'Dernière année importée.', (string) date('Y'))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de scrutins importés.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réimporte les votes déjà présents.')
            ->addOption('skip-personnalites', null, InputOption::VALUE_NONE, 'Ne pas importer les sénateurs et groupes.')
            ->addOption('skip-scrutins', null, InputOption::VALUE_NONE, 'Ne pas importer les scrutins.')
            ->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'ImportJob id to report progress to (internal)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jobId = $input->getOption('job-id');

        // share of the progress bar for each phase, so it never goes backwards
        $partPersonnalites = $input->getOption('skip-personnalites') ? 0 : ($input->getOption('skip-scrutins') ? 100 : 20);

        if (!$input->getOption('skip-personnalites')) {
            $io->section('Sénateurs et groupes');
            // pass progress callback when job-id provided (detached mode)
            $callback = null;
            if ($jobId !== null) {
                $em = $this->getApplication()->getKernel()->getContainer()->get('doctrine')->getManager();
                $callback = function(int $pct) use ($em, $jobId, $partPersonnalites) {
                    $repo = $em->getRepository(\App\Entity\ImportJob::class);
                    $job = $repo->find((int) $jobId);
                    if ($job) {
                        $job->setProgress(intdiv($pct * $partPersonnalites, 100));
                        $em->flush();
                    }
                };
            }

            $resultat = $this->personnalites->importer($callback ?? null);
            $io->success(sprintf(
                '%d sénateurs traités, %d nouveaux, %d groupes.',
                $resultat['senateurs'],
                $resultat['nouveaux'],
                $resultat['groupes'],
            ));
        }

        if (!$input->getOption('skip-scrutins')) {
            $io->section('Scrutins');
            $debut = max(2006, (int) $input->getOption('from-year'));
            $fin = max($debut, (int) $input->getOption('to-year'));
            $limite = $input->getOption('limit') !== null ? max(0, (int) $input->getOption('limit')) : null;

            // progress callback for detached mode
            $scrutinCallback = null;
            if ($jobId !== null) {
                $em = $this->getApplication()->getKernel()->getContainer()->get('doctrine')->getManager();
                $scrutinCallback = function(int $pct) use ($em, $jobId, $partPersonnalites) {
                    $repo = $em->getRepository(\App\Entity\ImportJob::class);
                    $job = $repo->find((int) $jobId);
                    if ($job) {
                        $job->setProgress($partPersonnalites + intdiv($pct * (100 - $partPersonnalites), 100));
                        $em->flush();
                    }
                };
            }

            $resultat = $this->scrutins->importer(
                $debut,
                $fin,
                $limite,
                (bool) $input->getOption('force'),
                $scrutinCallback
            );

            $io->success(sprintf(
                '%d scrutins traités, %d nouveaux, %d votes insérés, %d votes ignorés, %d anomalies.',
                $resultat['scrutins'],
                $resultat['nouveaux'],
                $resultat['votes'],
                $resultat['ignores'],
                $resultat['anomalies'],
            ));
        }

        return Command::SUCCESS;
    }
}
