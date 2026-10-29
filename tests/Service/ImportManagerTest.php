<?php

namespace App\Tests\Service;

use App\Entity\ImportJob;
use App\Service\ImportManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\StringInput;

final class ImportManagerTest extends KernelTestCase
{
    /**
     * Arguments as posted by the /admin/imports form for each command.
     *
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function commandesAdmin(): iterable
    {
        yield 'senat' => ['app:import-senat', ['from-year' => '2020', 'to-year' => '2021', 'limit' => '5', 'force' => true, 'skip-personnalites' => true], ['--from-year=2020', '--limit=5', '--force', '--skip-personnalites', '--job-id=42']];
        yield 'assemblee' => ['app:import-assemblee', ['from-year' => '2020', 'limit' => '5', 'force' => true], ['--from-year=2020', '--limit=5', '--force', '--job-id=42']];
        yield 'personnalites' => ['app:import-personnalites', [], []];
        yield 'votes' => ['app:import-votes', ['limit' => '3', 'uid' => 'VTANR5L17V1', 'force' => true], ['--limit=3', '--uid=VTANR5L17V1', '--force']];
        yield 'backfill' => ['app:backfill-textes-an', [], []];
        yield 'ia traiter' => ['app:ia:traiter', ['limit' => '2', 'shard' => '0/2', 'skip-tags' => true, 'chambre' => 'SENAT'], ['--limit=2', '--shard=0/2', '--skip-tags', '--chambre=SENAT']];
        yield 'taguer' => ['app:taguer', ['limit' => '2', 'force' => true, 'chambre' => 'all'], ['--limit=2', '--force', '--chambre=all']];
        yield 'vulgariser' => ['app:vulgariser', ['type' => 'dossier', 'dry-run' => true, 'chambre' => 'AN'], ['--type=dossier', '--dry-run', '--chambre=AN']];
        yield 'renommer' => ['app:renommer', ['uid' => 'X', 'force' => true, 'chambre' => 'AN'], ['--uid=X', '--force', '--chambre=AN']];
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string>         $attendus
     */
    #[DataProvider('commandesAdmin')]
    public function testLaLigneDeCommandeEstAccepteeParLaCommande(string $nom, array $args, array $attendus): void
    {
        self::bootKernel();
        $ligne = $this->construire($nom, $args + ['inconnu' => 'x']);

        $prefixe = "'php' '-d' 'memory_limit=1G' 'bin/console' '" . $nom . "'";
        self::assertStringStartsWith($prefixe, $ligne);
        foreach ($attendus as $attendu) {
            self::assertStringContainsString("'" . $attendu . "'", $ligne);
        }
        self::assertStringNotContainsString('inconnu', $ligne);

        if (!\in_array('--job-id=42', $attendus, true)) {
            self::assertStringNotContainsString('job-id', $ligne);
        }

        // binding throws if an option is unknown or given a value it does not accept
        $commande = (new Application(self::$kernel))->find($nom);
        $input = new StringInput(substr($ligne, \strlen($prefixe)));
        $input->bind($commande->getDefinition());
        $input->validate();
        $this->addToAssertionCount(1);
    }

    public function testLesOptionsEffectivesIgnorentCeQueLaCommandeNeConnaitPas(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(ImportManager::class);

        self::assertSame(
            ['limit' => '1', 'force' => true],
            $manager->effectiveOptions('app:import-votes', ['limit' => true, 'force' => '1', 'chambre' => 'AN', 'uid' => '', 'job-id' => '3']),
        );
        self::assertSame([], $manager->effectiveOptions('app:import-personnalites', ['from-year' => '2006', 'chambre' => 'AN']));
    }

    public function testLaProgressionEstLueDansLaBarreDuJournal(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(ImportManager::class);
        $journal = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($journal, " 0/40 [░░░░]   0%\n 3/40 [▓░░░]   7%\nautre sortie\n 17/40 [▓▓░░]  42%\n");

        try {
            self::assertSame(42, $manager->progressFromLog($journal));
            file_put_contents($journal, "pas de barre\n");
            self::assertNull($manager->progressFromLog($journal));
        } finally {
            unlink($journal);
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function finsDeJournal(): iterable
    {
        yield 'succès' => ["…\n [OK] Rien à faire.\n", 'success', 100];
        yield 'erreur' => [" [OK] étape 1\n [ERROR] Dossier introuvable.\n", 'failed', 30];
        yield 'exception' => [" [OK] étape 1\nIn Foo.php line 12:\n  boom\n", 'failed', 30];
        yield 'vide' => ['', 'failed', 30];
    }

    #[DataProvider('finsDeJournal')]
    public function testUneTacheDontLeProcessusAEteInterrompuEstClose(string $journal, string $statut, int $progression): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $manager = static::getContainer()->get(ImportManager::class);
        $chemin = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($chemin, $journal);

        $job = new ImportJob('app:renommer');
        $job->setStatus('running');
        $job->setProgress(30);
        $job->setLogPath($chemin);
        $em->persist($job);
        $em->flush();

        try {
            // no pid: the process is gone
            self::assertTrue($manager->reconcile($job));
            self::assertSame($statut, $job->getStatus());
            self::assertSame($progression, $job->getProgress());
            self::assertFalse($manager->reconcile($job));
        } finally {
            $em->remove($job);
            $em->flush();
            unlink($chemin);
        }
    }

    public function testRefuseUneCommandeInconnue(): void
    {
        self::bootKernel();

        $this->expectException(\InvalidArgumentException::class);
        $this->construire('cache:clear; rm -rf /', []);
    }

    /** @param array<string, mixed> $args */
    private function construire(string $nom, array $args): string
    {
        $job = new ImportJob($nom, $args);
        (new \ReflectionProperty(ImportJob::class, 'id'))->setValue($job, 42);

        $manager = static::getContainer()->get(ImportManager::class);

        return (new \ReflectionMethod(ImportManager::class, 'buildCommand'))->invoke($manager, $job);
    }
}
