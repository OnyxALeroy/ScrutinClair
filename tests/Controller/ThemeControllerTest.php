<?php

namespace App\Tests\Controller;

use App\Entity\Tag;
use App\Entity\Texte;
use App\Enum\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThemeControllerTest extends WebTestCase
{
    private ?int $texteId = null;
    private ?int $tagId = null;

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

        parent::tearDown();
    }

    public function testIndexListsTheCatalogue(): void
    {
        $client = static::createClient();
        $client->request('GET', '/themes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.theme-card');
        self::assertSelectorTextContains('.themes', 'Finances publiques');
        self::assertSelectorTextContains('.themes', 'Écologie');
    }

    public function testShowListsTheTextesDuTheme(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(6));
        $texte = $this->createTexteTheme($em, $token, Theme::FinancesPubliques);

        $client->request('GET', '/themes/finances-publiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.detail-head', 'Finances publiques');
        self::assertSelectorExists('a[href="/textes/' . $texte->getId() . '"]');
    }

    public function testUnknownThemeReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/themes/theme-inconnu');

        self::assertResponseStatusCodeSame(404);
    }

    private function createTexteTheme(EntityManagerInterface $em, string $token, Theme $theme): Texte
    {
        $texte = new Texte();
        $texte->setTitre('Titre de test ' . $token);
        $texte->setObjet('Objet de test ' . $token);
        $texte->setUidScrutin('THEME' . $token);
        $em->persist($texte);

        $tag = $em->getRepository(Tag::class)->findOneBy(['nom' => $theme->label()]);
        $cree = false;
        if ($tag === null) {
            $tag = new Tag();
            $tag->setNom($theme->label());
            $tag->setSlug($theme->slug());
            $tag->setCouleur($theme->couleur());
            $em->persist($tag);
            $cree = true;
        }

        $texte->addTheme($tag);
        $em->flush();

        if ($cree) {
            $this->tagId = $tag->getId();
        }

        $this->texteId = $texte->getId();

        return $texte;
    }
}
