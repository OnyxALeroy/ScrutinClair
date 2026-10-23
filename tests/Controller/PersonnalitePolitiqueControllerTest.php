<?php

namespace App\Tests\Controller;

use App\Entity\GroupePolitique;
use App\Entity\PersonnalitePolitique;
use App\Entity\Tag;
use App\Entity\Texte;
use App\Entity\Vote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonnalitePolitiqueControllerTest extends WebTestCase
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

    public function testListDisplaysPersonnalites(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(5));
        $personnalite = $this->createPersonnalite(static::getContainer()->get(EntityManagerInterface::class), $token);

        $client->request('GET', '/personnalites');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $personnalite->getNomComplet());
    }

    public function testSearchFiltersByName(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(5));
        $personnalite = $this->createPersonnalite(static::getContainer()->get(EntityManagerInterface::class), $token);

        $client->request('GET', '/personnalites?q=' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $personnalite->getNomComplet());
    }

    public function testShowDisplaysVotes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(5));
        $personnalite = $this->createPersonnalite($em, $token);
        $texte = $this->createTexte($em, $token);
        $this->createVote($em, $texte, $personnalite, 'Contre');

        $client->request('GET', '/personnalites/' . $personnalite->getUid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $personnalite->getNomComplet());
        self::assertSelectorTextContains('body', $texte->getObjet());
        self::assertSelectorTextContains('body', 'Contre');
    }

    public function testShowGroupsVotesByThemeAndShortensTitles(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(5));
        $personnalite = $this->createPersonnalite($em, $token);
        $tag = $this->createTag($em, $token);

        $texteTague = $this->createTexte($em, $token, "l'amendement n° 1839 de M. Tesson de suppression de l'article 20 du projet de loi de simplification de la vie économique (première lecture).");
        $texteTague->addTheme($tag);
        $em->flush();
        $this->createVote($em, $texteTague, $personnalite, 'Pour');

        $texteSansTag = $this->createTexte($em, $token, 'Objet sans thème ' . $token);
        $this->createVote($em, $texteSansTag, $personnalite, 'Contre');

        $crawler = $client->request('GET', '/personnalites/' . $personnalite->getUid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.theme-votes__resume', 'Thème ' . $token);
        self::assertSelectorTextContains('.theme-votes__ratio', '1 pour');
        self::assertSelectorTextContains('.theme-votes__ratio', '0 contre');

        $titres = $crawler->filter('.theme-votes__groupe .scrutin__titre')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertContains('Simplification de la vie économique (article 20, amendement 1839)', $titres);
        self::assertNotContains($texteTague->getTitreLong(), $titres);

        self::assertSelectorTextContains('.theme-votes--sans', $texteSansTag->getTitreLong());
    }

    public function testPhotoServesCachedFile(): void
    {
        $client = static::createClient();
        $uid = 'PA' . bin2hex(random_bytes(6));
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/var/photos_deputes/' . $uid . '.jpg';

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0775, true);
        }
        file_put_contents($path, str_repeat("\xFF\xD8\xFF\xE0", 200));

        try {
            $client->request('GET', '/personnalites/' . $uid . '/photo');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        } finally {
            @unlink($path);
        }
    }

    public function testUnknownPersonnaliteReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/personnalites/PA0000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHomeUnifiedSearchListsPersonnalite(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(5));
        $personnalite = $this->createPersonnalite(static::getContainer()->get(EntityManagerInterface::class), $token);

        $client->request('GET', '/?q=' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $personnalite->getNomComplet());
    }

    private function createGroupe(EntityManagerInterface $em): GroupePolitique
    {
        $groupe = new GroupePolitique();
        $groupe->setUid('PO' . bin2hex(random_bytes(5)));
        $groupe->setNom('Groupe de test');
        $groupe->setAcronyme('TG' . bin2hex(random_bytes(2)));
        $groupe->setCouleur('#4c6ef5');
        $groupe->setOrdre(50);
        $em->persist($groupe);
        $em->flush();
        $this->crees[] = $groupe;

        return $groupe;
    }

    private function createPersonnalite(EntityManagerInterface $em, string $token): PersonnalitePolitique
    {
        $groupe = $this->createGroupe($em);
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

    private function createTag(EntityManagerInterface $em, string $token): Tag
    {
        $tag = new Tag();
        $tag->setNom('Thème ' . $token);
        $tag->setSlug('theme-' . strtolower($token));
        $tag->setCouleur('#4c6ef5');
        $em->persist($tag);
        $em->flush();
        $this->crees[] = $tag;

        return $tag;
    }

    private function createTexte(EntityManagerInterface $em, string $token, ?string $objet = null): Texte
    {
        $texte = new Texte();
        $texte->setTitre('Titre ' . $token);
        $texte->setObjet($objet ?? 'Objet du scrutin ' . $token);
        $texte->setUidScrutin('TEST' . $token . bin2hex(random_bytes(3)));
        $em->persist($texte);
        $em->flush();
        $this->crees[] = $texte;

        return $texte;
    }

    private function createVote(EntityManagerInterface $em, Texte $texte, PersonnalitePolitique $personnalite, string $statut): void
    {
        $vote = new Vote();
        $vote->setStatut($statut);
        $vote->setTexte($texte);
        $vote->setPersonalite($personnalite);
        $vote->setGroupe('Groupe');
        $em->persist($vote);
        $em->flush();
        $this->crees[] = $vote;
    }
}
