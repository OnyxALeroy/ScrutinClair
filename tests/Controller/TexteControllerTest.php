<?php

namespace App\Tests\Controller;

use App\Entity\Texte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TexteControllerTest extends WebTestCase
{
    private ?int $texteId = null;

    protected function tearDown(): void
    {
        if ($this->texteId !== null) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $texte = $em->find(Texte::class, $this->texteId);
            if ($texte !== null) {
                $em->remove($texte);
                $em->flush();
            }
            $this->texteId = null;
        }

        parent::tearDown();
    }

    public function testSearchEndpointReturnsMatchingTextes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $client->request('GET', '/api/textes?q=' . $token);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertContains($this->texteId, array_column($data, 'id'));
    }

    public function testShowDisplaysSelectedTexte(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $token);
        self::assertSelectorExists('a[href="https://example.test/' . $token . '"]');
    }

    public function testHomeSearchFallbackListsResults(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $client->request('GET', '/?q=' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/textes/' . $this->texteId . '"]');
    }

    public function testUnknownTexteReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/textes/2147483647');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDisplaysVulgarisationWhenSet(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $texte->setVulgarisation("Premier paragraphe $token.\n\nSecond paragraphe $token.");
        $texte->setVulgarisationStatut('ok');
        $em->flush();
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'En clair');
        self::assertSelectorTextContains('.vulgarisation p', 'Premier paragraphe ' . $token . '.');
    }

    private function createTexte(EntityManagerInterface $em, string $token): Texte
    {
        $texte = new Texte();
        $texte->setTitre('Titre de test ' . $token);
        $texte->setObjet('Objet de test ' . $token);
        $texte->setSynthese('Synthese de test ' . $token);
        $texte->setUidScrutin('TEST' . $token);
        $texte->setLien('https://example.test/' . $token);
        $em->persist($texte);
        $em->flush();

        return $texte;
    }
}
