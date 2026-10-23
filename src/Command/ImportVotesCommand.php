<?php

namespace App\Command;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-votes',
    description: 'Recrée les votes à partir des JSON de scrutins (sans toucher aux textes vulgarisés/tagués)',
)]
final class ImportVotesCommand extends Command
{
    private const DOSSIER_SCRUTINS = '/var/www/symfony/data/scrutins_extraits/json';
    private const TAILLE_LOT = 500;
    private const TEXTES_AVANT_RESET = 50;

    /**
     * Le collecteur SQL du profiler conserve toutes les requêtes en mémoire.
     * Sur 1,2 M d'insertions cela provoquait un OOM ; on le vide régulièrement.
     */
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly BacktraceDebugDataHolder $debugDataHolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de scrutins à traiter')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Importer uniquement ce scrutin (uidScrutin)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Dossier des JSON de scrutins (par défaut data/scrutins_extraits/json)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recrée les votes même s\'ils existent déjà');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $uidCible = $input->getOption('uid');
        $force = (bool) $input->getOption('force');

        $dossier = $input->getOption('dir') ?: self::DOSSIER_SCRUTINS;
        if (!is_dir($dossier)) {
            $io->error(sprintf('Dossier introuvable : %s', $dossier));

            return Command::FAILURE;
        }

        $fichiers = glob($dossier . '/*.json');
        sort($fichiers);

        if ($uidCible !== null && $uidCible !== '') {
            $fichiers = array_values(array_filter(
                $fichiers,
                static fn (string $fichier): bool => basename($fichier, '.json') === $uidCible,
            ));
            if ($fichiers === []) {
                $io->error(sprintf('Aucun fichier de scrutin trouvé pour uid "%s".', $uidCible));

                return Command::FAILURE;
            }
        }

        $personnalites = $this->idsParUid('personnalite_politique');
        $groupes = $this->idsParUid('groupe_politique');

        $io->title('Recréation des votes depuis les scrutins');
        $io->writeln(sprintf('Personnalités connues : %d | Groupes connus : %d', \count($personnalites), \count($groupes)));

        $compteurTextes = 0;
        $compteurVotes = 0;
        $compteurSansTexte = 0;
        $compteurSansPerso = 0;

        $this->debugDataHolder->reset();
        $io->progressStart(\count($fichiers));

        foreach ($fichiers as $fichier) {
            $nom = basename($fichier, '.json');

            $contenu = file_get_contents($fichier);
            if ($contenu === false) {
                $io->progressAdvance();
                continue;
            }

            $donnees = json_decode($contenu, true);
            $scrutin = $donnees['scrutin'] ?? null;
            unset($contenu, $donnees);

            if (!\is_array($scrutin)) {
                $io->progressAdvance();
                continue;
            }

            $uid = $scrutin['uid'] ?? $nom;
            $texteId = $this->connection->fetchOne(
                'SELECT id FROM texte WHERE uid_scrutin = ?',
                [$uid],
                [ParameterType::STRING],
            );

            if ($texteId === false || $texteId === null) {
                ++$compteurSansTexte;
                $io->progressAdvance();
                continue;
            }

            $texteId = (int) $texteId;

            if (!$force && $this->aDesVotes($texteId)) {
                $io->progressAdvance();
                continue;
            }

            $lignes = $this->lignesDeVote($scrutin, $texteId, $personnalites, $groupes, $compteurSansPerso);

            $this->connection->beginTransaction();
            try {
                if ($force) {
                    $this->connection->executeStatement(
                        'DELETE FROM vote WHERE texte_id = ?',
                        [$texteId],
                        [ParameterType::INTEGER],
                    );
                }

                $this->insererParLots($lignes);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }

            if ($lignes !== []) {
                ++$compteurTextes;
                $compteurVotes += \count($lignes);
            }

            if ($compteurTextes > 0 && $compteurTextes % self::TEXTES_AVANT_RESET === 0) {
                $this->debugDataHolder->reset();
            }

            $io->progressAdvance();

            if ($limit !== null && $limit > 0 && $compteurTextes >= $limit) {
                break;
            }
        }

        $this->debugDataHolder->reset();
        $io->progressFinish();
        $io->newLine(2);
        $io->writeln(sprintf('<info>Textes avec votes créés : %d | Votes insérés : %d</info>', $compteurTextes, $compteurVotes));
        $io->writeln(sprintf('Textes sans correspondance en base : %d | Votants non trouvés (ignorés) : %d', $compteurSansTexte, $compteurSansPerso));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function idsParUid(string $table): array
    {
        /** @var array<string, int> $ids */
        $ids = $this->connection->fetchAllKeyValue(
            sprintf('SELECT uid, id FROM %s WHERE uid IS NOT NULL', $table),
        );

        return $ids;
    }

    private function aDesVotes(int $texteId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM vote WHERE texte_id = ? LIMIT 1',
            [$texteId],
            [ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, int> $personnalites
     * @param array<string, int> $groupesIds
     *
     * @return list<array{statut: string, texte_id: int, personalite_id: int, groupe: ?string, groupe_politique_id: ?int}>
     */
    private function lignesDeVote(array $scrutin, int $texteId, array $personnalites, array $groupesIds, int &$compteurSansPerso): array
    {
        $groupes = $scrutin['ventilationVotes']['organe']['groupes']['groupe'] ?? [];
        if (isset($groupes['organeRef'])) {
            $groupes = [$groupes];
        }

        if (!\is_array($groupes)) {
            return [];
        }

        $lignes = [];
        foreach ($groupes as $groupe) {
            if (!\is_array($groupe)) {
                continue;
            }

            $decompte = $groupe['vote']['decompteNominatif'] ?? null;
            if (!\is_array($decompte)) {
                continue;
            }

            $groupeUid = $groupe['organeRef'] ?? null;
            $groupeId = $groupeUid !== null ? ($groupesIds[$groupeUid] ?? null) : null;

            $typesVote = [
                'Pour' => $decompte['pours']['votant'] ?? [],
                'Contre' => $decompte['contres']['votant'] ?? [],
                'Abstention' => $decompte['abstentions']['votant'] ?? [],
            ];

            foreach ($typesVote as $statut => $votants) {
                if (empty($votants) || !\is_array($votants)) {
                    continue;
                }

                if (isset($votants['acteurRef'])) {
                    $votants = [$votants];
                }

                foreach ($votants as $votant) {
                    if (!\is_array($votant)) {
                        continue;
                    }

                    $uidActeur = $votant['acteurRef'] ?? null;
                    if (!$uidActeur || !isset($personnalites[$uidActeur])) {
                        if ($uidActeur) {
                            ++$compteurSansPerso;
                        }
                        continue;
                    }

                    $lignes[] = [
                        'statut' => $statut,
                        'texte_id' => $texteId,
                        'personalite_id' => $personnalites[$uidActeur],
                        'groupe' => $groupeUid,
                        'groupe_politique_id' => $groupeId,
                    ];
                }
            }
        }

        return $lignes;
    }

    /**
     * @param list<array{statut: string, texte_id: int, personalite_id: int, groupe: ?string, groupe_politique_id: ?int}> $lignes
     */
    private function insererParLots(array $lignes): void
    {
        foreach (array_chunk($lignes, self::TAILLE_LOT) as $lot) {
            $placeholders = implode(', ', array_fill(0, \count($lot), '(?, ?, ?, ?, ?)'));
            $parametres = [];
            $types = [];

            foreach ($lot as $ligne) {
                $parametres[] = $ligne['statut'];
                $parametres[] = $ligne['texte_id'];
                $parametres[] = $ligne['personalite_id'];
                $parametres[] = $ligne['groupe'];
                $parametres[] = $ligne['groupe_politique_id'];

                $types[] = ParameterType::STRING;
                $types[] = ParameterType::INTEGER;
                $types[] = ParameterType::INTEGER;
                $types[] = $ligne['groupe'] === null ? ParameterType::NULL : ParameterType::STRING;
                $types[] = $ligne['groupe_politique_id'] === null ? ParameterType::NULL : ParameterType::INTEGER;
            }

            $this->connection->executeStatement(
                sprintf('INSERT INTO vote (statut, texte_id, personalite_id, groupe, groupe_politique_id) VALUES %s', $placeholders),
                $parametres,
                $types,
            );
        }
    }
}
