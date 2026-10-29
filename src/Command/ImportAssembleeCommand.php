<?php
namespace App\Command;

use App\Service\AssembleeNationaleImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-assemblee',
    description: 'Télécharge et importe les scrutins de l\'Assemblée Nationale',
)]
class ImportAssembleeCommand extends Command
{
    // On injecte UNIQUEMENT le service, pas de $projectDir ici
    public function __construct(private AssembleeNationaleImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from-year', null, InputOption::VALUE_REQUIRED, 'Première année importée.')
            ->addOption('to-year', null, InputOption::VALUE_REQUIRED, 'Dernière année importée.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de scrutins importés.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réimporte les votes déjà présents.')
            ->addOption('chambre', null, InputOption::VALUE_REQUIRED, 'Chambre (AN par défaut).')
            ->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'ImportJob id to report progress to (internal)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de l\'importation des Scrutins');
        $io->info('Vérification et téléchargement de l\'archive (reprise automatique en cas de coupure)...');

        $jobId = $input->getOption('job-id');

        // Read options and forward to the importer
        $fromYear = $input->getOption('from-year') ? (int) $input->getOption('from-year') : null;
        $toYear = $input->getOption('to-year') ? (int) $input->getOption('to-year') : null;
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $force = (bool) $input->getOption('force');
        $chambre = $input->getOption('chambre') ?: null;

        // On lance notre service (qui lui, possède le $projectDir et le downloader)
        // If job-id provided (detached), create a progress callback that persists progress
        $onProgress = null;
        if ($jobId !== null) {
            $em = $this->getApplication()->getKernel()->getContainer()->get('doctrine')->getManager();
            $onProgress = function(int $pct) use ($em, $jobId) {
                $repo = $em->getRepository(\App\Entity\ImportJob::class);
                $job = $repo->find((int) $jobId);
                if ($job) {
                    $job->setProgress($pct);
                    $em->flush();
                }
            };
        }

        $this->importer->importTextes($io, $onProgress, $fromYear, $toYear, $limit, $force, $chambre);

        $io->success('Import des textes terminé avec succès !');

        return Command::SUCCESS;
    }
}
