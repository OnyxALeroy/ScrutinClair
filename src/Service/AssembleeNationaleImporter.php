<?php
namespace App\Service;

use App\Entity\Texte;
use App\Entity\PersonnalitePolitique;
use App\Entity\Vote;
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
        #[Autowire('%env(API_ASSEMBLEE_URL)%')]
        private string $apiUrl,
        private ResilientDownloader $downloader
    ) {}

    public function importTextes(?SymfonyStyle $io = null): void
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
        $this->downloader->download($this->apiUrl, $zipPath);

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

            foreach ($finder as $file) {
                $jsonContent = $file->getContents();
                $data = json_decode($jsonContent, true);

                if (!isset($data['scrutin'])) {
                    continue;
                }

                $donneesScrutin = $data['scrutin'];

                // Récupération des infos du texte
                $titre = $donneesScrutin['objet']['libelle'] ?? $donneesScrutin['titre'] ?? 'Scrutin sans titre';

                // Anti-doublon
                $texteExistant = $texteRepository->findOneBy(['titre' => $titre]);
                if ($texteExistant !== null) {
                    continue;
                }

                // Création du Texte
                $texte = new Texte();
                $texte->setTitre($titre);
                $sort = $donneesScrutin['sort']['libelle'] ?? 'Résultat inconnu';
                $texte->setDescription('Résultat du vote : ' . $sort);

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
}
