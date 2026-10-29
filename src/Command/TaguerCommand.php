<?php

namespace App\Command;

use App\Entity\Texte;
use App\Enum\Chambre;
use App\Enum\Theme;
use App\Repository\TagRepository;
use App\Repository\TexteRepository;
use App\Service\AmendementResolver;
use App\Service\ThemeClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Classe les textes de loi dans le catalogue des thèmes, sans produire de
 * vulgarisation : un seul appel au modèle par texte (sortie JSON courte).
 *
 * Le texte à classer est l'objet du scrutin (le titre du vote, qui nomme la
 * loi) complété du début de l'amendement quand il est résolu.
 */
#[AsCommand(
    name: 'app:taguer',
    description: 'Classe les textes de loi dans le catalogue des thèmes (IA)',
)]
final class TaguerCommand extends Command
{
    /** Longueur du début de l'amendement envoyée au modèle. */
    private const EXTRAIT = 800;

    public function __construct(
        private readonly TexteRepository $textes,
        private readonly TagRepository $tags,
        private readonly ThemeClassifier $classifieur,
        private readonly AmendementResolver $amendements,
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Retraiter les textes déjà taggés')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Affiche uniquement les compteurs')
            ->addOption('chambre', null, InputOption::VALUE_REQUIRED, 'Filtrer par chambre : AN, SENAT ou all', 'AN');
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
        try {
            $chambre = Chambre::depuisFiltre((string) $input->getOption('chambre'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        [$shard, $nbShards] = $this->decouperShards($input->getOption('shard'));

        $textes = $this->textes->findPourTaguage($force, $limit, $uid, $shard, $nbShards, $chambre);
        $total = \count($textes);

        $io->title('Classement des textes dans le catalogue des thèmes');
        $io->writeln(sprintf(
            'Déjà taggés : <info>%d</info> | à traiter dans ce run : <info>%d</info>',
            $this->tags->compterTextesTagues(),
            $total,
        ));

        if ($total === 0) {
            $io->success('Rien à faire.');

            return Command::SUCCESS;
        }

        $useBar = !$output->isVerbose();
        $bar = $useBar ? $io->createProgressBar($total) : null;
        $bar?->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — %message%');
        $bar?->setMessage('démarrage…');
        $bar?->start();

        $stats = ['ok' => 0, 'sans_theme' => 0, 'erreur' => 0];
        $erreurs = [];
        $index = 0;
        $debut = microtime(true);

        foreach ($textes as $texte) {
            ++$index;
            $identifiant = $texte->getUidScrutin() ?? ('#' . $texte->getId());
            $t0 = microtime(true);

            try {
                $themes = $this->classer($texte);

                if ($themes === []) {
                    $stats['sans_theme']++;
                    $statut = 'sans_theme';
                } else {
                    $this->attacherThemes($texte, $themes);
                    $this->entityManager->flush();
                    $stats['ok']++;
                    $statut = implode(', ', array_map(static fn (Theme $t): string => $t->label(), $themes));
                }
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $statut = 'erreur';
                $erreurs[] = sprintf('%s : %s', $identifiant, $e->getMessage());
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

        $dureeTotale = microtime(true) - $debut;
        $this->afficherBilan($io, $stats, $dureeTotale, $total);

        if ($erreurs !== []) {
            $io->section('Échecs');
            foreach ($erreurs as $erreur) {
                $io->writeln(' - ' . $erreur);
            }
        }

        return $erreurs === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Classe un texte : l'objet du scrutin, complété du début de l'amendement
     * s'il est résolu.
     *
     * @return list<Theme>
     */
    private function classer(Texte $texte): array
    {
        $contenu = trim(($texte->getObjet() ?? '') . ' ' . ($texte->getTitre() ?? ''));

        $amendement = $this->amendements->resolve($texte);
        if ($amendement !== null) {
            $extrait = trim($amendement['contenu']);
            if ($extrait !== '') {
                $contenu .= "\n" . mb_substr($extrait, 0, self::EXTRAIT);
            }
        }

        return $this->classifieur->classer($contenu);
    }

    /**
     * @param list<Theme> $themes
     */
    private function attacherThemes(Texte $texte, array $themes): void
    {
        $tags = [];
        foreach ($themes as $theme) {
            $tags[] = $this->tags->obtenirPour($theme);
        }

        $texte->setThemes($tags);
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
     * @param array{ok: int, sans_theme: int, erreur: int} $stats
     */
    private function afficherBilan(SymfonyStyle $io, array $stats, float $dureeTotale, int $total): void
    {
        $io->table(
            ['Résultat', 'Nombre'],
            [
                ['Tagués', $stats['ok']],
                ['Sans thème reconnu', $stats['sans_theme']],
                ['Erreurs', $stats['erreur']],
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
        $io->title('Avancement du classement par thèmes');
        $io->table(
            ['Indicateur', 'Nombre'],
            [
                ['Textes taggés', $this->tags->compterTextesTagues()],
                ['Textes au total', $this->textes->compterTotal()],
                ['Thèmes du catalogue', \count(Theme::cases())],
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
