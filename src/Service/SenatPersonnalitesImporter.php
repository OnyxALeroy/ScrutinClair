<?php

namespace App\Service;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Enum\Chambre;
use Doctrine\ORM\EntityManagerInterface;

final class SenatPersonnalitesImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SenatDataProvider $donnees,
        private readonly PersonnaliteMatcher $matcher,
    ) {
    }

    /**
     * @return array{senateurs: int, nouveaux: int, groupes: int}
     */
    public function importer(?callable $onProgress = null): array
    {
        $groupes = $this->importerGroupes();
        $repository = $this->entityManager->getRepository(PersonnalitePolitique::class);

        $parUidSenat = [];
        $parCle = [];
        $clesAmbigues = [];
        foreach ($repository->findAll() as $personnalite) {
            if (null !== $personnalite->getUidSenat()) {
                $parUidSenat[$personnalite->getUidSenat()] = $personnalite;
            }

            $cle = $this->matcher->clePersonnalite($personnalite);
            if ('||' === $cle) {
                continue;
            }
            if (isset($parCle[$cle])) {
                $clesAmbigues[$cle] = true;
            } else {
                $parCle[$cle] = $personnalite;
            }
        }
        foreach (array_keys($clesAmbigues) as $cle) {
            unset($parCle[$cle]);
        }

        $nouveaux = 0;
        $senateurs = $this->donnees->senateurs();
        $total = count($senateurs) ?: 1;
        $processed = 0;
        foreach ($senateurs as $index => $senateur) {
            $matricule = trim((string) ($senateur['senmat'] ?? ''));
            if ('' === $matricule) {
                continue;
            }

            $cle = $this->matcher->cleSenateur($senateur);
            $personnalite = $parUidSenat[$matricule] ?? $parCle[$cle] ?? null;

            if (null === $personnalite) {
                $personnalite = new PersonnalitePolitique();
                $personnalite->setUid('SEN'.strtoupper($matricule));
                $this->entityManager->persist($personnalite);
                ++$nouveaux;
            } elseif (null !== $personnalite->getUidSenat() && $personnalite->getUidSenat() !== $matricule) {
                continue;
            }

            $personnalite->setUidSenat($matricule);
            if (null === $personnalite->getNom() || '' === $personnalite->getNom()) {
                $personnalite->setNom((string) ($senateur['sennomuse'] ?? $matricule));
            }
            if (null === $personnalite->getPrenom() || '' === $personnalite->getPrenom()) {
                $personnalite->setPrenom((string) ($senateur['senprenomuse'] ?? ''));
            }
            if (null === $personnalite->getDateNaissance()) {
                $personnalite->setDateNaissance(SenatDataProvider::date($senateur['sendatnai'] ?? null));
            }
            if (null === $personnalite->getProfession() && ($senateur['sendespro'] ?? null) !== null) {
                $personnalite->setProfession($senateur['sendespro']);
            }
            if (null === $personnalite->getCirconscriptionSenat() && ($senateur['sencircou'] ?? null) !== null) {
                $personnalite->setCirconscriptionSenat($senateur['sencircou']);
            }

            $codeGroupe = $senateur['sengrppolcodcou'] ?? null;
            $groupe = null !== $codeGroupe ? ($groupes[$codeGroupe] ?? null) : null;
            if (null === $groupe) {
                $codeGroupe = $this->donnees->dernierGroupe($matricule);
                $groupe = null !== $codeGroupe ? ($groupes[$codeGroupe] ?? null) : null;
            }
            if (null !== $groupe) {
                $personnalite->setGroupePolitiqueSenat($groupe);
            }

            if (null === $personnalite->getPhotoUrl()) {
                $photoUrl = $this->donnees->photoUrl($senateur);
                if (null !== $photoUrl) {
                    $personnalite->setPhotoUrl($photoUrl);
                    $personnalite->setPhotoCredit('Sénat');
                }
            }
            if (null === $personnalite->getFonction()) {
                $personnalite->setFonction(($senateur['etasencod'] ?? null) === 'ACTIF' ? 'Sénateur' : 'Ancien sénateur');
            }

            $parUidSenat[$matricule] = $personnalite;
            if ('||' !== $cle) {
                $parCle[$cle] = $personnalite;
            }

            if (($index + 1) % 100 === 0) {
                $this->entityManager->flush();
                if (null !== $onProgress) {
                    $processed = $index + 1;
                    $pct = (int) floor(($processed / $total) * 100);
                    $onProgress(min(100, $pct));
                }
            }
        }

        $this->entityManager->flush();

        return [
            'senateurs' => \count($this->donnees->senateurs()),
            'nouveaux' => $nouveaux,
            'groupes' => \count($groupes),
        ];
    }

    /**
     * @return array<string, GroupePolitique>
     */
    private function importerGroupes(): array
    {
        $repository = $this->entityManager->getRepository(GroupePolitique::class);
        $resultat = [];

        foreach ($this->donnees->groupes() as $code => $donnees) {
            $uid = 'SEN'.strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
            $groupe = $repository->findOneBy(['uid' => $uid]);
            if (null === $groupe) {
                $groupe = new GroupePolitique();
                $groupe->setUid($uid);
                $this->entityManager->persist($groupe);
            }

            $groupe->setChambre(Chambre::Senat);
            $groupe->setNom((string) ($donnees['grppollibcou'] ?? $donnees['grppolliccou'] ?? $code));
            $groupe->setAcronyme(substr((string) $code, 0, 50));
            $groupe->setCouleur(GroupePolitique::couleurPourSenat($code));
            $groupe->setOrdre($groupe->getPositionPolitique());
            $resultat[$code] = $groupe;
        }

        $this->entityManager->flush();

        return $resultat;
    }
}
