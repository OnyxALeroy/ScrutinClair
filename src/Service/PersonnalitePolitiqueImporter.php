<?php
namespace App\Service;

use App\Entity\GroupePolitique;
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
        private EntityManagerInterface $entityManager,
        private AssembleeUrls $urls,
        private ResilientDownloader $downloader
    ) {}

    /**
     * Importe les personnalités politiques depuis l'Assemblée Nationale (Députés).
     */
    public function importDepuisAssembleeNationale(): void
    {
        $dataDir = $this->projectDir . '/data';
        $zipPath = $dataDir . '/acteurs_assemblee.zip';
        $extractPath = $dataDir . '/acteurs_assemblee_extraits';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        // 1. Téléchargement robuste (sauté si l'archive est déjà extraite)
        if (!$this->dejaExtrait($extractPath)) {
            $this->downloader->download($this->urls->acteursZipUrl(), $zipPath);

            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($extractPath);
                $zip->close();
            } else {
                echo "Erreur : Impossible d'extraire l'archive des acteurs.\n";

                return;
            }
        }

        // 2. Import des groupes politiques
        $groupeRepository = $this->entityManager->getRepository(GroupePolitique::class);
        $finderGroupes = new Finder();
        $finderGroupes->files()->in($extractPath)->name('PO*.json');

        /** @var array<string, GroupePolitique> $groupesParUid */
        $groupesParUid = [];

        foreach ($finderGroupes as $file) {
            $data = json_decode($file->getContents(), true);
            // On ne garde que les Groupes Politiques (GP)
            if (!isset($data['organe']) || $data['organe']['codeType'] !== 'GP') {
                continue;
            }

            $uid = $data['organe']['uid'];
            $nom = $data['organe']['libelle'];
            $acronyme = $data['organe']['libelleAbrev'] ?? $nom;

            $groupe = $groupeRepository->findOneBy(['uid' => $uid]);
            if (!$groupe) {
                $groupe = new GroupePolitique();
                $groupe->setUid($uid);
            }

            $groupe->setNom($nom);
            $groupe->setAcronyme($acronyme);
            $groupe->setCouleur(GroupePolitique::couleurPour($acronyme));
            $groupe->setOrdre($groupe->getPositionPolitique());

            $this->entityManager->persist($groupe);
            $groupesParUid[$uid] = $groupe;
        }
        $this->entityManager->flush();
        echo "Groupes politiques mis à jour.\n";

        // 3. Outil Symfony natif pour fouiller dans les dossiers extraits
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
            if ((empty($prenom) && empty($nom)) || empty($uid)) {
                continue;
            }

            $personnalite = $personnaliteRepository->findOneBy(['uid' => $uid]);
            if ($personnalite === null) {
                $personnalite = new PersonnalitePolitique();
                $personnalite->setUid($uid);
            }

            $personnalite->setNom($nom);
            $personnalite->setPrenom($prenom);
            $this->enrichir($personnalite, $acteur, $groupesParUid);

            $this->entityManager->persist($personnalite);
            $compteur++;

            // Optimisation de la mémoire (indispensable pour les grosses boucles)
            if ($compteur % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $groupesParUid = [];
                foreach ($groupeRepository->findAll() as $groupe) {
                    $groupesParUid[$groupe->getUid()] = $groupe;
                }
            }
        }

        $this->entityManager->flush();
        echo "Import des personnalités (Assemblée) terminé. " . $compteur . " personnes traitées.\n";
    }

    private function dejaExtrait(string $extractPath): bool
    {
        if (!is_dir($extractPath)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($extractPath)->name('PA*.json');

        return $finder->count() > 0;
    }

    /**
     * Complète une personnalité depuis son fichier acteur : naissance,
     * profession, circonscription et groupe politique d'appartenance.
     *
     * @param array<string, mixed> $acteur
     * @param array<string, GroupePolitique> $groupesParUid
     */
    private function enrichir(PersonnalitePolitique $personnalite, array $acteur, array $groupesParUid): void
    {
        $dateNais = $acteur['etatCivil']['infoNaissance']['dateNais'] ?? null;
        if (is_string($dateNais) && $dateNais !== '') {
            try {
                $personnalite->setDateNaissance(new \DateTimeImmutable($dateNais));
            } catch (\Exception) {
                // La date de naissance est facultative : on ignore une valeur invalide.
            }
        }

        $profession = $acteur['profession']['libelleCourant'] ?? null;
        if (is_string($profession) && $profession !== '') {
            $personnalite->setProfession($profession);
        }

        $personnalite->setFonction('Député');

        $mandats = $acteur['mandats']['mandat'] ?? [];
        if (!is_array($mandats)) {
            return;
        }
        if (isset($mandats['typeOrgane'])) {
            $mandats = [$mandats];
        }

        $groupeUid = null;
        $groupeDebut = null;

        foreach ($mandats as $mandat) {
            if (!is_array($mandat)) {
                continue;
            }

            $typeOrgane = $mandat['typeOrgane'] ?? null;

            if ($typeOrgane === 'GP') {
                $uid = $mandat['organes']['organeRef'] ?? null;
                $debut = $mandat['dateDebut'] ?? null;
                if (is_string($uid) && ($groupeDebut === null || (string) $debut > (string) $groupeDebut)) {
                    $groupeUid = $uid;
                    $groupeDebut = $debut;
                }
            } elseif ($typeOrgane === 'ASSEMBLEE') {
                $circonscription = $this->formaterCirconscription($mandat);
                if ($personnalite->getCirconscription() === null && $circonscription !== null) {
                    $personnalite->setCirconscription($circonscription);
                }
            }
        }

        if ($groupeUid !== null && isset($groupesParUid[$groupeUid])) {
            $personnalite->setGroupePolitique($groupesParUid[$groupeUid]);
        }
    }

    /**
     * @param array<string, mixed> $mandat
     */
    private function formaterCirconscription(array $mandat): ?string
    {
        $departement = $mandat['election']['lieu']['departement'] ?? null;
        $numCirco = $mandat['election']['lieu']['numCirco'] ?? null;
        if (!is_string($departement) || $departement === '') {
            return null;
        }

        if ($numCirco !== null && $numCirco !== '') {
            return $departement . ' (' . $numCirco . 'e circonscription)';
        }

        return $departement;
    }
}
