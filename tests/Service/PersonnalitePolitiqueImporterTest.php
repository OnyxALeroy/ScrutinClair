<?php

namespace App\Tests\Service;

use App\Entity\Gouvernement;
use App\Entity\PersonnalitePolitique;
use App\Repository\GouvernementRepository;
use App\Repository\PersonnalitePolitiqueRepository;
use App\Service\AssembleeUrls;
use App\Service\PersonnalitePolitiqueImporter;
use App\Service\ResilientDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use ZipArchive;

final class PersonnalitePolitiqueImporterTest extends KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = sys_get_temp_dir() . '/scrutinclair-import-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/data', 0777, true);
    }

    protected function tearDown(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $gouvernement = $container->get(GouvernementRepository::class)->findOneBy(['nom' => 'TEST-IMPORT']);
        if ($gouvernement !== null) {
            $em->remove($gouvernement);
            $em->flush();
        }

        foreach (['TestImport', 'TestImportMission'] as $nom) {
            $personnalite = $container->get(PersonnalitePolitiqueRepository::class)->findOneBy(['nom' => $nom]);
            if ($personnalite !== null) {
                $em->remove($personnalite);
                $em->flush();
            }
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testImportsGovernmentMembersAndCreatesMissingPersonalities(): void
    {
        $gouvernementUid = 'GOUV' . bin2hex(random_bytes(4));
        $ministereUid = 'MIN' . bin2hex(random_bytes(4));
        $acteurUid = 'PA' . bin2hex(random_bytes(4));

        $this->createArchive($gouvernementUid, $ministereUid, $acteurUid);

        $downloader = $this->createMock(ResilientDownloader::class);
        $downloader->expects(self::never())->method('download');

        $importer = new PersonnalitePolitiqueImporter(
            $this->projectDir,
            static::getContainer()->get(EntityManagerInterface::class),
            new AssembleeUrls('https://www.assemblee-nationale.fr', 17),
            $downloader,
        );

        $importer->importGouvernements();

        $gouvernement = static::getContainer()->get(GouvernementRepository::class)
            ->findOneBy(['uid' => $gouvernementUid]);

        self::assertInstanceOf(Gouvernement::class, $gouvernement);
        self::assertSame('TEST-IMPORT', $gouvernement->getNom());
        self::assertTrue($gouvernement->estActuel());
        self::assertCount(1, $gouvernement->getMembres());

        $membre = $gouvernement->getMembres()->first();
        self::assertNotFalse($membre);
        self::assertSame('Ministre', $membre->getRole());
        self::assertSame('Ministère de test', $membre->getMinistere());
        self::assertSame('Ministres', $membre->getCategorie());

        $personnalite = $membre->getPersonnalite();
        self::assertInstanceOf(PersonnalitePolitique::class, $personnalite);
        self::assertSame($acteurUid, $personnalite->getUid());
        self::assertSame('Alice', $personnalite->getPrenom());
        self::assertSame('TestImport', $personnalite->getNom());
        self::assertSame('Ministre', $personnalite->getFonction());
    }

    private function createArchive(string $gouvernementUid, string $ministereUid, string $acteurUid): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->projectDir . '/data/gouvernements.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString("json/organe/{$gouvernementUid}.json", json_encode([
            'organe' => [
                'uid' => $gouvernementUid,
                'codeType' => 'GOUVERNEMENT',
                'libelleAbrege' => 'TEST-IMPORT',
                'viMoDe' => ['dateDebut' => '2025-01-01', 'dateFin' => null],
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->addFromString("json/organe/{$ministereUid}.json", json_encode([
            'organe' => [
                'uid' => $ministereUid,
                'codeType' => 'MINISTERE',
                'libelleAbrege' => 'Ministère de test',
                'organeParent' => $gouvernementUid,
            ],
        ], JSON_THROW_ON_ERROR));

        $zip->addFromString("json/acteur/{$acteurUid}.json", json_encode([
            'acteur' => [
                'uid' => $acteurUid,
                'etatCivil' => [
                    'ident' => ['prenom' => 'Alice', 'nom' => 'TestImport'],
                ],
                'mandats' => [
                    'mandat' => [
                        'typeOrgane' => 'MINISTERE',
                        'infosQualite' => ['codeQualite' => 'Ministre', 'libQualite' => 'Ministre'],
                        'organes' => ['organeRef' => $ministereUid],
                        'dateDebut' => '2025-01-01',
                        'dateFin' => null,
                        'preseance' => 30,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $enMissionUid = 'PA' . bin2hex(random_bytes(4));
        $zip->addFromString("json/acteur/{$enMissionUid}.json", json_encode([
            'acteur' => [
                'uid' => $enMissionUid,
                'etatCivil' => [
                    'ident' => ['prenom' => 'Bob', 'nom' => 'TestImportMission'],
                ],
                'mandats' => [
                    'mandat' => [
                        'typeOrgane' => 'MINISTERE',
                        'infosQualite' => ['codeQualite' => 'en mission', 'libQualite' => 'En mission'],
                        'organes' => ['organeRef' => $ministereUid],
                        'dateDebut' => '2025-02-01',
                        'dateFin' => null,
                        'preseance' => 30,
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
