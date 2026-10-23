<?php

namespace App\Tests\Service;

use App\Service\AssembleeUrls;
use App\Service\ResilientDownloader;
use App\Service\TextesInitiativesIndex;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class TextesInitiativesIndexTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/scrutinclair-index-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/data', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testIndexesProposalsByAuthorAndProjectsByInitiator(): void
    {
        $this->createArchive($this->projectDir . '/data/dossiers.zip');

        $downloader = $this->createMock(ResilientDownloader::class);
        $downloader->expects(self::never())->method('download');

        $index = new TextesInitiativesIndex(
            $this->projectDir,
            new AssembleeUrls('https://www.assemblee-nationale.fr', 17),
            $downloader,
        );

        $propositions = $index->propositionsPourAuteur('PA111');

        self::assertCount(1, $propositions);
        self::assertSame('PIONANR5L17B0001', $propositions[0]['uid']);
        self::assertSame('Proposition de loi test', $propositions[0]['titre']);
        self::assertSame('2025-01-10', $propositions[0]['dateDepot']);
        self::assertSame(
            'https://www.assemblee-nationale.fr/dyn/opendata/PIONANR5L17B0001.html',
            $propositions[0]['lien'],
        );
        self::assertSame('auteur', $propositions[0]['qualite']);
        self::assertSame(1, $index->compterPropositionsPourAuteur('PA111'));

        $cosignatures = $index->propositionsPourAuteur('PA444');

        self::assertCount(1, $cosignatures);
        self::assertSame('PIONANR5L17B0001', $cosignatures[0]['uid']);
        self::assertSame('cosignataire', $cosignatures[0]['qualite']);
        self::assertSame([], $index->propositionsPourAuteur('PA555'));

        foreach (['PA222', 'PA333'] as $initiateur) {
            $projets = $index->projetsPourAuteur($initiateur);

            self::assertCount(1, $projets);
            self::assertSame('PRJLANR5L17B0002', $projets[0]['uid']);
            self::assertSame('Projet de loi test', $projets[0]['titre']);
            self::assertSame(1, $index->compterProjetsPourAuteur($initiateur));
        }

        self::assertSame([], $index->projetsPourAuteur('PA111'));
        self::assertSame(0, $index->compterProjetsPourAuteur('PA111'));
    }

    public function testDownloadsArchiveWhenLocalFileIsMissing(): void
    {
        $archive = $this->projectDir . '/fixture.zip';
        $this->createArchive($archive);

        $downloader = $this->createMock(ResilientDownloader::class);
        $downloader->expects(self::once())
            ->method('download')
            ->willReturnCallback(static function (string $url, string $destination) use ($archive): void {
                self::assertStringContainsString('/loi/dossiers_legislatifs/', $url);
                copy($archive, $destination);
            });

        $index = new TextesInitiativesIndex(
            $this->projectDir,
            new AssembleeUrls('https://www.assemblee-nationale.fr', 17),
            $downloader,
        );

        self::assertCount(1, $index->propositionsPourAuteur('PA111'));
    }

    private function createArchive(string $path): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('json/document/PIONANR5L17B0001.json', json_encode([
            'document' => [
                'uid' => 'PIONANR5L17B0001',
                'legislature' => '17',
                'dossierRef' => 'DLR5L17N1',
                'titres' => ['titrePrincipalCourt' => 'Proposition de loi test'],
                'cycleDeVie' => ['chrono' => ['dateDepot' => '2025-01-10T00:00:00.000+01:00']],
                'provenance' => 'Texte Déposé',
                'auteurs' => ['auteur' => ['acteur' => ['acteurRef' => 'PA111']]],
                'coSignataires' => [
                    'coSignataire' => [
                        ['acteur' => ['acteurRef' => 'PA111'], 'dateRetraitCosignature' => null],
                        ['acteur' => ['acteurRef' => 'PA444'], 'dateRetraitCosignature' => null],
                        ['acteur' => ['acteurRef' => 'PA555'], 'dateRetraitCosignature' => '2025-04-01+02:00'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->addFromString('json/document/PIONANR5L17B0009.json', json_encode([
            'document' => [
                'uid' => 'PIONANR5L17B0009',
                'legislature' => '17',
                'dossierRef' => 'DLR5L17N1',
                'titres' => ['titrePrincipalCourt' => 'Proposition de loi test (version ultérieure)'],
                'cycleDeVie' => ['chrono' => ['dateDepot' => '2025-03-01T00:00:00.000+01:00']],
                'provenance' => 'Texte Déposé',
                'auteurs' => ['auteur' => ['acteur' => ['acteurRef' => 'PA111']]],
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->addFromString('json/document/PRJLANR5L17B0002.json', json_encode([
            'document' => [
                'uid' => 'PRJLANR5L17B0002',
                'legislature' => '17',
                'dossierRef' => 'DLR5L17N2',
                'titres' => ['titrePrincipalCourt' => 'Projet de loi test'],
                'cycleDeVie' => ['chrono' => ['dateDepot' => '2025-02-20T00:00:00.000+01:00']],
                'provenance' => 'Texte Déposé',
                'classification' => ['famille' => ['classe' => ['code' => 'PRJLOI']]],
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->addFromString('json/dossierParlementaire/DLR5L17N2.json', json_encode([
            'dossierParlementaire' => [
                'uid' => 'DLR5L17N2',
                'legislature' => '17',
                'initiateur' => [
                    'acteurs' => [
                        'acteur' => [
                            ['acteurRef' => 'PA222'],
                            ['acteurRef' => 'PA333'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->close();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
