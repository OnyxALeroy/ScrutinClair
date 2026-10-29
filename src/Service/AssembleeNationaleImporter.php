<?php
namespace App\Service;

use App\Entity\Texte;
use App\Entity\PersonnalitePolitique;
use App\Entity\Vote;
use App\Enum\Chambre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

class AssembleeNationaleImporter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private EntityManagerInterface $entityManager,
        private AssembleeUrls $urls,
        private ResilientDownloader $downloader,
        private \Doctrine\DBAL\Connection $connection
    ) {}

    public function importTextes(?SymfonyStyle $io = null, ?callable $onProgress = null, ?int $fromYear = null, ?int $toYear = null, ?int $limit = null, bool $force = false, ?string $chambre = null): void
    {
        $dataDir = $this->projectDir . '/data';
        $zipPath = $dataDir . '/scrutins.zip';
        $extractPath = $dataDir . '/scrutins_extraits';

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        // 0. Récupération des noms des groupes politiques
        $groupeRepository = $this->entityManager->getRepository(\App\Entity\GroupePolitique::class);
        $groupesDict = [];
        foreach ($groupeRepository->findAll() as $g) {
            $groupesDict[$g->getUid()] = $g;
        }

        // 1. Téléchargement de l'archive
        $this->downloader->download($this->urls->scrutinsZipUrl(), $zipPath);

        // 2. Extraction
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();

            $finder = new Finder();
            $finder->files()->in($extractPath)->name('*.json');

            $totalFichiers = $finder->count();
            if ($io) {
                $io->writeln("Extraction terminée. Début de la lecture des $totalFichiers scrutins...");
                $io->progressStart($totalFichiers); // Initialise la barre
            }

            $texteRepository = $this->entityManager->getRepository(Texte::class);
            $personnaliteRepository = $this->entityManager->getRepository(PersonnalitePolitique::class);

            // 3. OPTIMISATION EXTRÊME : On charge tous les députés en mémoire RAM
            $deputes = $personnaliteRepository->findAll();
            $deputesDict = [];
            foreach ($deputes as $depute) {
                $deputesDict[$depute->getUid()] = $depute;
            }

            $compteurTextes = 0;
            $compteurVotes = 0;

            $processed = 0;
            foreach ($finder as $file) {
                $jsonContent = $file->getContents();
                $data = json_decode($jsonContent, true);

                if (!isset($data['scrutin'])) {
                    continue;
                }

                // Filter by year range if requested
                $date = $data['scrutin']['dateScrutin'] ?? null;
                $year = null;
                if (is_string($date) && $date !== '') {
                    try {
                        $dt = new \DateTimeImmutable($date);
                        $year = (int) $dt->format('Y');
                    } catch (\Throwable) {
                        $year = null;
                    }
                }
                if ($fromYear !== null && $year !== null && $year < $fromYear) {
                    continue;
                }
                if ($toYear !== null && $year !== null && $year > $toYear) {
                    continue;
                }

                $donneesScrutin = $data['scrutin'];

                // Récupération des infos du texte
                $uid = $donneesScrutin['uid'] ?? null;
                $titre = $donneesScrutin['objet']['libelle'] ?? $donneesScrutin['titre'] ?? 'Scrutin sans titre';

                // Anti-doublon : priorité à l'identifiant stable du scrutin
                if ($uid !== null && $uid !== '') {
                    $texteExistant = $texteRepository->findOneBy(['uidScrutin' => $uid]);
                } else {
                    $texteExistant = $texteRepository->findOneBy(['titre' => $titre]);
                }
                if ($texteExistant !== null) {
                    if ($force) {
                        // remove existing votes for this texte so we can reimport
                        if ($texteExistant->getId() !== null) {
                            $this->connection->executeStatement('DELETE FROM vote WHERE texte_id = ?', [$texteExistant->getId()]);
                        }
                    } else {
                        continue;
                    }
                }

                // Création du Texte
                $texte = new Texte();
                $texte->setTitre($titre);
                $texte->setObjet($donneesScrutin['objet']['libelle'] ?? null);
                $texte->setSynthese($donneesScrutin['objet']['dossierLegislatif']['libelle'] ?? null);
                $texte->setUidScrutin($uid);
                $texte->setChambre(Chambre::AssembleeNationale);
                $texte->setDossierRef($donneesScrutin['objet']['dossierLegislatif']['dossierRef'] ?? null);
                $texte->setLien($this->construireLienScrutin($uid));
                $sort = $donneesScrutin['sort']['libelle'] ?? 'Résultat inconnu';
                $texte->setDescription('Résultat du vote : ' . $sort);
                $texte->setResultat($this->normaliserResultat($donneesScrutin['sort']['code'] ?? null));
                $texte->setDateScrutin($this->dateOuNull($donneesScrutin['dateScrutin'] ?? null));
                $nonVotants = $donneesScrutin['syntheseVote']['decompte']['nonVotants'] ?? null;
                $texte->setNonVotants(is_numeric($nonVotants) ? (int) $nonVotants : null);

                $this->entityManager->persist($texte);
                $compteurTextes++;

                // 4. LECTURE DES VOTES
                $groupes = $donneesScrutin['ventilationVotes']['organe']['groupes']['groupe'] ?? [];
                if (isset($groupes['organeRef'])) {
                    $groupes = [$groupes]; // Formatage forcé en tableau si un seul groupe
                }

                foreach ($groupes as $groupe) {
                    $decompte = $groupe['vote']['decompteNominatif'] ?? null;
                    if (!$decompte) continue;

                    $typesVote = [
                        'Pour' => $decompte['pours']['votant'] ?? [],
                        'Contre' => $decompte['contres']['votant'] ?? [],
                        'Abstention' => $decompte['abstentions']['votant'] ?? []
                    ];

                    foreach ($typesVote as $statut => $votants) {
                        if (empty($votants)) continue;

                        if (isset($votants['acteurRef'])) {
                            $votants = [$votants]; // Formatage forcé en tableau si un seul votant
                        }

                        foreach ($votants as $votant) {
                            $uidActeur = $votant['acteurRef'] ?? null;

                            // Si on trouve le député dans notre dictionnaire en mémoire
                            if ($uidActeur && isset($deputesDict[$uidActeur])) {
                                $vote = new Vote();
                                $vote->setTexte($texte);
                                $vote->setPersonalite($deputesDict[$uidActeur]);
                                $vote->setStatut($statut);
                                $vote->setGroupe($groupe['organeRef'] ?? null);

                                // On récupère le groupe
                                $groupeUid = $groupe['organeRef'] ?? null;
                                if ($groupeUid && isset($groupesDict[$groupeUid])) {
                                    $vote->setGroupePolitique($groupesDict[$groupeUid]);
                                }

                                $this->entityManager->persist($vote);
                                $compteurVotes++;
                            }
                        }
                    }
                }

                // 4.5. On fait avancer la barre de 1 étape à chaque fichier traité
                if ($io) {
                    $io->progressAdvance();
                }
                if ($onProgress !== null && $totalFichiers > 0) {
                    $processed++;
                    $onProgress((int) floor(($processed / $totalFichiers) * 100));
                }

                // Respect limit if provided
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                // 5. Gestion de la mémoire : on vide tous les 20 textes (soit environ 10 000 votes)
                if ($compteurTextes % 20 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();

                    // On recharge députés et groupes, suite au clear qui vide la mémoire Doctrine
                    $deputes = $personnaliteRepository->findAll();
                    $deputesDict = [];
                    foreach ($deputes as $depute) {
                        $deputesDict[$depute->getUid()] = $depute;
                    }

                    $groupes = $groupeRepository->findAll();
                    $groupesDict = [];
                    foreach ($groupes as $groupe) {
                        $groupesDict[$groupe->getUid()] = $groupe;
                    }
                }
            }

            $this->entityManager->flush();

            // On termine la barre proprement
            if ($io) {
                $io->progressFinish();
                $io->newLine(2); // Saute deux lignes pour la lisibilité
                $io->writeln("<info>Bilan final : $compteurTextes textes et $compteurVotes votes insérés en base de données.</info>");
            }
        }
    }

    private function normaliserResultat(?string $resultat): ?string
    {
        if ($resultat === null || $resultat === '') {
            return null;
        }

        return ucfirst(mb_strtolower($resultat, 'UTF-8'));
    }

    private function dateOuNull(mixed $valeur): ?\DateTimeImmutable
    {
        if (!is_string($valeur) || $valeur === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }

    private function construireLienScrutin(?string $uid): ?string
    {
        if ($uid === null || $uid === '') {
            return null;
        }

        // uid de la forme VTANR5L17V4008 : le numéro du scrutin y est encodé.
        if (preg_match('/V(\d+)$/i', $uid, $matches)) {
            return $this->urls->scrutinUrl($matches[1]);
        }

        return null;
    }
}
