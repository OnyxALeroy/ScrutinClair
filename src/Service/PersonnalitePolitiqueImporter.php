<?php
namespace App\Service;

use App\Entity\PersonnalitePolitique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class PersonnalitePolitiqueImporter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(API_ACTEURS_ASSEMBLEE_URL)%')]
        private string $apiUrlAssemblee,
        private EntityManagerInterface $entityManager,
        private ResilientDownloader $downloader
    ) {}

    /**
     * Importe les personnalités politiques depuis l'Assemblée Nationale (Députés)
     * À l'avenir, vous pourrez ajouter des méthodes importDepuisSenat(), etc.
     */
    public function importDepuisAssembleeNationale(): void
    {
        $dataDir = $this->projectDir . '/data';
        $zipPath = $dataDir . '/acteurs_assemblee.zip';
        $extractPath = $dataDir . '/acteurs_assemblee_extraits';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        // 1. Téléchargement robuste
        $this->downloader->download($this->apiUrlAssemblee, $zipPath);

        // 2. Extraction du ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();

            // 3.1. Import des groupes politiques
            $groupeRepository = $this->entityManager->getRepository(\App\Entity\GroupePolitique::class);
            $finderGroupes = new Finder();
            $finderGroupes->files()->in($extractPath)->name('PO*.json');

            foreach ($finderGroupes as $file) {
                $data = json_decode($file->getContents(), true);
                // On ne garde que les Groupes Politiques (GP)
                if (!isset($data['organe']) || $data['organe']['codeType'] !== 'GP')
                    continue;

                $uid = $data['organe']['uid'];
                $nom = $data['organe']['libelle'];
                $acronyme = $data['organe']['libelleAbrev'] ?? $nom;

                $groupe = $groupeRepository->findOneBy(['uid' => $uid]);
                if (!$groupe) {
                    $groupe = new \App\Entity\GroupePolitique();
                    $groupe->setUid($uid);
                    // On met du gris et un ordre arbitraire par défaut
                    $groupe->setCouleur('#9ca3af');
                    $groupe->setOrdre(99);
                }

                $groupe->setNom($nom);
                $groupe->setAcronyme($acronyme);
                $this->entityManager->persist($groupe);
            }
            $this->entityManager->flush();
            echo "Groupes politiques mis à jour.\n";

            // 3.2. Outil Symfony natif pour fouiller dans les dossiers extraits
            $finder = new Finder();
            // Les fichiers des acteurs de l'Assemblée commencent toujours par "PA"
            $finder->files()->in($extractPath)->name('PA*.json');

            $personnaliteRepository = $this->entityManager->getRepository(PersonnalitePolitique::class);
            $compteur = 0;

            foreach ($finder as $file) {
                $jsonContent = $file->getContents();
                $data = json_decode($jsonContent, true);

                // L'objet racine s'appelle "acteur" dans cette archive
                if (!isset($data['acteur'])) {
                    continue;
                }

                $acteur = $data['acteur'];
                $etatCivil = $acteur['etatCivil']['ident'];

                // On récupère le prénom, nom de famille et UID
                $prenom = $etatCivil['prenom'] ?? '';
                $nom = $etatCivil['nom'] ?? '';
                $uid = $acteur['uid']['#text'] ?? '';

                // On vérifie que l'UID n'est pas vide avant de continuer
                if (empty($prenom) && empty($nom) || empty($uid)) {
                    continue;
                }

                // Anti-doublon
                $personnaliteExistante = $personnaliteRepository->findOneBy(['uid' => $uid]);
                if ($personnaliteExistante !== null) {
                    continue;
                }

                // Création de l'Entité
                $personnalite = new PersonnalitePolitique();
                $personnalite->setUid($uid);
                $personnalite->setNom($nom);
                $personnalite->setPrenom($prenom);

                // NOTE : Quand il faut rajouterer un champ "type" ou "fonction" (ex: senateur, depute),
                // c'est ici qu'il faudra faire : $personnalite->setFonction('depute');

                $this->entityManager->persist($personnalite);
                $compteur++;

                // Optimisation de la mémoire (indispensable pour les grosses boucles)
                if ($compteur % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            }

            $this->entityManager->flush();
            echo "Import des personnalités (Assemblée) terminé. " . $compteur . " nouvelles personnes insérées.\n";
        } else {
            echo "Erreur : Impossible d'extraire l'archive des acteurs.\n";
        }
    }
}
