<?php
namespace App\Command;

use App\Service\AssembleeNationaleImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de l\'importation des Scrutins');
        $io->info('Vérification et téléchargement de l\'archive (reprise automatique en cas de coupure)...');

        // On lance notre service (qui lui, possède le $projectDir et le downloader)
        $this->importer->importTextes();

        $io->success('Import des textes terminé avec succès !');

        return Command::SUCCESS;
    }
}
