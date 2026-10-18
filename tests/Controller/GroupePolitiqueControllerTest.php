<?php

namespace App\Tests\Controller;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Entity\Texte;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GroupePolitiqueControllerTest extends WebTestCase
{
    /** @var list<object> */
    private array $crees = [];

    protected function tearDown(): void
    {
        if ($this->crees !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach (array_reverse($this->crees) as $entite) {
                $em->remove($entite);
            }
            $em->flush();
            $this->crees = [];
        }

        parent::tearDown();
    }

    public function testListDisplaysGroupes(): void
    {
        $client = static::createClient();
        $groupe = $this->createGroupe(static::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/partis');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $groupe->getAcronyme());
    }

    public function testShowDisplaysVotesAndMembers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(5));
        $groupe = $this->createGroupe($em, $token);
        $personnalite = $this->createPersonnalite($em, $groupe, $token);
        $texte = $this->createTexte($em, $token);
        $this->createVote($em, $texte, $personnalite, $groupe, 'Pour');

        $client->request('GET', '/partis/' . $groupe->getUid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', (string) $groupe->getNom());
        self::assertSelectorTextContains('body', $texte->getObjet());
        self::assertSelectorTextContains('body', $personnalite->getNomComplet());
    }

    public function testUnknownGroupeReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/partis/PO0000000');

        self::assertResponseStatusCodeSame(404);
    }

    private function createGroupe(EntityManagerInterface $em, ?string $token = null): GroupePolitique
    {
        $token ??= bin2hex(random_bytes(5));
        $groupe = new GroupePolitique();
        $groupe->setUid('PO' . $token);
        $groupe->setNom('Groupe de test ' . $token);
        $groupe->setAcronyme('TST' . substr($token, 0, 3));
        $groupe->setCouleur('#4c6ef5');
        $groupe->setOrdre(50);
        $em->persist($groupe);
        $em->flush();
        $this->crees[] = $groupe;

        return $groupe;
    }

    private function createPersonnalite(EntityManagerInterface $em, GroupePolitique $groupe, string $token): PersonnalitePolitique
    {
        $personnalite = new PersonnalitePolitique();
        $personnalite->setUid('PA' . $token);
        $personnalite->setPrenom('Test');
        $personnalite->setNom('Député ' . $token);
        $personnalite->setGroupePolitique($groupe);
        $em->persist($personnalite);
        $em->flush();
        $this->crees[] = $personnalite;

        return $personnalite;
    }

    private function createTexte(EntityManagerInterface $em, string $token): Texte
    {
        $texte = new Texte();
        $texte->setTitre('Titre ' . $token);
        $texte->setObjet('Objet du scrutin ' . $token);
        $texte->setUidScrutin('TEST' . $token);
        $em->persist($texte);
        $em->flush();
        $this->crees[] = $texte;

        return $texte;
    }

    private function createVote(EntityManagerInterface $em, Texte $texte, PersonnalitePolitique $personnalite, GroupePolitique $groupe, string $statut): void
    {
        $vote = new Vote();
        $vote->setStatut($statut);
        $vote->setTexte($texte);
        $vote->setPersonalite($personnalite);
        $vote->setGroupe('Groupe');
        $vote->setGroupePolitique($groupe);
        $em->persist($vote);
        $em->flush();
        $this->crees[] = $vote;
    }
}
