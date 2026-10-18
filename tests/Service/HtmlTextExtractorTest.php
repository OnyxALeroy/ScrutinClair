<?php

namespace App\Tests\Service;

use App\Service\HtmlTextExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlTextExtractorTest extends TestCase
{
    public function testExtraitLeTexteEnPreservantLesParagraphes(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><style>body { color: red }</style></head>
        <body>
            <h1>Article 1</h1>
            <p>La premi&#232;re phrase.</p>
            <p>La&nbsp;seconde phrase.</p>
            <script>console.log('ignore');</script>
        </body></html>
        HTML;

        $texte = (new HtmlTextExtractor())->extract($html);

        self::assertStringContainsString('Article 1', $texte);
        self::assertStringContainsString('La première phrase.', $texte);
        self::assertStringContainsString('La seconde phrase.', $texte);
        self::assertStringNotContainsString('color: red', $texte);
        self::assertStringNotContainsString('console.log', $texte);
    }

    public function testNormaliseLesLignesVidesMultiples(): void
    {
        $texte = (new HtmlTextExtractor())->extract("<p>A</p>\n\n\n\n<p>B</p>");

        self::assertStringNotContainsString("\n\n\n", $texte);
    }
}
