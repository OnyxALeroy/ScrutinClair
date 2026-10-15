<?php
namespace App\Command;

use App\Service\PersonnalitePolitiqueImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-personnalites',
    description: 'Télécharge et importe les personnalités politiques (actuellement : Assemblée Nationale)',
)]
class ImportPersonnalitePolitiqueCommand extends Command
{
    // On injecte le service généralisé
    public function __construct(private PersonnalitePolitiqueImporter $importer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de l\'importation des Personnalités Politiques');
        $io->info('Vérification et téléchargement des archives (reprise automatique en cas de coupure)...');

        // Appel de la méthode spécifique à l'Assemblée.
        // Plus tard, on rajoutera $this->importer->importDepuisSenat() juste en dessous
        $this->importer->importDepuisAssembleeNationale();

        $io->success('Import des personnalités terminé avec succès !');

        return Command::SUCCESS;
    }
}
