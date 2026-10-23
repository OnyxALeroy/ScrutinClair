<?php

namespace App\Tests\Repository;

use App\Entity\Texte;
use App\Repository\TexteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TexteRepositoryTest extends KernelTestCase
{
    /** @var list<Texte> */
    private array $textes = [];

    protected function tearDown(): void
    {
        if ($this->textes !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);

            foreach ($this->textes as $texte) {
                $em->remove($texte);
            }
            $em->flush();

            $this->textes = [];
        }

        parent::tearDown();
    }

    public function testPrefersMostRecentNonAmendmentTextForEachDossier(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $dossierA = 'DLR-TEST-' . bin2hex(random_bytes(4));
        $dossierB = 'DLR-TEST-' . bin2hex(random_bytes(4));

        $this->createTexte($em, $dossierA, 'Texte principal ancien');
        $this->createTexte($em, $dossierA, "l'amendement n° 12 de test");
        $texteRecent = $this->createTexte($em, $dossierA, 'Texte principal récent');
        $amendementSeul = $this->createTexte($em, $dossierB, "l'amendement n° 34 de test");

        $ids = static::getContainer()->get(TexteRepository::class)
            ->idsRepresentatifsParDossierRef([$dossierA, $dossierB, $dossierA, '']);

        self::assertSame($texteRecent->getId(), $ids[$dossierA]);
        self::assertSame($amendementSeul->getId(), $ids[$dossierB]);
        self::assertArrayNotHasKey('DLR-TEST-INCONNU', $ids);
    }

    private function createTexte(EntityManagerInterface $em, string $dossierRef, string $objet): Texte
    {
        $texte = new Texte();
        $texte->setTitre('Titre ' . bin2hex(random_bytes(4)));
        $texte->setObjet($objet);
        $texte->setDossierRef($dossierRef);
        $em->persist($texte);
        $em->flush();

        $this->textes[] = $texte;

        return $texte;
    }
}
