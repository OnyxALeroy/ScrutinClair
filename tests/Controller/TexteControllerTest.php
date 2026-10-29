<?php

namespace App\Tests\Controller;

use App\Entity\PersonnalitePolitique;
use App\Entity\Tag;
use App\Entity\Texte;
use App\Entity\Vote;
use App\Enum\Chambre;
use App\Service\AiAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TexteControllerTest extends WebTestCase
{
    private ?int $texteId = null;
    private ?int $tagId = null;
    private ?int $personnaliteId = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if ($this->texteId !== null) {
            $texte = $em->find(Texte::class, $this->texteId);
            if ($texte !== null) {
                $em->remove($texte);
                $em->flush();
            }
            $this->texteId = null;
        }

        if ($this->tagId !== null) {
            $tag = $em->find(Tag::class, $this->tagId);
            if ($tag !== null) {
                $em->remove($tag);
                $em->flush();
            }
            $this->tagId = null;
        }

        if ($this->personnaliteId !== null) {
            $personnalite = $em->find(PersonnalitePolitique::class, $this->personnaliteId);
            if ($personnalite !== null) {
                $em->remove($personnalite);
                $em->flush();
            }
            $this->personnaliteId = null;
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
        $texte->setVulgarisation(
            "### Première partie\n\n"
            ."Un paragraphe avec du **gras $token**.\n\n"
            ."- un premier point $token\n"
            ."- un second point\n\n"
            ."<script>alert('xss')</script>",
        );
        $texte->setVulgarisationStatut('ok');
        $em->flush();
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'En clair');
        self::assertSelectorTextContains('.vulgarisation__body h3', 'Première partie');
        self::assertSelectorTextContains('.vulgarisation__body p', 'Un paragraphe avec du gras ' . $token . '.');
        self::assertSelectorExists('.vulgarisation__body strong');
        self::assertSelectorTextContains('.vulgarisation__body li', 'un premier point ' . $token);
        self::assertSelectorNotExists('.vulgarisation__body script');
    }

    public function testShowDisplaysPendingNoticeWhenAiIsEnabledAndVulgarisationIsMissing(): void
    {
        $client = static::createClient();
        static::getContainer()->set(AiAvailability::class, new AiAvailability('1'));
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.vulgarisation');
        self::assertSelectorTextContains('.vulgarisation__placeholder', 'pas encore été vulgarisé');
        self::assertSelectorNotExists('.vulgarisation__body');
    }

    public function testShowDisplaysUnavailableNoticeWhenAiIsDisabled(): void
    {
        $client = static::createClient();
        static::getContainer()->set(AiAvailability::class, new AiAvailability('0'));
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.vulgarisation');
        self::assertSelectorTextContains('.vulgarisation__placeholder', 'désactivée');
        self::assertSelectorTextContains('.vulgarisation__placeholder', 'aucun rapport ne peut être généré');
        self::assertSelectorNotExists('.vulgarisation__body');
    }

    public function testShowDisplaysExistingVulgarisationWithDisabledNoticeWhenAiIsDisabled(): void
    {
        $client = static::createClient();
        static::getContainer()->set(AiAvailability::class, new AiAvailability('0'));
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $texte->setVulgarisation('Un résumé déjà généré ' . $token . '.');
        $texte->setVulgarisationStatut('ok');
        $em->flush();
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vulgarisation__body', 'Un résumé déjà généré ' . $token . '.');
        self::assertSelectorTextContains('.ia-note', 'désactivée sur cette instance');
    }

    public function testShowDisplaysThemeChips(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $tag = new Tag();
        $tag->setNom('Finances publiques');
        $tag->setSlug('finances-publiques');
        $tag->setCouleur('#4f46e5');
        $em->persist($tag);
        $texte->addTheme($tag);
        $em->flush();
        $this->tagId = $tag->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.theme-chip');
        self::assertSelectorTextContains('.theme-chip', 'Finances publiques');
        self::assertSelectorExists('a.theme-chip[href="/themes/finances-publiques"]');
    }

    public function testSearchEndpointReturnsTheThemes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $tag = new Tag();
        $tag->setNom('Écologie');
        $tag->setSlug('ecologie');
        $tag->setCouleur('#16a34a');
        $em->persist($tag);
        $texte->addTheme($tag);
        $em->flush();
        $this->tagId = $tag->getId();

        $client->request('GET', '/api/textes?q=' . $token);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        $trouve = null;
        foreach ($data as $item) {
            if ($item['id'] === $this->texteId) {
                $trouve = $item;
            }
        }

        self::assertIsArray($trouve);
        self::assertArrayHasKey('themes', $trouve);
        self::assertSame('Écologie', $trouve['themes'][0]['label']);
        self::assertSame('ecologie', $trouve['themes'][0]['slug']);
    }

    public function testShowLinksVotersToTheirPersonalityPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $this->texteId = $texte->getId();

        $personnalite = new PersonnalitePolitique();
        $personnalite->setUid('PA' . $token);
        $personnalite->setPrenom('Marie');
        $personnalite->setNom('Test ' . $token);
        $em->persist($personnalite);
        $em->flush();
        $this->personnaliteId = $personnalite->getId();

        $vote = new Vote();
        $vote->setStatut('Pour');
        $vote->setPersonalite($personnalite);
        $texte->addVote($vote);
        $em->persist($vote);
        $em->flush();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.vote__nom[href="/personnalites/' . $personnalite->getUid() . '"]');
        self::assertSelectorTextContains('.vote__nom', 'Marie Test ' . $token);
    }

    public function testSenateTexteDisplaysChamberAndSenators(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexte($em, $token);
        $texte->setChambre(Chambre::Senat);
        $texte->setLien('https://www.senat.fr/scrutin-public/2024/scr2024-120.html');
        $em->flush();
        $this->texteId = $texte->getId();

        $client->request('GET', '/textes/' . $this->texteId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge--chambre', 'Sénat');
        self::assertSelectorTextContains('body', 'Votes des sénateurs');
        self::assertSelectorTextContains('.source-link', 'Consulter le scrutin au Sénat');
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
