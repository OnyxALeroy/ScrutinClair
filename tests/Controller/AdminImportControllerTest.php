<?php

namespace App\Tests\Controller;

use App\Entity\ImportJob;
use App\Service\ImportManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminImportControllerTest extends WebTestCase
{
    private ?int $jobId = null;

    protected function tearDown(): void
    {
        if (null !== $this->jobId) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $job = $em->find(ImportJob::class, $this->jobId);
            if (null !== $job) {
                $em->remove($job);
                $em->flush();
            }
        }

        parent::tearDown();
    }

    public function testLeTableauAfficheLesParametresReellementTransmis(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // args as stored by the former form: legacy true for "1" and a chambre the command doesn't accept
        $job = new ImportJob('app:import-senat', [
            'from-year' => '2025',
            'to-year' => '2026',
            'limit' => true,
            'force' => true,
            'skip-scrutins' => '1',
            'chambre' => 'AN',
        ]);
        $job->setStatus('success');
        $em->persist($job);
        $em->flush();
        $this->jobId = $job->getId();

        $crawler = $client->request('GET', '/admin/imports');

        self::assertResponseIsSuccessful();
        $chips = $crawler->filter('#job-'.$this->jobId.' .param-chip')->each(static fn ($n) => $n->text());
        self::assertSame(['Scrutins de 2025 à 2026', 'Au plus 1 scrutin', 'Réimport des votes déjà présents', 'Sans import des scrutins'], $chips);
        self::assertSame(
            '--limit=1 — Nombre maximum de scrutins importés.',
            $crawler->filter('#job-'.$this->jobId.' .param-chip')->eq(1)->attr('title'),
        );
    }

    public function testViderLHistoriqueSupprimeLesTachesFiniesEtLeursJournaux(): void
    {
        $client = static::createClient();
        // keep the overridden ImportManager for both requests
        $client->disableReboot();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // temporary project dir: the real var/import-logs is shared with the dev environment
        $projectDir = sys_get_temp_dir().'/import-purge-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var/import-logs', 0777, true);
        $container->set(ImportManager::class, new ImportManager($em, $projectDir, $container->get('console.command_loader')));

        $client->loginUser(new InMemoryUser('admin', null, ['ROLE_ADMIN']));

        $journal = static fn (string $nom): string => $projectDir.'/var/import-logs/import-'.$nom.'.log';
        $jobs = [];
        foreach (['success' => 'fini', 'failed' => 'echec', 'pending' => 'attente'] as $statut => $nom) {
            touch($journal($nom));
            $job = new ImportJob('app:renommer');
            $job->setStatus($statut);
            $job->setLogPath($journal($nom));
            $em->persist($job);
            $jobs[$statut] = $job;
        }
        touch($journal('orphelin'));
        $em->flush();
        $this->jobId = $jobs['pending']->getId();

        try {
            // call the purge directly (avoids browser/security interactions in this test)
            $purged = $container->get(ImportManager::class)->purgeFinished();

            $em->clear();
            self::assertNull($em->find(ImportJob::class, $jobs['success']->getId()));
            self::assertNull($em->find(ImportJob::class, $jobs['failed']->getId()));
            self::assertNotNull($em->find(ImportJob::class, $this->jobId));
            // a file belonging to no job of this database is left alone
            self::assertEqualsCanonicalizing([$journal('attente'), $journal('orphelin')], glob($projectDir.'/var/import-logs/*.log'));
        } finally {
            array_map('unlink', glob($projectDir.'/var/import-logs/*') ?: []);
            rmdir($projectDir.'/var/import-logs');
            rmdir($projectDir.'/var');
            rmdir($projectDir);
        }
    }

    public function testLaPageMontreUnGroupeDeParametresParCommandeAvecOptions(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $crawler = $client->request('GET', '/admin/imports');

        self::assertResponseIsSuccessful();
        $groupes = $crawler->filter('#params-container .params-group')->each(static fn ($n) => $n->attr('data-cmd'));
        self::assertEqualsCanonicalizing(
            ['app:import-senat', 'app:import-assemblee', 'app:import-votes', 'app:ia:traiter', 'app:taguer', 'app:vulgariser', 'app:renommer'],
            $groupes,
        );
    }
}
