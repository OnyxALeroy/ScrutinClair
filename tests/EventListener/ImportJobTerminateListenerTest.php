<?php

namespace App\Tests\EventListener;

use App\Entity\ImportJob;
use App\EventListener\ImportJobTerminateListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ImportJobTerminateListenerTest extends KernelTestCase
{
    /** @return iterable<string, array{int, string, int}> */
    public static function codesDeSortie(): iterable
    {
        yield 'succès' => [Command::SUCCESS, 'success', 100];
        yield 'échec' => [Command::FAILURE, 'failed', 40];
    }

    #[DataProvider('codesDeSortie')]
    public function testLaFinDeLaCommandeEstEnregistree(int $code, string $statut, int $progression): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ImportJob('app:renommer');
        $job->setStatus('running');
        $job->setProgress(40);
        $em->persist($job);
        $em->flush();

        try {
            $listener = new ImportJobTerminateListener(static::getContainer()->get(Connection::class), (string) $job->getId());
            // a nested or unrelated command must not close the job
            $listener(new ConsoleTerminateEvent(new Command('app:taguer'), new ArrayInput([]), new NullOutput(), Command::FAILURE));
            $em->refresh($job);
            self::assertSame('running', $job->getStatus());

            $listener(new ConsoleTerminateEvent(new Command('app:renommer'), new ArrayInput([]), new NullOutput(), $code));

            $em->refresh($job);
            self::assertSame($statut, $job->getStatus());
            self::assertSame($progression, $job->getProgress());
            self::assertNotNull($job->getFinishedAt());
        } finally {
            $em->remove($job);
            $em->flush();
        }
    }

    public function testUneTacheAnnuleeResteAnnulee(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ImportJob('app:renommer');
        $job->setStatus('cancelled');
        $em->persist($job);
        $em->flush();

        try {
            $listener = new ImportJobTerminateListener(static::getContainer()->get(Connection::class), (string) $job->getId());
            $listener(new ConsoleTerminateEvent(new Command('app:renommer'), new ArrayInput([]), new NullOutput(), Command::SUCCESS));

            $em->refresh($job);
            self::assertSame('cancelled', $job->getStatus());
        } finally {
            $em->remove($job);
            $em->flush();
        }
    }
}
