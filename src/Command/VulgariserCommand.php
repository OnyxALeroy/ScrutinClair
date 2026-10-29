<?php

namespace App\Command;

use App\Entity\Texte;
use App\Enum\Chambre;
use App\Enum\Theme;
use App\Repository\TagRepository;
use App\Repository\TexteRepository;
use App\Service\AiAvailability;
use App\Service\VulgarisationGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:vulgariser',
    description: 'Génère la vulgarisation grand public des textes via Ollama',
)]
final class VulgariserCommand extends Command
{
    public function __construct(
        private readonly TexteRepository $textes,
        private readonly TagRepository $tags,
        private readonly VulgarisationGenerator $generator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiAvailability $ai,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de textes à traiter')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Traiter un seul scrutin (uidScrutin)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filtrer : amendement ou dossier')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Traitement réparti : « i/n » (ex. 0/2)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Retraiter les textes déjà vulgarisés')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Résout les sources sans appeler l\'IA')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Affiche uniquement les compteurs d\'avancement')
            ->addOption('chambre', null, InputOption::VALUE_REQUIRED, 'Filtrer par chambre : AN, SENAT ou all', 'AN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->afficherStatut($io);
        }

        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $uid = $input->getOption('uid');
        $type = $input->getOption('type');
        try {
            $chambre = Chambre::depuisFiltre((string) $input->getOption('chambre'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }


        if (!$dryRun && !$this->ai->isEnabled()) {
            $io->error('La génération IA est désactivée sur cette instance (AI_ENABLED=0) : aucun rapport ne peut être généré.');
            $io->writeln('Passez en mode IA (<info>make ai-up</info>) ou réactivez-la avec <info>AI_ENABLED=1</info> pour lancer la vulgarisation.');

            return Command::FAILURE;
        }

        [$shard, $nbShards] = $this->decouperShards($input->getOption('shard'));

        $textes = $this->textes->findPourVulgarisation($force, $limit, $uid, $type, $shard, $nbShards, $chambre);
        $total = \count($textes);

        $io->title($dryRun ? 'Vulgarisation — résolution des sources (dry-run)' : 'Vulgarisation des textes');
        $io->writeln(sprintf(
            'Déjà vulgarisés : <info>%d</info> | à traiter dans ce run : <info>%d</info>%s',
            $this->textes->compterVulgarises(),
            $total,
            $limit !== null ? sprintf(' (limite %d)', $limit) : '',
        ));

        if ($total === 0) {
            $io->success('Rien à faire.');

            return Command::SUCCESS;
        }

        $useBar = !$output->isVerbose() && !$dryRun;
        $bar = $useBar ? $io->createProgressBar($total) : null;
        if ($bar !== null) {
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — %message%');
            $bar->setMessage('démarrage…');
            $bar->start();
        }

        $stats = ['ok' => 0, 'no_source' => 0, 'error' => 0, 'tagnes' => 0];
        $erreurs = [];
        $index = 0;
        $debut = microtime(true);

        foreach ($textes as $texte) {
            ++$index;
            $identifiant = $texte->getUidScrutin() ?? ('#' . $texte->getId());
            $t0 = microtime(true);

            $onEtape = function (string $phase, int $courant, int $totalMorceaux) use ($bar, $identifiant): void {
                if ($bar !== null) {
                    $bar->setMessage(sprintf('%s — %s %d/%d', $identifiant, $phase, $courant, $totalMorceaux));
                    $bar->display();
                }
            };

            if ($bar !== null) {
                $bar->setMessage($identifiant . ' — résolution…');
                $bar->display();
            }

            try {
                if ($dryRun) {
                    $source = $this->generator->resoudre($texte);
                    $statut = $source === null ? 'no_source' : ('source:' . $source['type']);
                    ++$stats[$source === null ? 'no_source' : 'ok'];
                } else {
                    $resultat = $this->generator->generer($texte, $onEtape);
                    if ($resultat === null) {
                        $texte->setVulgarisationStatut('no_source');
                        $stats['no_source']++;
                        $statut = 'no_source';
                    } else {
                        $texte->setVulgarisation($resultat['contenu']);
                        $texte->setVulgarisationSource($resultat['source']);
                        $texte->setVulgarisationStatut('ok');
                        $texte->setVulgarisationAt(new \DateTimeImmutable());
                        $stats['ok']++;

                        if ($this->attacherThemes($texte, $resultat['themes']) > 0) {
                            $stats['tagnes']++;
                        }

                        $statut = 'ok';
                    }
                    $this->entityManager->flush();
                }
            } catch (\Throwable $e) {
                $stats['error']++;
                $statut = 'error';
                $erreurs[] = sprintf('%s : %s', $identifiant, $e->getMessage());
                if (!$dryRun) {
                    $texte->setVulgarisationStatut('error');
                    $this->entityManager->flush();
                }
            }

            $duree = microtime(true) - $t0;

            if ($bar !== null) {
                $bar->setMessage(sprintf('%s — %s (%.1fs)', $identifiant, $statut, $duree));
                $bar->advance();
            } else {
                $io->writeln(sprintf(
                    '[%d/%d] %s — %s (%.1fs)',
                    $index,
                    $total,
                    $identifiant,
                    $statut,
                    $duree,
                ));
            }
        }

        if ($bar !== null) {
            $bar->finish();
            $io->newLine(2);
        }

        $dureeTotale = microtime(true) - $debut;
        $this->afficherBilan($io, $stats, $dureeTotale, $total);

        if ($erreurs !== []) {
            $io->section('Échecs');
            foreach ($erreurs as $erreur) {
                $io->writeln(' - ' . $erreur);
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Découpe l'option « i/n » en morceau courant et nombre de morceaux.
     *
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
     * Rattache les thèmes du texte et retourne le nombre de thèmes posés.
     *
     * @param list<Theme> $themes
     */
    private function attacherThemes(Texte $texte, array $themes): int
    {
        if ($themes === []) {
            return 0;
        }

        $tags = [];
        foreach ($themes as $theme) {
            $tags[] = $this->tags->obtenirPour($theme);
        }

        $texte->setThemes($tags);

        return \count($tags);
    }

    /**
     * @param array{ok: int, no_source: int, error: int, tagnes: int} $stats
     */
    private function afficherBilan(SymfonyStyle $io, array $stats, float $dureeTotale, int $total): void
    {
        $io->table(
            ['Résultat', 'Nombre'],
            [
                ['Vulgarisés (ok)', $stats['ok']],
                ['Dont taggés', $stats['tagnes']],
                ['Sans source exploitable', $stats['no_source']],
                ['Erreurs', $stats['error']],
                ['Total traité', $total],
            ],
        );

        $parTexte = $total > 0 ? $dureeTotale / $total : 0;
        $io->writeln(sprintf(
            'Durée : <info>%s</info> — vitesse : <info>%.2f</info> texte(s)/s — moyenne : <info>%.2f</info> s/texte',
            $this->formaterDuree($dureeTotale),
            $dureeTotale > 0 ? $total / $dureeTotale : 0.0,
            $parTexte,
        ));
    }

    private function afficherStatut(SymfonyStyle $io): int
    {
        $compteurs = $this->textes->compterParStatut();
        $total = array_sum($compteurs);
        $vulgarises = $this->textes->compterVulgarises();

        $io->title('Avancement de la vulgarisation');
        $io->table(
            ['Statut', 'Nombre'],
            [
                ['Vulgarisés', $vulgarises],
                ['ok', $compteurs['ok'] ?? 0],
                ['no_source', $compteurs['no_source'] ?? 0],
                ['error', $compteurs['error'] ?? 0],
                ['Sans statut (non traité)', $compteurs['null'] ?? 0],
                ['Total textes', $total],
                ['Textes taggés', $this->tags->compterTextesTagues()],
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
