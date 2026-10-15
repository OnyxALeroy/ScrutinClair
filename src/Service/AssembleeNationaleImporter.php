<?php
namespace App\Service;

use App\Entity\Texte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class AssembleeNationaleImporter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(API_ASSEMBLEE_URL)%')]
        private string $apiUrl,

        private EntityManagerInterface $entityManager,
        private ResilientDownloader $downloader
    ) {
    }

    public function importTextes(): void
    {
        $dataDir = $this->projectDir . '/data';
        $zipPath = $dataDir . '/scrutins.zip';
        $extractPath = $dataDir . '/scrutins_extraits';

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        // 1. Téléchargement robuste et automatique
        // Si le fichier existe déjà et est complet, ça prendra 0 seconde.
        // S'il est incomplet ou absent, ça le téléchargera.
        $this->downloader->download($this->apiUrl, $zipPath);

        // 2. Extraction du ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();

            // 3. Outil Symfony natif pour fouiller dans les dossiers extraits
            $finder = new Finder();
            // On cherche tous les fichiers qui finissent par .json dans le dossier extrait
            $finder->files()->in($extractPath)->name('*.json');

            if ($finder->hasResults()) {
                // On prend le tout premier fichier trouvé pour l'analyser
                $iterator = $finder->getIterator();
                $iterator->rewind();

                $finder = new Finder();
                $finder->files()->in($extractPath)->name('*.json');

                $texteRepository = $this->entityManager->getRepository(Texte::class);
                $compteur = 0;

                foreach ($finder as $file) {
                    $jsonContent = $file->getContents();
                    $data = json_decode($jsonContent, true);

                    // On vérifie que la clé scrutin existe bien dans ce fichier
                    if (!isset($data['scrutin'])) {
                        continue;
                    }

                    $donneesScrutin = $data['scrutin'];

                    // 1. Récupération du titre (on utilise objet.libelle comme on l'a vu dans le dd)
                    // L'opérateur ?? '' évite une erreur si la clé n'existe pas
                    $titre = $donneesScrutin['objet']['libelle'] ?? $donneesScrutin['titre'] ?? 'Scrutin sans titre';

                    // 2. Anti-doublon (pour pouvoir relancer l'import sans tout dupliquer)
                    $texteExistant = $texteRepository->findOneBy(['titre' => $titre]);
                    if ($texteExistant !== null) {
                        continue;
                    }

                    // 3. Création de l'Entité
                    $texte = new Texte();
                    $texte->setTitre($titre);

                    // On récupère le résultat (adopté / rejeté)
                    $sort = $donneesScrutin['sort']['libelle'] ?? 'Résultat inconnu';
                    $texte->setDescription('Résultat du vote : ' . $sort);

                    $this->entityManager->persist($texte);
                    $compteur++;

                    // Petite optimisation : on flush par paquets de 100 pour ne pas saturer la RAM de PHP
                    if ($compteur % 100 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear(); // Vide la mémoire de Doctrine
                    }
                }

                // On flush le reste à la fin
                $this->entityManager->flush();

                // On peut afficher un petit message dans la console à la fin
                echo "Import terminé. " . $compteur . " nouveaux scrutins insérés.\n";
            }
        }
    }
}
