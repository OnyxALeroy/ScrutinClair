<?php

namespace App\MessageHandler;

use App\Entity\ImportJob;
use App\Message\ImportJobMessage;
use App\Service\ImportManager;
use App\Service\SenatPersonnalitesImporter;
use App\Service\SenatScrutinsImporter;
use App\Command\IaTraiterCommand;
use App\Command\VulgariserCommand;
use App\Command\TaguerCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Exception\JobCancelledException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ImportJobHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportManager $manager,
        private readonly ?SenatPersonnalitesImporter $senatPersonnalites = null,
        private readonly ?SenatScrutinsImporter $senatScrutins = null,
        private readonly ?\App\Service\AssembleeNationaleImporter $assembleeImporter = null,
        private readonly ?IaTraiterCommand $iaTraiter = null,
        private readonly ?VulgariserCommand $vulgariser = null,
        private readonly ?TaguerCommand $taguer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ImportJobMessage $message): void
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $job = $repo->find($message->jobId);
        if (!$job instanceof ImportJob) {
            return;
        }

        // prepare log file
        $logDir = $this->manager->getProjectDir() . '/var/import-logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logPath = sprintf('%s/import-%s-%s.log', $logDir, (new \DateTime())->format('Ymd-His'), bin2hex(random_bytes(4)));
        $job->setLogPath($logPath);
        $job->setStatus('running');
        $job->setStartedAt(new \DateTime());
        $this->em->persist($job);
        $this->em->flush();

        $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] START job {$job->getId()} {$job->getCommand()}\n");
        $job->setProgress(1);
        $this->em->flush();

        // helper to check for cancellation requests and abort cooperatively
        $checkCancel = function() use ($job, $logPath) {
            $repo = $this->em->getRepository(ImportJob::class);
            $fresh = $repo->find($job->getId());
            if ($fresh && $fresh->isCancelRequested()) {
                $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] CANCEL requested for job {$job->getId()}\n");
                throw new JobCancelledException('cancelled');
            }
        };

        try {
            $cmd = $job->getCommand();
            $args = $job->getArgs();
            $raw = is_array($args) && isset($args['raw']) ? (string) $args['raw'] : '';

            if ($cmd === 'app:import-senat' && $this->senatPersonnalites !== null && $this->senatScrutins !== null) {
                // parse args: prefer structured args when available, otherwise parse raw CLI-like string
                $parsed = [];
                if (is_array($args) && count($args) > 0) {
                    foreach ($args as $k => $v) {
                        $parsed[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                    }
                } else {
                    $parsed = $this->parseRawArgs($raw);
                }
                $fromYear = isset($parsed['from-year']) ? (int) $parsed['from-year'] : 2006;
                $toYear = isset($parsed['to-year']) ? (int) $parsed['to-year'] : (int) date('Y');
                $limit = isset($parsed['limit']) ? (int) $parsed['limit'] : null;
                $force = isset($parsed['force']);
                $skipPersonnalites = isset($parsed['skip-personnalites']);
                $skipScrutins = isset($parsed['skip-scrutins']);

                if (!$skipPersonnalites) {
                    $res = $this->senatPersonnalites->importer(function(int $pct) use ($job, $checkCancel, $logPath) {
                        $checkCancel();
                        // map personnalites progress to 0..20
                        $repo = $this->em->getRepository(ImportJob::class);
                        $fresh = $repo->find($job->getId());
                        if ($fresh) {
                            $fresh->setProgress((int) floor($pct * 0.2));
                            $this->em->flush();
                            $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $fresh->getProgress() . "\n");
                        }
                    });
                    $this->manager->appendLog($logPath, "Senat personnalites: " . json_encode($res) . "\n");
                    $job->setProgress(20);
                    $this->em->flush();
                    $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $job->getProgress() . "\n");
                }

                if (!$skipScrutins) {
                    $res2 = $this->senatScrutins->importer($fromYear, $toYear, $limit, $force, function(int $pct) use ($job, $checkCancel, $logPath) {
                        $checkCancel();
                        $repo = $this->em->getRepository(ImportJob::class);
                        $fresh = $repo->find($job->getId());
                        if ($fresh) {
                            $fresh->setProgress(20 + (int) floor($pct * 0.7));
                            $this->em->flush();
                            $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $fresh->getProgress() . "\n");
                        }
                    });
                    $this->manager->appendLog($logPath, "Senat scrutins: " . json_encode($res2) . "\n");
                    $job->setProgress(95);
                    $this->em->flush();
                    $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $job->getProgress() . "\n");
                }

                $job->setProgress(100);
                $this->em->flush();
                $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $job->getProgress() . "\n");
                $job->setStatus('success');
                $job->setFinishedAt(new \DateTime());
                $this->em->flush();
            } elseif ($cmd === 'app:ia:traiter' && $this->iaTraiter !== null) {
                // run IA traiter command in-process via its service
                $inputArgs = [];
                foreach ($job->getArgs() as $k => $v) {
                    $inputArgs['--' . $k] = $v;
                }
                $input = new ArrayInput($inputArgs);
                $output = new BufferedOutput();
                $code = $this->iaTraiter->run($input, $output);
                $this->manager->appendLog($logPath, $output->fetch());
                $job->setProgress(100);
                $job->setStatus($code === 0 ? 'success' : 'failed');
                $job->setFinishedAt(new \DateTime());
                $this->em->flush();
            } elseif ($cmd === 'app:vulgariser' && $this->vulgariser !== null) {
                $inputArgs = [];
                foreach ($job->getArgs() as $k => $v) {
                    $inputArgs['--' . $k] = $v;
                }
                $input = new ArrayInput($inputArgs);
                $output = new BufferedOutput();
                $code = $this->vulgariser->run($input, $output);
                $this->manager->appendLog($logPath, $output->fetch());
                $job->setStatus($code === 0 ? 'success' : 'failed');
                $job->setFinishedAt(new \DateTime());
                $this->em->flush();
            } elseif ($cmd === 'app:taguer' && $this->taguer !== null) {
                $inputArgs = [];
                foreach ($job->getArgs() as $k => $v) {
                    $inputArgs['--' . $k] = $v;
                }
                $input = new ArrayInput($inputArgs);
                $output = new BufferedOutput();
                $code = $this->taguer->run($input, $output);
                $this->manager->appendLog($logPath, $output->fetch());
                $job->setStatus($code === 0 ? 'success' : 'failed');
                $job->setFinishedAt(new \DateTimeImmutable());
                $this->em->flush();
            } elseif ($cmd === 'app:import-assemblee' && $this->assembleeImporter !== null) {
                // parse args: prefer structured args when available, otherwise parse raw CLI-like string
                $parsed = [];
                if (is_array($args) && count($args) > 0) {
                    foreach ($args as $k => $v) {
                        $parsed[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                    }
                } else {
                    $parsed = $this->parseRawArgs($raw);
                }

                $fromYear = isset($parsed['from-year']) ? (int) $parsed['from-year'] : null;
                $toYear = isset($parsed['to-year']) ? (int) $parsed['to-year'] : null;
                $limit = isset($parsed['limit']) ? (int) $parsed['limit'] : null;
                $force = isset($parsed['force']);
                $chambre = $parsed['chambre'] ?? null;

                // run Assemblee importer in-process with progress/cancel callback
                $this->manager->appendLog($logPath, "Starting Assemblée import\n");
                $this->assembleeImporter->importTextes(null, function(int $pct) use ($job, $checkCancel, $logPath) {
                    $checkCancel();
                    $repo = $this->em->getRepository(ImportJob::class);
                    $fresh = $repo->find($job->getId());
                    if ($fresh) {
                        $fresh->setProgress($pct);
                        $this->em->flush();
                        $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $fresh->getProgress() . "\n");
                    }
                }, $fromYear, $toYear, $limit, $force, $chambre);
                $this->manager->appendLog($logPath, "Assemblee scrutins processed\n");
                $job->setProgress(100);
                $this->em->flush();
                $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] PROGRESS " . $job->getProgress() . "\n");
                $job->setStatus('success');
                $job->setFinishedAt(new \DateTime());
                $this->em->flush();
            } else {
                // fallback: spawn process to run the command (keeps behaviour for all others)
                $this->manager->start($job);
            }

            
        } catch (JobCancelledException $e) {
            $this->manager->appendLog($logPath, "CANCELLED: " . $e->getMessage() . "\n");
            $job->setStatus('cancelled');
            $job->setFinishedAt(new \DateTime());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->manager->appendLog($logPath, "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            $job->setStatus('failed');
            $job->setFinishedAt(new \DateTime());
            $this->em->flush();
            if ($this->logger) {
                $this->logger->error('Import job failed: ' . $e->getMessage(), ['exception' => $e]);
            }
        } finally {
            $this->manager->appendLog($logPath, '[' . (new \DateTime())->format(DATE_ATOM) . "] END job {$job->getId()}\n");
        }
    }

    /** Parse a raw CLI-like arg string into an associative array of allowed flags/values */
    private function parseRawArgs(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $result = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (!str_starts_with($p, '--')) continue;
            $p = substr($p, 2);
            if ($p === '') continue;
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $result[$k] = $v;
            } else {
                $result[$p] = true;
            }
        }

        // whitelist: only allow known options for security
        $allowed = [
            'from-year', 'to-year', 'limit', 'force', 'skip-personnalites', 'skip-scrutins', 'chambre',
        ];
        foreach (array_keys($result) as $k) {
            if (!in_array($k, $allowed, true)) {
                unset($result[$k]);
            }
        }

        return $result;
    }
}
