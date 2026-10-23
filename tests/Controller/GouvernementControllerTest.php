<?php

namespace App\Tests\Controller;

use App\Entity\Gouvernement;
use App\Entity\MembreGouvernement;
use App\Entity\PersonnalitePolitique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GouvernementControllerTest extends WebTestCase
{
    /** @var list<Gouvernement> */
    private array $gouvernements = [];

    /** @var list<PersonnalitePolitique> */
    private array $personnalites = [];

    protected function tearDown(): void
    {
        if ($this->gouvernements !== [] || $this->personnalites !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);

            foreach ($this->gouvernements as $gouvernement) {
                $em->remove($gouvernement);
            }
            $em->flush();

            foreach ($this->personnalites as $personnalite) {
                $em->remove($personnalite);
            }
            $em->flush();
        }

        $this->gouvernements = [];
        $this->personnalites = [];

        parent::tearDown();
    }

    public function testIndexOrdersGovernmentsFromMostRecentToOldest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $ancien = $this->createGouvernement($em, 'ANCIEN', '2024-01-10', '2024-09-05');
        $recent = $this->createGouvernement($em, 'RECENT', '2025-10-11', null);

        $crawler = $client->request('GET', '/gouvernements');

        self::assertResponseIsSuccessful();

        $noms = $crawler->filter('.gouvernement-card__nom')->each(
            static fn ($node): string => trim($node->text()),
        );

        self::assertSame([$recent->getNomAffiche(), $ancien->getNomAffiche()], $noms);
        self::assertSelectorTextContains('body', 'En exercice');
    }

    public function testShowDisplaysMembersAndLinksToTheirPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $gouvernement = $this->createGouvernement($em, 'TEST', '2025-01-01', null);
        $premierMinistre = $this->createPersonnalite($em, 'Première', 'Ministre');
        $ministre = $this->createPersonnalite($em, 'Jean', 'Ministre');

        $this->createMembre($em, $gouvernement, $premierMinistre, 'Premier ministre', 'Premier ministre', 1);
        $this->createMembre($em, $gouvernement, $ministre, 'Ministre', 'Intérieur', 30);

        $client->request('GET', '/gouvernements/' . $gouvernement->getUid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $gouvernement->getNomAffiche());
        self::assertSelectorTextContains('body', $premierMinistre->getNomComplet());
        self::assertSelectorTextContains('body', $ministre->getNomComplet());
        self::assertSelectorTextContains('body', 'Intérieur');
        self::assertSelectorExists('a[href="/personnalites/' . $ministre->getUid() . '"]');
    }

    public function testUnknownGovernmentReturnsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/gouvernements/PO000000');

        self::assertResponseStatusCodeSame(404);
    }

    private function createGouvernement(
        EntityManagerInterface $em,
        string $nom,
        string $dateDebut,
        ?string $dateFin,
    ): Gouvernement {
        $gouvernement = new Gouvernement();
        $gouvernement->setUid('PO' . bin2hex(random_bytes(6)));
        $gouvernement->setNom($nom);
        $gouvernement->setDateDebut(new \DateTimeImmutable($dateDebut));
        $gouvernement->setDateFin($dateFin !== null ? new \DateTimeImmutable($dateFin) : null);
        $em->persist($gouvernement);
        $em->flush();

        $this->gouvernements[] = $gouvernement;

        return $gouvernement;
    }

    private function createPersonnalite(EntityManagerInterface $em, string $prenom, string $nom): PersonnalitePolitique
    {
        $personnalite = new PersonnalitePolitique();
        $personnalite->setUid('PA' . bin2hex(random_bytes(6)));
        $personnalite->setPrenom($prenom);
        $personnalite->setNom($nom . ' ' . bin2hex(random_bytes(3)));
        $em->persist($personnalite);
        $em->flush();

        $this->personnalites[] = $personnalite;

        return $personnalite;
    }

    private function createMembre(
        EntityManagerInterface $em,
        Gouvernement $gouvernement,
        PersonnalitePolitique $personnalite,
        string $role,
        string $ministere,
        int $preseance,
    ): MembreGouvernement {
        $membre = new MembreGouvernement();
        $membre->setPersonnalite($personnalite);
        $membre->setRole($role);
        $membre->setMinistere($ministere);
        $membre->setPreseance($preseance);
        $membre->setDateDebut($gouvernement->getDateDebut());
        $gouvernement->addMembre($membre);
        $em->persist($membre);
        $em->flush();

        return $membre;
    }
}
