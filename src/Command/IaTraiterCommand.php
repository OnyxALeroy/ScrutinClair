<?php

namespace App\Command;

use App\Entity\Texte;
use App\Enum\Theme;
use App\Repository\TagRepository;
use App\Repository\TexteRepository;
use App\Service\AmendementResolver;
use App\Service\AiAvailability;
use App\Service\ThemeClassifier;
use App\Service\TitreCourtGenerator;
use App\Service\VulgarisationGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ia:traiter',
    description: 'Traite titre court, tags et résumé texte par texte avec mutualisation du contexte',
)]
final class IaTraiterCommand extends Command
{
    private const EXTRAIT_AMENDEMENT = 800;

    public function __construct(
        private readonly TexteRepository $textes,
        private readonly TagRepository $tags,
        private readonly TitreCourtGenerator $titreCourtGenerator,
        private readonly ThemeClassifier $classifieur,
        private readonly VulgarisationGenerator $vulgarisationGenerator,
        private readonly AmendementResolver $amendements,
        private readonly AiAvailability $ai,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de textes à traiter (défaut : tous)')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Traiter un seul scrutin (uidScrutin)')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Traitement réparti : « i/n » (ex. 0/2)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Retraiter même si déjà fait pour chaque étape')
            ->addOption('skip-titre', null, InputOption::VALUE_NONE, 'Ne pas générer le titre court')
            ->addOption('skip-tags', null, InputOption::VALUE_NONE, 'Ne pas attribuer les tags')
            ->addOption('skip-resume', null, InputOption::VALUE_NONE, 'Ne pas générer le résumé/vulgarisation')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Affiche uniquement les compteurs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->afficherStatut($io);
        }

        if (!$input->getOption('skip-resume') && !$this->ai->isEnabled()) {
            $io->error('IA désactivée. Active AI_ENABLED=1 ou utilise --skip-resume.');

            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force');
        $faireTitre = !(bool) $input->getOption('skip-titre');
        $faireTags = !(bool) $input->getOption('skip-tags');
        $faireResume = !(bool) $input->getOption('skip-resume');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $uid = $input->getOption('uid');
        [$shard, $nbShards] = $this->decouperShards($input->getOption('shard'));

        $textes = $this->textes->findPourTraitementIa($force, $faireTitre, $faireTags, $faireResume, $limit, $uid, $shard, $nbShards);
        $total = \count($textes);

        $io->title('Traitement IA unifié : titre court + tags + résumé');
        $io->writeln(sprintf(
            'À traiter dans ce run : <info>%d</info> (titre:%s tags:%s résumé:%s | force:%s)',
            $total,
            $faireTitre ? 'oui' : 'non',
            $faireTags ? 'oui' : 'non',
            $faireResume ? 'oui' : 'non',
            $force ? 'oui' : 'non',
        ));

        if ($total === 0) {
            $io->success('Rien à faire.');

            return Command::SUCCESS;
        }

        $useBar = !$output->isVerbose();
        $bar = $useBar ? $io->createProgressBar($total) : null;
        $bar?->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — %message%');
        $bar?->start();

        $stats = ['titre_ok' => 0, 'titre_skip' => 0, 'tags_ok' => 0, 'tags_skip' => 0, 'resume_ok' => 0, 'resume_no_source' => 0, 'resume_skip' => 0, 'erreur' => 0];
        $erreurs = [];
        $index = 0;
        $debut = microtime(true);

        foreach ($textes as $texte) {
            ++$index;
            $identifiant = $texte->getUidScrutin() ?? ('#' . $texte->getId());
            $t0 = microtime(true);

            try {
                $this->traiterTexte($texte, $identifiant, $force, $faireTitre, $faireTags, $faireResume, $bar, $stats, $erreurs);
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $erreurs[] = sprintf('%s : %s', $identifiant, $e->getMessage());
                if ($bar !== null) {
                    $bar->setMessage(sprintf('%s — erreur globale (%.1fs)', $identifiant, microtime(true) - $t0));
                } else {
                    $io->writeln(sprintf('[%d/%d] %s — erreur globale (%.1fs)', $index, $total, $identifiant, microtime(true) - $t0));
                }
            }

            if ($bar !== null) {
                $bar->advance();
            }
        }

        $bar?->finish();
        $io->newLine(2);

        $dureeTotale = microtime(true) - $debut;
        $this->afficherBilan($io, $stats, $dureeTotale, $total);

        if ($erreurs !== []) {
            $io->section('Échecs');
            foreach (array_slice($erreurs, 0, 40) as $erreur) {
                $io->writeln(' - ' . $erreur);
            }
            if (\count($erreurs) > 40) {
                $io->writeln(sprintf('  ... et %d autres erreurs', \count($erreurs) - 40));
            }
        }

        return $erreurs === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array{titre_ok:int,titre_skip:int,tags_ok:int,tags_skip:int,resume_ok:int,resume_no_source:int,resume_skip:int,erreur:int} $stats
     * @param list<string> $erreurs
     */
    private function traiterTexte(Texte $texte, string $identifiant, bool $force, bool $faireTitre, bool $faireTags, bool $faireResume, $bar, array &$stats, array &$erreurs): void
    {
        $contexte = null;
        try {
            $contexte = $this->vulgarisationGenerator->resoudre($texte);
        } catch (\Throwable) {
            $contexte = null;
        }

        $titreLong = $texte->getTitreLong();

        // 1) Titre court
        if (!$faireTitre) {
            $stats['titre_skip']++;
        } elseif (!$force && $this->aTitreCourt($texte)) {
            $stats['titre_skip']++;
        } else {
            if ($bar !== null) {
                $bar->setMessage(sprintf('%s — titre court', $identifiant));
            }
            try {
                if (mb_strlen($titreLong) <= TitreCourtGenerator::LONGUEUR_MAX) {
                    $texte->setTitreCourt($titreLong)->setTitreCourtAt(new \DateTimeImmutable());
                    $stats['titre_ok']++;
                } else {
                    $titreCourt = $this->titreCourtGenerator->generer($titreLong, $texte->getSynthese());
                    if ($titreCourt !== null && trim($titreCourt) !== '') {
                        $texte->setTitreCourt(trim($titreCourt))->setTitreCourtAt(new \DateTimeImmutable());
                        $stats['titre_ok']++;
                    } else {
                        // rien d'exploitable : on laisse vide, on ne bloque pas
                        $stats['titre_skip']++;
                    }
                }
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $erreurs[] = sprintf('%s [titre] : %s', $identifiant, $e->getMessage());
            }
        }

        // 2) Tags
        if (!$faireTags) {
            $stats['tags_skip']++;
        } elseif (!$force && $this->aDesTags($texte)) {
            $stats['tags_skip']++;
        } else {
            if ($bar !== null) {
                $bar->setMessage(sprintf('%s — tags', $identifiant));
            }
            try {
                $contenu = trim(($texte->getObjet() ?? '') . ' ' . ($texte->getTitre() ?? ''));
                $amendement = $this->amendements->resolve($texte);
                if ($amendement !== null) {
                    $extrait = trim((string) ($amendement['contenu'] ?? ''));
                    if ($extrait !== '') {
                        $contenu .= "\n" . mb_substr($extrait, 0, self::EXTRAIT_AMENDEMENT);
                    }
                }

                $themes = $this->classifieur->classer($contenu);
                $this->attacherThemes($texte, $themes);
                $this->entityManager->flush();
                if ($themes !== []) {
                    $stats['tags_ok']++;
                } else {
                    $stats['tags_skip']++;
                }
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $erreurs[] = sprintf('%s [tags] : %s', $identifiant, $e->getMessage());
            }
        }

        // 3) Résumé/vulgarisation
        if (!$faireResume) {
            $stats['resume_skip']++;
        } elseif (!$force && $texte->getVulgarisation() !== null) {
            $stats['resume_skip']++;
        } else {
            if ($bar !== null) {
                $bar->setMessage(sprintf('%s — résumé', $identifiant));
            }
            try {
                $resultat = $this->vulgarisationGenerator->generer($texte, null);
                if ($resultat === null) {
                    $texte->setVulgarisationStatut('no_source');
                    $this->entityManager->flush();
                    $stats['resume_no_source']++;
                } else {
                    $texte->setVulgarisation((string) ($resultat['contenu'] ?? ''));
                    $texte->setVulgarisationSource($resultat['source'] ?? null);
                    $texte->setVulgarisationStatut('ok');
                    $texte->setVulgarisationAt(new \DateTimeImmutable());
                    if (!empty($resultat['themes']) && \is_array($resultat['themes'])) {
                        $themes = array_values(array_filter($resultat['themes'], static fn ($t): bool => $t instanceof Theme));
                        if ($themes !== []) {
                            $this->attacherThemes($texte, $themes);
                        }
                    }
                    $this->entityManager->flush();
                    $stats['resume_ok']++;
                }
            } catch (\Throwable $e) {
                $stats['erreur']++;
                $erreurs[] = sprintf('%s [resume] : %s', $identifiant, $e->getMessage());
                try {
                    $texte->setVulgarisationStatut('error');
                    $this->entityManager->flush();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function aTitreCourt(Texte $texte): bool
    {
        $tc = $texte->getTitreCourt();

        return \is_string($tc) && trim($tc) !== '';
    }

    private function aDesTags(Texte $texte): bool
    {
        return !$texte->getThemes()->isEmpty();
    }

    /**
     * @param list<Theme> $themes
     */
    private function attacherThemes(Texte $texte, array $themes): void
    {
        if ($themes === []) {
            return;
        }

        $tags = [];
        foreach ($themes as $theme) {
            if (!$theme instanceof Theme) {
                continue;
            }
            $tags[] = $this->tags->obtenirPour($theme);
        }

        if ($tags !== []) {
            $texte->setThemes($tags);
        }
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

    private function afficherStatut(SymfonyStyle $io): int
    {
        $io->title('Avancement traitement IA unifié');
        $io->table(
            ['Indicateur', 'Nombre'],
            [
                ['Textes au total', $this->textes->compterTotal()],
                ['Sans titre court', $this->textes->compterSansTitreCourt()],
                ['Sans tags', $this->textes->compterSansTags()],
                ['Sans vulgarisation', $this->textes->compterSansVulgarisation()],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * @param array{titre_ok:int,titre_skip:int,tags_ok:int,tags_skip:int,resume_ok:int,resume_no_source:int,resume_skip:int,erreur:int} $stats
     */
    private function afficherBilan(SymfonyStyle $io, array $stats, float $dureeTotale, int $total): void
    {
        $io->table(
            ['Étape', 'Résultat', 'Nombre'],
            [
                ['Titre court', 'Généré', $stats['titre_ok']],
                ['Titre court', 'Ignoré/déjà fait', $stats['titre_skip']],
                ['Tags', 'Attribués', $stats['tags_ok']],
                ['Tags', 'Ignorés/déjà fait/sans thème', $stats['tags_skip']],
                ['Résumé', 'Généré', $stats['resume_ok']],
                ['Résumé', 'Sans source', $stats['resume_no_source']],
                ['Résumé', 'Ignoré/déjà fait', $stats['resume_skip']],
                ['Global', 'Erreurs', $stats['erreur']],
                ['Global', 'Textes traités', $total],
            ],
        );

        if ($total > 0) {
            $io->writeln(sprintf(
                'Durée : <info>%s</info> — %.2f texte(s)/s — %.2f s/texte',
                $this->formaterDuree($dureeTotale),
                $dureeTotale > 0 ? $total / $dureeTotale : 0.0,
                $dureeTotale / $total,
            ));
        }
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