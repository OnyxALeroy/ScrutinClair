<?php

namespace App\Tests\Enum;

use App\Enum\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testLeCatalogueContientVingtTroisThemes(): void
    {
        self::assertCount(23, Theme::cases());
    }

    public function testLibellesCouleursEtSlugsSontUniques(): void
    {
        $themes = Theme::cases();

        self::assertCount(\count($themes), array_unique(Theme::libelles()));
        self::assertCount(\count($themes), array_unique(array_map(static fn (Theme $t): string => $t->slug(), $themes)));

        foreach ($themes as $theme) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $theme->couleur());
            self::assertNotSame('', $theme->description());
        }
    }

    public function testLeSlugIgnoreLesAccentsEtLaPonctuation(): void
    {
        self::assertSame('finances-publiques', Theme::FinancesPubliques->slug());
        self::assertSame('economie-entreprises', Theme::Economie->slug());
        self::assertSame('securite-interieure', Theme::SecuriteInterieure->slug());
        self::assertSame('collectivites-territoriales', Theme::Collectivites->slug());
    }

    public function testFromLabelIgnoreLaCasseLesAccentsEtLaPonctuation(): void
    {
        self::assertSame(Theme::FinancesPubliques, Theme::fromLabel('finances publiques'));
        self::assertSame(Theme::Economie, Theme::fromLabel('Économie et entreprises'));
        self::assertSame(Theme::SecuriteInterieure, Theme::fromLabel('Sécurité intérieure'));
        self::assertSame(Theme::RelationsInternationales, Theme::fromLabel('RELATIONS INTERNATIONALES'));
    }

    public function testFromLabelAccepteUnLibelleAbrege(): void
    {
        self::assertSame(Theme::FinancesPubliques, Theme::fromLabel('Finances'));
        self::assertSame(Theme::Sports, Theme::fromLabel('Sport'));
    }

    public function testFromLabelIgnoreLesLibellesInconnus(): void
    {
        self::assertNull(Theme::fromLabel('Politique étrangère'));
        self::assertNull(Theme::fromLabel(''));
        self::assertNull(Theme::fromLabel('   '));
    }

    public function testLeCataloguePourModeleContientChaqueTheme(): void
    {
        $catalogue = Theme::cataloguePourModele();

        foreach (Theme::cases() as $theme) {
            self::assertStringContainsString('- ' . $theme->label() . ' :', $catalogue);
        }
    }
}
