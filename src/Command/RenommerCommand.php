<?php

namespace App\Command;

use App\Entity\Texte;
use App\Repository\TexteRepository;
use App\Service\TitreCourtGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère le titre court (« grand public ») des textes de loi, en conservant
 * le titre officiel complet en base.
 *
 * La génération est reprise là où elle s'est arrêtée : seuls les textes sans
 * titre court sont traités, sauf avec --force.
 */
#[AsCommand(
    name: 'app:renommer',
    description: 'Rédige un titre court pour chaque texte de loi (IA)',
)]
final class RenommerCommand extends Command
{
    public function __construct(
        private readonly TexteRepository $textes,
        private readonly TitreCourtGenerator $generateur,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de textes à traiter')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Traiter un seul scrutin (uidScrutin)')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Traitement réparti : « i/n » (ex. 0/2)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Retraiter les textes déjà renommés')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Affiche uniquement les compteurs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->afficherStatut($io);
        }

        $force = (bool) $input->getOption('force');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $uid = $input->getOption('uid');
        [$shard, $nbShards] = $this->decouperShards($input->getOption('shard'));

        $textes = $this->textes->findPourRenommage($force, $limit, $uid, $shard, $nbShards);
        $total = \count($textes);

        $io->title('Génération des titres courts (grand public)');
        $io->writeln(sprintf(
            'Déjà renommés : <info>%d</info> | à traiter dans ce run : <info>%d</info>',
            $this->textes->compterAvecTitreCourt(),
            $total,
        ));

        if ($total === 0) {
            $io->success('Rien à faire.');

            return Command::SUCCESS;
        }

        $useBar = !$output->isVerbose();
        $bar = $useBar ? $io->createProgressBar($total) : null;
        $bar?->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s% — %message%');
        $bar?->setMessage('démarrage…');
        $bar?->start();

        $stats = ['ia' => 0, 'tel_quel' => 0, 'sans_titre' => 0, 'erreur' => 0];
        $resultats = [];
        $index = 0;
        $debut = microtime(true);

        foreach ($textes as $texte) {
            ++$index;
            $identifiant = $texte->getUidScrutin() ?? ('#' . $texte->getId());
            $t0 = microtime(true);

            try {
                $titreCourt = $this->genererPour($texte);

                if ($titreCourt === null) {
                    if ($force && $texte->getTitreCourt() !== null) {
                        $texte->setTitreCourt(null)->setTitreCourtAt(null);
                        $this->entityManager->flush();
                    }

                    $stats['sans_titre']++;
                    $statut = 'aucun titre court';
                } else {
                    $titreCourt = trim($titreCourt);
                    $texte->setTitreCourt($titreCourt)->setTitreCourtAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                    $estIa = $titreCourt !== $texte->getTitreLong();
                    $stats[$estIa ? 'ia' : 'tel_quel']++;
                    $statut = $estIa ? $titreCourt : 'déjà court, inchangé';
                }

                if (\count($resultats) < 400) {
                    $resultats[] = [$identifiant, mb_substr($texte->getTitreLong(), 0, 95), $titreCourt ?? '—'];
                }
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $statut = 'erreur';
                $resultats[] = [$identifiant, mb_substr($texte->getTitreLong(), 0, 95), 'ERREUR : ' . $e->getMessage()];
            }

            $duree = microtime(true) - $t0;

            if ($bar !== null) {
                $bar->setMessage(sprintf('%s — %s (%.1fs)', $identifiant, $statut, $duree));
                $bar->advance();
            } else {
                $io->writeln(sprintf('[%d/%d] %s — %s (%.1fs)', $index, $total, $identifiant, $statut, $duree));
            }
        }

        $bar?->finish();
        $io->newLine(2);

        if ($output->isVerbose() && $resultats !== []) {
            $io->section('Échantillon des titres générés');
            $io->table(['Scrutin', 'Titre officiel', 'Titre court'], $resultats);
        }

        $this->afficherBilan($io, $stats, microtime(true) - $debut, $total);

        return $stats['erreur'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Retourne le titre court, ou null si rien d'exploitable n'a été trouvé.
     */
    private function genererPour(Texte $texte): ?string
    {
        $titreLong = $texte->getTitreLong();

        // Déjà court : aucun appel IA, on fige la valeur telle quelle pour
        // ne pas retraiter ce texte à chaque run.
        if (mb_strlen($titreLong) <= TitreCourtGenerator::LONGUEUR_MAX) {
            return $titreLong;
        }

        return $this->generateur->generer($titreLong, $texte->getSynthese());
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function decouperShards(?string $valeur): array
    {
        if ($valeur === null || $valeur === '') {
            return [null, null];
        }

        if (!preg_match('/^(\d+)\/(\d+)$/', $valeur, $correspondances)) {
            throw new \InvalidArgumentException(sprintf('Shard invalide : « %s » (attendu « i/n »).', $valeur));
        }

        $shard = (int) $correspondances[1];
        $nbShards = (int) $correspondances[2];

        if ($nbShards < 1 || $shard < 0 || $shard >= $nbShards) {
            throw new \InvalidArgumentException(sprintf('Shard hors bornes : « %s ».', $valeur));
        }

        return [$shard, $nbShards];
    }

    /**
     * @param array{ia: int, tel_quel: int, sans_titre: int, erreur: int} $stats
     */
    private function afficherBilan(SymfonyStyle $io, array $stats, float $dureeTotale, int $total): void
    {
        $io->table(
            ['Résultat', 'Nombre'],
            [
                ['Titres rédigés par l\'IA', $stats['ia']],
                ['Déjà courts, conservés tels quels', $stats['tel_quel']],
                ['Sans titre court exploitable', $stats['sans_titre']],
                ['Erreurs', $stats['erreur']],
                ['Total traité', $total],
            ],
        );

        $io->writeln(sprintf(
            'Durée : <info>%s</info> — vitesse : <info>%.2f</info> texte(s)/s — moyenne : <info>%.2f</info> s/texte',
            $this->formaterDuree($dureeTotale),
            $dureeTotale > 0 ? $total / $dureeTotale : 0.0,
            $total > 0 ? $dureeTotale / $total : 0.0,
        ));
    }

    private function afficherStatut(SymfonyStyle $io): int
    {
        $io->title('Avancement des titres courts');
        $io->table(
            ['Indicateur', 'Nombre'],
            [
                ['Textes avec titre court', $this->textes->compterAvecTitreCourt()],
                ['Textes au total', $this->textes->compterTotal()],
            ],
        );

        return Command::SUCCESS;
    }

    private function formaterDuree(float $secondes): string
    {
        $total = (int) round($secondes);
        $heures = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secondesRestantes = $total % 60;

        if ($heures > 0) {
            return sprintf('%dh%02dm%02ds', $heures, $minutes, $secondesRestantes);
        }

        return sprintf('%dm%02ds', $minutes, $secondesRestantes);
    }
}
