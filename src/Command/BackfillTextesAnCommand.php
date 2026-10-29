<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:backfill-textes-an',
    description: 'Complète la date, le résultat et les non-votants des textes AN depuis les JSON locaux.',
)]
final class BackfillTextesAnCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichiers = glob($this->projectDir . '/data/scrutins_extraits/json/*.json') ?: [];
        $misAJour = 0;
        $ignores = 0;
        $sql = "UPDATE texte SET date_scrutin = ?, resultat = ?, non_votants = ?, chambre = 'AN' WHERE uid_scrutin = ?";

        foreach ($fichiers as $index => $fichier) {
            $contenu = file_get_contents($fichier);
            $donnees = $contenu !== false ? json_decode($contenu, true) : null;
            $scrutin = \is_array($donnees) ? ($donnees['scrutin'] ?? null) : null;
            if (!\is_array($scrutin)) {
                $ignores++;

                continue;
            }

            $uid = $scrutin['uid'] ?? null;
            if (!\is_string($uid) || $uid === '') {
                $ignores++;

                continue;
            }

            $date = $scrutin['dateScrutin'] ?? null;
            $resultat = $scrutin['sort']['code'] ?? null;
            $resultat = \is_string($resultat) && $resultat !== '' ? ucfirst(mb_strtolower($resultat, 'UTF-8')) : null;
            $nonVotants = $scrutin['syntheseVote']['decompte']['nonVotants'] ?? null;
            $nonVotants = is_numeric($nonVotants) ? (int) $nonVotants : null;

            $this->connection->executeStatement($sql, [
                \is_string($date) && $date !== '' ? substr($date, 0, 10) : null,
                $resultat,
                $nonVotants,
                $uid,
            ]);
            $misAJour++;

            if (($index + 1) % 1000 === 0) {
                $io->text(sprintf('%d fichiers traités…', $index + 1));
            }
        }

        $io->success(sprintf('%d textes mis à jour, %d fichiers ignorés.', $misAJour, $ignores));

        return Command::SUCCESS;
    }
}
