<?php
namespace App\Service;

use App\Entity\Gouvernement;
use App\Entity\GroupePolitique;
use App\Entity\MembreGouvernement;
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

    /**
     * Importe les gouvernements, leurs ministères et leurs membres depuis le
     * jeu de données AMO20 (députés, sénateurs et ministres).
     */
    public function importGouvernements(): void
    {
        $dataDir = $this->projectDir . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $zipPath = $dataDir . '/gouvernements.zip';
        $this->assurerArchiveGouvernements($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Impossible d\'ouvrir l\'archive des gouvernements.');
        }

        $organes = [];
        $acteurs = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '.json')) {
                continue;
            }

            $raw = $zip->getFromIndex($i);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (isset($data['organe']) && is_array($data['organe'])) {
                $uid = $data['organe']['uid'] ?? null;
                if (is_string($uid) && $uid !== '') {
                    $organes[$uid] = $data['organe'];
                }
            } elseif (isset($data['acteur']) && is_array($data['acteur'])) {
                $uid = $this->uidActeur($data['acteur']);
                if ($uid !== null) {
                    $acteurs[$uid] = $data['acteur'];
                }
            }
        }

        $zip->close();

        $gouvernementRepository = $this->entityManager->getRepository(Gouvernement::class);

        /** @var array<string, Gouvernement> $gouvernements */
        $gouvernements = [];

        foreach ($organes as $organe) {
            if (($organe['codeType'] ?? null) !== 'GOUVERNEMENT') {
                continue;
            }

            $uid = $organe['uid'] ?? null;
            $dateDebut = $this->dateOuNull($organe['viMoDe']['dateDebut'] ?? null);
            if (!is_string($uid) || $uid === '' || $dateDebut === null) {
                continue;
            }

            $gouvernement = $gouvernementRepository->findOneBy(['uid' => $uid]);
            if ($gouvernement === null) {
                $gouvernement = new Gouvernement();
                $gouvernement->setUid($uid);
            }

            $gouvernement->setNom((string) ($organe['libelleAbrege'] ?? $organe['libelle'] ?? $uid));
            $gouvernement->setDateDebut($dateDebut);
            $gouvernement->setDateFin($this->dateOuNull($organe['viMoDe']['dateFin'] ?? null));

            $this->entityManager->persist($gouvernement);
            $gouvernements[$uid] = $gouvernement;
        }

        $this->entityManager->flush();

        if ($gouvernements === []) {
            echo "Aucun gouvernement trouvé dans l'archive AMO20.\n";

            return;
        }

        /** @var array<string, string> $parentParMinistere */
        $parentParMinistere = [];

        foreach ($organes as $uid => $organe) {
            if (($organe['codeType'] ?? null) !== 'MINISTERE') {
                continue;
            }

            $parent = $organe['organeParent'] ?? null;
            if (is_array($parent)) {
                $parent = $parent[0] ?? null;
            }

            if (is_string($parent) && isset($gouvernements[$parent])) {
                $parentParMinistere[$uid] = $parent;
            }
        }

        /** @var array<string, array<string, list<array{mandat: array<string, mixed>, ministere: array<string, mixed>}>>> $mandatsParGouvernement */
        $mandatsParGouvernement = [];

        foreach ($acteurs as $acteurUid => $acteur) {
            $mandats = $acteur['mandats']['mandat'] ?? [];
            if (!is_array($mandats)) {
                continue;
            }
            if (isset($mandats['typeOrgane'])) {
                $mandats = [$mandats];
            }

            foreach ($mandats as $mandat) {
                if (!is_array($mandat) || ($mandat['typeOrgane'] ?? null) !== 'MINISTERE') {
                    continue;
                }

                $qualite = (string) ($mandat['infosQualite']['codeQualite'] ?? '');
                if ($qualite === 'en mission') {
                    continue;
                }

                $ministereUid = $mandat['organes']['organeRef'] ?? null;
                if (!is_string($ministereUid)) {
                    continue;
                }

                $gouvernementUid = $parentParMinistere[$ministereUid] ?? null;
                if ($gouvernementUid === null) {
                    continue;
                }

                $mandatsParGouvernement[$gouvernementUid][$acteurUid][] = [
                    'mandat' => $mandat,
                    'ministere' => $organes[$ministereUid],
                ];
            }
        }

        $personnaliteRepository = $this->entityManager->getRepository(PersonnalitePolitique::class);

        /** @var array<string, PersonnalitePolitique> $personnalites */
        $personnalites = [];
        /** @var array<string, list<array{membre: MembreGouvernement, gouvernement: Gouvernement}>> $membresParPersonne */
        $membresParPersonne = [];

        foreach ($gouvernements as $gouvernementUid => $gouvernement) {
            $membresExistants = [];
            foreach ($gouvernement->getMembres() as $membreExistant) {
                $uid = $membreExistant->getPersonnalite()?->getUid();
                if ($uid !== null) {
                    $membresExistants[$uid] = $membreExistant;
                }
            }

            $vus = [];

            foreach ($mandatsParGouvernement[$gouvernementUid] ?? [] as $acteurUid => $mandats) {
                $acteur = $acteurs[$acteurUid] ?? null;
                if ($acteur === null) {
                    continue;
                }

                $meilleurMandat = $this->meilleurMandat($mandats);

                $personnalite = $personnalites[$acteurUid] ?? $personnaliteRepository->findOneBy(['uid' => $acteurUid]);
                if ($personnalite === null) {
                    $personnalite = new PersonnalitePolitique();
                    $personnalite->setUid($acteurUid);
                }

                $this->enrichirDepuisAmo20($personnalite, $acteur);
                $this->entityManager->persist($personnalite);
                $personnalites[$acteurUid] = $personnalite;

                $membre = $membresExistants[$acteurUid] ?? new MembreGouvernement();
                if ($membre->getId() === null) {
                    $gouvernement->addMembre($membre);
                }

                $role = (string) ($meilleurMandat['mandat']['infosQualite']['libQualite']
                    ?? $meilleurMandat['mandat']['infosQualite']['codeQualite']
                    ?? 'Membre du gouvernement');
                $ministere = (string) ($meilleurMandat['ministere']['libelleAbrege']
                    ?? $meilleurMandat['ministere']['libelle']
                    ?? '');
                $preseance = $meilleurMandat['mandat']['preseance'] ?? null;

                $membre->setPersonnalite($personnalite);
                $membre->setRole($role);
                $membre->setMinistere($ministere !== '' ? $ministere : null);
                $membre->setDateDebut($this->dateOuNull($meilleurMandat['mandat']['dateDebut'] ?? null));
                $membre->setDateFin($this->dateOuNull($meilleurMandat['mandat']['dateFin'] ?? null));
                $membre->setPreseance(is_numeric($preseance) ? (int) $preseance : null);

                $vus[$acteurUid] = true;
                $membresParPersonne[$acteurUid][] = [
                    'membre' => $membre,
                    'gouvernement' => $gouvernement,
                ];
            }

            foreach ($membresExistants as $uid => $membreExistant) {
                if (!isset($vus[$uid])) {
                    $gouvernement->removeMembre($membreExistant);
                }
            }
        }

        foreach ($membresParPersonne as $acteurUid => $mandats) {
            $personnalite = $personnalites[$acteurUid];
            $membreActuel = null;
            $dernierAncien = null;

            foreach ($mandats as $mandat) {
                if ($mandat['gouvernement']->estActuel()) {
                    $membreActuel = $mandat['membre'];
                } elseif ($dernierAncien === null || $mandat['gouvernement']->getDateDebut() > $dernierAncien['gouvernement']->getDateDebut()) {
                    $dernierAncien = $mandat;
                }
            }

            if ($membreActuel !== null) {
                $personnalite->setFonction($this->fonctionGouvernementale($membreActuel->getCategorie()));
            } elseif ($dernierAncien !== null
                && ($personnalite->getFonction() === null || str_starts_with($personnalite->getFonction(), 'Ancien '))
            ) {
                $personnalite->setFonction($this->fonctionGouvernementale($dernierAncien['membre']->getCategorie(), true));
            }
        }

        $this->entityManager->flush();

        echo 'Import des gouvernements terminé. ' . \count($gouvernements) . ' gouvernements traités.' . "\n";
    }

    private function assurerArchiveGouvernements(string $zipPath): void
    {
        if (is_file($zipPath) && filesize($zipPath) > 0) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true && $zip->numFiles > 0) {
                $zip->close();

                return;
            }
        }

        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $this->downloader->download($this->urls->acteursGouvernementsZipUrl(), $zipPath);
    }

    /**
     * @param array<string, mixed> $acteur
     */
    private function uidActeur(array $acteur): ?string
    {
        $uid = $acteur['uid'] ?? null;
        if (is_string($uid)) {
            return $uid !== '' ? $uid : null;
        }
        if (is_array($uid) && is_string($uid['#text'] ?? null)) {
            return $uid['#text'] !== '' ? $uid['#text'] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $acteur
     */
    private function enrichirDepuisAmo20(PersonnalitePolitique $personnalite, array $acteur): void
    {
        $ident = $acteur['etatCivil']['ident'] ?? [];
        $prenom = $ident['prenom'] ?? null;
        $nom = $ident['nom'] ?? null;

        if (is_string($prenom) && $prenom !== '') {
            $personnalite->setPrenom($prenom);
        }
        if (is_string($nom) && $nom !== '') {
            $personnalite->setNom($nom);
        }

        $dateNais = $acteur['etatCivil']['infoNaissance']['dateNais'] ?? null;
        $dateNaissance = $this->dateOuNull($dateNais);
        if ($dateNaissance !== null) {
            $personnalite->setDateNaissance($dateNaissance);
        }

        $profession = $acteur['profession']['libelleCourant'] ?? null;
        if (is_string($profession) && $profession !== '') {
            $personnalite->setProfession($profession);
        }
    }

    /**
     * @param list<array{mandat: array<string, mixed>, ministere: array<string, mixed>}> $mandats
     *
     * @return array{mandat: array<string, mixed>, ministere: array<string, mixed>}
     */
    private function meilleurMandat(array $mandats): array
    {
        usort($mandats, static function (array $a, array $b): int {
            $aEnCours = empty($a['mandat']['dateFin']);
            $bEnCours = empty($b['mandat']['dateFin']);

            if ($aEnCours !== $bEnCours) {
                return $aEnCours ? -1 : 1;
            }

            return strcmp(
                (string) ($b['mandat']['dateDebut'] ?? ''),
                (string) ($a['mandat']['dateDebut'] ?? ''),
            );
        });

        return $mandats[0];
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

    private function fonctionGouvernementale(string $categorie, bool $ancien = false): string
    {
        if ($ancien) {
            return match ($categorie) {
                'Premier ministre' => 'Ancien Premier ministre',
                'Ministres d’État' => 'Ancien ministre d’État',
                'Ministres délégués' => 'Ancien ministre délégué',
                'Secrétaires d’État' => 'Ancien secrétaire d’État',
                default => 'Ancien ministre',
            };
        }

        return match ($categorie) {
            'Premier ministre' => 'Premier ministre',
            'Ministres d’État' => 'Ministre d’État',
            'Ministres délégués' => 'Ministre délégué',
            'Secrétaires d’État' => 'Secrétaire d’État',
            default => 'Ministre',
        };
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
