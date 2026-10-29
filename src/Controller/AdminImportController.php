<?php

namespace App\Controller;

use App\Entity\ImportJob;
use App\Message\ImportJobMessage;
use App\Service\ImportManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AdminImportController extends AbstractController
{
    /** Commands that can be launched from the admin, with their human label. */
    private const COMMANDS = [
        'app:import-senat' => 'Import sénat (sénateurs + scrutins)',
        'app:import-assemblee' => 'Import Assemblée (scrutins)',
        'app:import-personnalites' => 'Import personnalités (Assemblée)',
        'app:import-votes' => 'Recréer les votes depuis JSON',
        'app:backfill-textes-an' => 'Backfill textes (Assemblée)',
        'app:ia:traiter' => 'IA — Traiter (titre / tags / résumé)',
        'app:taguer' => 'IA — Classer par thèmes',
        'app:vulgariser' => 'IA — Vulgariser',
        'app:renommer' => 'Renommer textes',
    ];

    public function __construct(private readonly EntityManagerInterface $em, private readonly ImportManager $manager)
    {
    }

    #[Route('/admin/imports', name: 'admin_imports')]
    public function index(Request $request): Response
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $jobs = $repo->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 50);

        // compute metadata for template (whether a download is available)
        $jobsMeta = [];
        foreach ($jobs as $j) {
            $this->manager->reconcile($j);
            $path = $j->getLogPath();
            $hasFile = $path !== null && is_file($path);
            $hasPreview = (method_exists($j, 'getLogPreview') && $j->getLogPreview() !== null && $j->getLogPreview() !== '');
            $jobsMeta[$j->getId()] = [
                'downloadable' => ($hasFile || $hasPreview),
                'params' => $this->describeOptions($j->getCommand(), $this->manager->effectiveOptions($j->getCommand(), $j->getArgs())),
            ];
        }

        $commands = [];
        foreach (self::COMMANDS as $name => $label) {
            $commands[] = ['name' => $name, 'label' => $label];
        }

        return $this->render('admin/imports/index.html.twig', [
            'commands' => $commands,
            'commandsMap' => self::COMMANDS,
            'statusLabels' => self::STATUS_LABELS,
            'jobs' => $jobs,
            'jobsMeta' => $jobsMeta,
        ]);
    }

    private const SSE_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        // otherwise nginx buffers the stream and the page receives nothing until it ends
        'X-Accel-Buffering' => 'no',
    ];

    private const STATUS_LABELS = [
        'pending' => 'En attente',
        'running' => 'En cours',
        'success' => 'Terminé',
        'failed' => 'Échec',
        'cancelled' => 'Annulé',
    ];

    /** Commands whose --limit / --uid / --force apply to scrutins rather than textes. */
    private const SCRUTIN_COMMANDS = ['app:import-senat', 'app:import-assemblee', 'app:import-votes'];

    private const FLAG_LABELS = [
        'skip-personnalites' => 'Sans import des sénateurs et groupes',
        'skip-scrutins' => 'Sans import des scrutins',
        'skip-titre' => 'Sans titres courts',
        'skip-tags' => 'Sans tags',
        'skip-resume' => 'Sans résumés',
        'dry-run' => 'Simulation (sans appel à l\'IA)',
    ];

    private const CHAMBRES = ['AN' => 'Assemblée nationale uniquement', 'SENAT' => 'Sénat uniquement', 'all' => 'Toutes les chambres'];

    private const TYPES = ['amendement' => 'Amendements uniquement', 'dossier' => 'Dossiers uniquement'];

    /**
     * Turn a job's effective options into readable chips for the jobs table;
     * each chip's hint gives the exact option passed and the command's own description of it.
     *
     * @param array<string, string|true> $options
     *
     * @return list<array{label: string, hint: string, flag: bool}>
     */
    private function describeOptions(string $command, array $options): array
    {
        $descriptions = $this->manager->optionDescriptions($command);
        $hint = static function (string $name, string|true $value) use ($descriptions): string {
            $cli = $value === true ? '--' . $name : '--' . $name . '=' . $value;

            return ($descriptions[$name] ?? '') !== '' ? $cli . ' — ' . $descriptions[$name] : $cli;
        };
        $scrutins = \in_array($command, self::SCRUTIN_COMMANDS, true);
        $chips = [];

        $from = $options['from-year'] ?? null;
        $to = $options['to-year'] ?? null;
        unset($options['from-year'], $options['to-year']);
        if ($from !== null || $to !== null) {
            $label = match (true) {
                $from !== null && $to !== null && $from === $to => 'Scrutins de ' . $from,
                $from !== null && $to !== null => 'Scrutins de ' . $from . ' à ' . $to,
                $from !== null => 'Scrutins depuis ' . $from,
                default => 'Scrutins jusqu\'à ' . $to,
            };
            $hints = array_filter([$from !== null ? $hint('from-year', $from) : null, $to !== null ? $hint('to-year', $to) : null]);
            $chips[] = ['label' => $label, 'hint' => implode("\n", $hints), 'flag' => false];
        }

        foreach ($options as $name => $value) {
            $label = match ($name) {
                'force' => match ($command) {
                    'app:import-senat', 'app:import-assemblee' => 'Réimport des votes déjà présents',
                    'app:import-votes' => 'Recréation des votes déjà présents',
                    default => 'Retraitement des textes déjà traités',
                },
                'limit' => sprintf('Au plus %d %s', (int) $value, ($scrutins ? 'scrutin' : 'texte') . ((int) $value > 1 ? 's' : '')),
                'uid' => 'Scrutin ' . $value . ' uniquement',
                'dir' => 'Fichiers JSON : ' . $value,
                'type' => self::TYPES[$value] ?? 'Type : ' . $value,
                'chambre' => self::CHAMBRES[$value] ?? 'Chambre : ' . $value,
                'shard' => preg_match('#^(\d+)/(\d+)$#', (string) $value, $m) ? sprintf('Lot %d sur %d', (int) $m[1] + 1, (int) $m[2]) : 'Lot ' . $value,
                default => $value === true ? (self::FLAG_LABELS[$name] ?? '--' . $name) : '--' . $name . ' = ' . $value,
            };
            $chips[] = ['label' => $label, 'hint' => $hint($name, $value), 'flag' => $value === true];
        }

        return $chips;
    }

    #[Route('/admin/imports/start', name: 'admin_imports_start', methods: ['POST'])]
    public function start(Request $request): RedirectResponse
    {
        $cmd = (string) $request->request->get('command', '');

        // Use all() to safely retrieve array parameters (InputBag::get rejects arrays)
        $posted = $request->request->all();
        $params = [];
        if (isset($posted['params']) && is_array($posted['params'])) {
            $params = $posted['params'];
        }

        if (!isset(self::COMMANDS[$cmd])) {
            $this->addFlash('error', 'Commande inconnue');
            return $this->redirectToRoute('admin_imports');
        }

        // store exactly what the command will receive, so the jobs table shows what really ran
        $args = $this->manager->effectiveOptions($cmd, $params);

        $job = new ImportJob($cmd, $args);
        $this->em->persist($job);
        $this->em->flush();

        // create a placeholder log file immediately so the UI can offer a download
        try {
            $projectDir = $this->getParameter('kernel.project_dir');
            $logDir = $projectDir . '/var/import-logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $placeholder = sprintf('%s/import-%s-%s.log', $logDir, (new \DateTime())->format('Ymd-His'), bin2hex(random_bytes(4)));
            // create empty file
            @file_put_contents($placeholder, '[' . (new \DateTime())->format(DATE_ATOM) . "] PLACEHOLDER for job {$job->getId()}\n", LOCK_EX);
            $job->setLogPath($placeholder);
            // also set a small DB preview so downloads work even if file is removed by restart hooks
            $job->setLogPreview('[' . (new \DateTime())->format(DATE_ATOM) . "] PLACEHOLDER for job {$job->getId()}\n");
            $this->em->persist($job);
            $this->em->flush();
        } catch (\Throwable) {
            // ignore placeholder failures
        }

        // dispatch to messenger if available, otherwise spawn process directly
        $label = self::COMMANDS[$cmd];
        if ($this->container->has('message_bus')) {
            $bus = $this->container->get('message_bus');
            $bus->dispatch(new ImportJobMessage($job->getId()));
            $this->addFlash('success', sprintf('Tâche en file : %s (id %d)', $label, $job->getId()));
        } else {
            $this->manager->start($job);
            $this->addFlash('success', sprintf('Tâche lancée : %s (id %d)', $label, $job->getId()));
        }

        return $this->redirectToRoute('admin_imports');
    }

    #[Route('/admin/imports/clear', name: 'admin_imports_clear', methods: ['POST'])]
    public function clear(): RedirectResponse
    {
        $purged = $this->manager->purgeFinished();
        $this->addFlash('success', sprintf('Historique vidé : %d tâche(s) et %d journal(aux) supprimé(s). Les tâches en cours sont conservées.', $purged['jobs'], $purged['files']));

        return $this->redirectToRoute('admin_imports');
    }

    #[Route('/admin/imports/{id}/status', name: 'admin_imports_status', methods: ['GET'])]
    public function status(int $id): Response
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $job = $repo->find($id);
        if ($job === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $this->manager->reconcile($job);
        $log = $this->manager->tailLog($job->getLogPath(), 200);

        return $this->json([
            'id' => $job->getId(),
            'status' => $job->getStatus(),
            'pid' => $job->getPid(),
            'log' => $log,
            'progress' => $job->getProgress(),
        ]);
    }

    #[Route('/admin/imports/{id}/cancel', name: 'admin_imports_cancel', methods: ['POST'])]
    public function cancel(int $id): RedirectResponse
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $job = $repo->find($id);
        if ($job === null) {
            $this->addFlash('error', 'Job introuvable');
            return $this->redirectToRoute('admin_imports');
        }

        $this->manager->cancel($job);
        $this->addFlash('success', 'Job annulé');

        return $this->redirectToRoute('admin_imports');
    }

    #[Route('/admin/imports/{id}/stream', name: 'admin_imports_stream', methods: ['GET'])]
    public function streamSse(int $id): StreamedResponse
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $job = $repo->find($id);
        if ($job === null) {
            return new StreamedResponse(fn() => print('Not found'), Response::HTTP_NOT_FOUND);
        }

        $response = new StreamedResponse(function () use ($job) {
            $last = '';
            $idle = 0;
            while (!connection_aborted()) {
                // refresh job to get latest status/progress written by handlers
                try {
                    $this->em->refresh($job);
                } catch (\Throwable) {
                    // ignore
                }

                $log = $this->manager->tailLog($job->getLogPath(), 1000);
                $status = $job->getStatus();
                $progress = $job->getProgress();
                $payload = json_encode(['log' => $log, 'status' => $status, 'progress' => $progress]);
                if ($payload !== $last) {
                    echo "data: " . $payload . "\n\n";
                    $last = $payload;
                    $idle = 0;
                } else {
                    $idle++;
                }

                // if job finished and no changes for a few loops, stop streaming
                if (in_array($status, ['success', 'failed', 'cancelled'], true) && $idle > 3) {
                    break;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                sleep(1);
            }
        }, Response::HTTP_OK, self::SSE_HEADERS);

        return $response;
    }

    #[Route('/admin/imports/stream-all', name: 'admin_imports_stream_all', methods: ['GET'])]
    public function streamAll(): StreamedResponse
    {
        $repo = $this->em->getRepository(ImportJob::class);

        $response = new StreamedResponse(function () use ($repo) {
            $last = '';
            $ticks = 0;
            while (!connection_aborted()) {
                try {
                    // refresh repository state and reconcile running jobs with their process
                    $this->em->clear();
                    $jobs = $repo->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 50);
                    foreach ($jobs as $j) {
                        $this->manager->reconcile($j);
                    }

                    $data = [];
                    foreach ($jobs as $j) {
                        $data[$j->getId()] = [
                            'id' => $j->getId(),
                            'status' => $j->getStatus(),
                            'pid' => $j->getPid(),
                            'progress' => $j->getProgress(),
                        ];
                    }

                    $payload = json_encode($data);
                    if ($payload !== $last) {
                        echo "data: " . $payload . "\n\n";
                        $last = $payload;
                    }
                } catch (\Throwable $e) {
                    // ignore but do not break the loop
                    // write to PHP error log for investigation
                    error_log('streamAll error: ' . $e->getMessage());
                }

                // end the stream regularly (the browser reconnects) so it never pins a php-fpm worker for long
                if (++$ticks >= 30) {
                    break;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                sleep(1);
            }
        }, Response::HTTP_OK, self::SSE_HEADERS);

        return $response;
    }

    #[Route('/admin/imports/{id}/download', name: 'admin_imports_download', methods: ['GET'])]
    public function download(int $id): Response
    {
        $repo = $this->em->getRepository(ImportJob::class);
        $job = $repo->find($id);
        if ($job === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $path = $job->getLogPath();

        // If the file exists, send it directly
        if ($path !== null && is_file($path)) {
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($path);
            $disposition = $response->headers->makeDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
            $response->headers->set('Content-Disposition', $disposition);

            return $response;
        }

        // Fallback: if the file was removed but we still have recent content in the log path, stream it
        if ($path !== null) {
            $content = $this->manager->tailLog($path, 2000);
                if ($content !== '') {
                    $filename = basename($path) ?: sprintf('import-%d.log', $job->getId());
                    $response = new \Symfony\Component\HttpFoundation\Response($content, 200, [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                    ]);
                    $disposition = $response->headers->makeDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
                    $response->headers->set('Content-Disposition', $disposition);

                    return $response;
                }
        }

        // If we reach here, no file and no preview: return a small placeholder message and persist it
        $placeholderContent = '[' . (new \DateTime())->format(DATE_ATOM) . "] (journal supprimé ou non disponible)\n";
        try {
            $job->setLogPreview($placeholderContent);
            $this->em->persist($job);
            $this->em->flush();
        } catch (\Throwable) {
            // ignore
        }

        $filename = sprintf('import-%d.log', $job->getId());
        $response = new \Symfony\Component\HttpFoundation\Response($placeholderContent, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
        $disposition = $response->headers->makeDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
