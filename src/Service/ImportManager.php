<?php

namespace App\Service;

use App\Entity\ImportJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class ImportManager
{
    /** Environment variable carrying the job id to the spawned console process. */
    public const JOB_ENV = 'IMPORT_JOB_ID';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(service: 'console.command_loader')] private readonly CommandLoaderInterface $commandLoader,
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /** Append a line to the given log file, ensuring size cap/rotation. */
    public function appendLog(string $path, string $line, int $maxBytes = 2_000_000, int $keepBytes = 1_000_000): void
    {
        // ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // append
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        // rotate/truncate if too large: keep last $keepBytes bytes
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : 0;
        if ($size > $maxBytes) {
            // read last keepBytes
            $fp = fopen($path, 'rb');
            if ($fp !== false) {
                if ($keepBytes > 0) {
                    fseek($fp, -$keepBytes, SEEK_END);
                    $data = stream_get_contents($fp);
                } else {
                    $data = '';
                }
                fclose($fp);
                // overwrite file with kept data
                file_put_contents($path, $data, LOCK_EX);
            }
        }

        // Also keep a preview in the DB for cases where files are removed on restart
        try {
            $repo = $this->em->getRepository(\App\Entity\ImportJob::class);
            $job = $repo->findOneBy(['logPath' => $path]);
            if ($job !== null) {
                // append to preview and keep last $keepBytes bytes
                $prev = (string) ($job->getLogPreview() ?? '');
                $prev .= $line;
                if ($keepBytes > 0 && strlen($prev) > $keepBytes) {
                    $prev = substr($prev, -$keepBytes);
                }
                $job->setLogPreview($prev);
                $this->em->persist($job);
                $this->em->flush();
            }
        } catch (\Throwable) {
            // ignore DB errors while writing preview
        }
    }

    public function start(ImportJob $job): ImportJob
    {
        $logDir = $this->projectDir . '/var/import-logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        // reuse the placeholder log created by the controller, so no orphan file is left behind
        $filename = $job->getLogPath() ?? sprintf('%s/import-%s-%s.log', $logDir, (new \DateTime())->format('Ymd-His'), bin2hex(random_bytes(4)));

        $cmd = $this->buildCommand($job);

        // mark running before spawning, so a very short command can still record its outcome
        $job->setLogPath($filename);
        $job->setStatus('running');
        $job->setStartedAt(new \DateTime());
        $job->setProgress(0);
        $this->em->flush();

        // launch as detached background process and capture PID
        $shell = sprintf('nohup %s > %s 2>&1 & echo $!', $cmd, escapeshellarg($filename));

        $process = Process::fromShellCommandline($shell, $this->projectDir, [self::JOB_ENV => (string) $job->getId()], null, 60);
        $process->run();
        $pid = trim($process->getOutput());

        $job->setPid($pid ?: null);
        $this->em->flush();

        return $job;
    }

    private function buildCommand(ImportJob $job): string
    {
        $name = $job->getCommand();
        if (!$this->commandLoader->has($name)) {
            throw new \InvalidArgumentException(sprintf('Unknown command "%s".', $name));
        }

        $parts = ['php', '-d', 'memory_limit=1G', 'bin/console', $name];
        foreach ($this->effectiveOptions($name, $job->getArgs()) as $k => $v) {
            $parts[] = $v === true ? '--' . $k : '--' . $k . '=' . $v;
        }

        // include job id so detached console processes can report progress back to DB
        if ($job->getId() !== null && $this->commandLoader->get($name)->getDefinition()->hasOption('job-id')) {
            $parts[] = '--job-id=' . $job->getId();
        }

        return implode(' ', array_map('escapeshellarg', $parts));
    }

    /**
     * Options the command really receives for the given args: unknown keys are dropped,
     * flags become true and value options become strings.
     *
     * @param array<array-key, mixed> $args
     *
     * @return array<string, string|true>
     */
    public function effectiveOptions(string $command, array $args): array
    {
        if (!$this->commandLoader->has($command)) {
            return [];
        }

        $definition = $this->commandLoader->get($command)->getDefinition();
        $options = [];
        foreach ($args as $k => $v) {
            if (!\is_string($k) || $k === 'job-id' || !$definition->hasOption($k)) {
                continue;
            }
            if (!$definition->getOption($k)->acceptValue()) {
                if ($v === true || $v === '1' || $v === 'on') {
                    $options[$k] = true;
                }
                continue;
            }
            if ($v === null || $v === false || $v === '' || \is_array($v)) {
                continue;
            }
            // older jobs stored "1" as true
            $options[$k] = $v === true ? '1' : (string) $v;
        }

        return $options;
    }

    /** @return array<string, string> option name => description, as declared by the command */
    public function optionDescriptions(string $command): array
    {
        if (!$this->commandLoader->has($command)) {
            return [];
        }

        $descriptions = [];
        foreach ($this->commandLoader->get($command)->getDefinition()->getOptions() as $option) {
            $descriptions[$option->getName()] = $option->getDescription();
        }

        return $descriptions;
    }

    /**
     * Delete finished jobs (pending/running ones are kept) and their log files. Only files
     * belonging to the deleted jobs are removed, never other files of the log directory.
     *
     * @return array{jobs: int, files: int}
     */
    public function purgeFinished(): array
    {
        $connection = $this->em->getConnection();
        $finished = "status NOT IN ('pending', 'running')";
        $paths = $connection->fetchFirstColumn("SELECT log_path FROM import_job WHERE {$finished} AND log_path IS NOT NULL");
        $jobs = $connection->executeStatement("DELETE FROM import_job WHERE {$finished}");
        $this->em->clear();

        $logDir = $this->projectDir . '/var/import-logs/';
        $files = 0;
        foreach ($paths as $path) {
            if (str_starts_with($path, $logDir) && is_file($path) && @unlink($path)) {
                ++$files;
            }
        }

        return ['jobs' => $jobs, 'files' => $files];
    }

    public function isRunning(ImportJob $job): bool
    {
        $pid = (int) $job->getPid();
        if ($pid <= 0) {
            return false;
        }

        // the pid must still be this job's console process (pids get reused after restarts)
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return \is_string($cmdline) && str_contains($cmdline, 'bin/console') && str_contains($cmdline, $job->getCommand());
    }

    /**
     * Bring a running job's progress and status in line with its process: progress is read
     * from the console progress bar in its log, and a process that exited without recording
     * its outcome (fatal error, killed, older job) is marked as failed.
     *
     * @return bool whether the job changed
     */
    public function reconcile(ImportJob $job): bool
    {
        if ($job->getStatus() !== 'running' || $job->getId() === null) {
            return false;
        }

        $connection = $this->em->getConnection();
        $changed = false;

        $progress = $this->progressFromLog($job->getLogPath());
        if ($progress !== null && $progress > $job->getProgress()) {
            $changed = $connection->executeStatement(
                "UPDATE import_job SET progress = ? WHERE id = ? AND status = 'running' AND progress < ?",
                [$progress, $job->getId(), $progress],
            ) > 0;
        }

        if (!$this->isRunning($job)) {
            // the outcome wasn't recorded (fatal error, killed, job launched before the console
            // listener existed): fall back on what the command printed last
            $success = $this->outcomeFromLog($job->getLogPath());
            $sql = $success
                ? "UPDATE import_job SET status = 'success', progress = 100, finished_at = NOW() WHERE id = ? AND status = 'running'"
                : "UPDATE import_job SET status = 'failed', finished_at = NOW() WHERE id = ? AND status = 'running'";
            // conditional: the console listener may have just recorded the real outcome
            $interrupted = $connection->executeStatement($sql, [$job->getId()]) > 0;
            $changed = $changed || $interrupted;
            if ($interrupted && $job->getLogPath() !== null) {
                $this->appendLog($job->getLogPath(), sprintf(
                    "[%s] Fin du processus non enregistrée ; d'après le journal : %s.\n",
                    (new \DateTime())->format(DATE_ATOM),
                    $success ? 'succès' : 'échec ou issue inconnue',
                ));
            }
        }

        if ($changed) {
            $this->em->refresh($job);
        }

        return $changed;
    }

    /** Whether the command's last message is a success block ("[OK] …") rather than an error. */
    private function outcomeFromLog(?string $path): bool
    {
        if ($path === null || !is_file($path)) {
            return false;
        }

        $size = filesize($path) ?: 0;
        $tail = (string) @file_get_contents($path, false, null, max(0, $size - 16384));
        $ok = strrpos($tail, ' [OK] ');
        $error = max((int) strrpos($tail, ' [ERROR] '), preg_match_all('#^In \S+ line \d+:#m', $tail, $m, PREG_OFFSET_CAPTURE) ? (int) end($m[0])[1] : 0);

        return $ok !== false && $ok > $error;
    }

    /** Last percentage printed by a console progress bar (" 12/40 [▓▓░░]  30%") in the log. */
    public function progressFromLog(?string $path): ?int
    {
        if ($path === null || !is_file($path)) {
            return null;
        }

        $size = filesize($path) ?: 0;
        $tail = (string) @file_get_contents($path, false, null, max(0, $size - 16384));
        if (!preg_match_all('#(\d+)/(\d+) \[[^\]\n]*\]\s+\d{1,3}%#u', $tail, $m, PREG_SET_ORDER)) {
            return null;
        }

        [, $current, $max] = end($m);

        return (int) $max > 0 ? min(100, intdiv(100 * (int) $current, (int) $max)) : null;
    }

    public function tailLog(?string $path, int $lines = 200): string
    {
        if ($path === null || !is_file($path)) {
            // fallback to DB preview if available
            try {
                $repo = $this->em->getRepository(\App\Entity\ImportJob::class);
                $job = $repo->findOneBy(['logPath' => $path]);
                if ($job !== null && $job->getLogPreview() !== null) {
                    $content = (string) $job->getLogPreview();
                    // return last $lines lines
                    $parts = preg_split('/\R/', $content);
                    if ($parts === false) return $content;
                    $last = array_slice($parts, -$lines);
                    return implode("\n", $last);
                }
            } catch (\Throwable) {
                return '';
            }

            return '';
        }

        $content = shell_exec(sprintf('tail -n %d %s', $lines, escapeshellarg($path)));

        return (string) $content;
    }

    public function cancel(ImportJob $job): bool
    {
        // Mark job as cancel requested in DB so in-process handlers can stop cooperatively
        $job->setCancelRequested(true);
        $this->em->persist($job);
        $this->em->flush();

        $pid = $job->getPid();
        if ($pid === null) {
            // nothing else to do: in-process handler should observe cancelRequested
            return true;
        }

        $pidInt = (int) $pid;
        if ($pidInt <= 0) {
            return false;
        }

        // try graceful: use posix_kill when available, otherwise fallback to shell kill
        $sigterm = defined('SIGTERM') ? SIGTERM : 15;
        $sigkill = defined('SIGKILL') ? SIGKILL : 9;
        if (function_exists('posix_kill')) {
            @posix_kill($pidInt, $sigterm);
            // if still running after short wait, force
            sleep(1);
            if ($this->isRunning($job)) {
                @posix_kill($pidInt, $sigkill);
            }
        } else {
            @shell_exec(sprintf('kill -%d %d >/dev/null 2>&1', $sigterm, $pidInt));
            sleep(1);
            if ($this->isRunning($job)) {
                @shell_exec(sprintf('kill -%d %d >/dev/null 2>&1', $sigkill, $pidInt));
            }
        }

        $job->setStatus('cancelled');
        $job->setFinishedAt(new \DateTime());
        $this->em->flush();

        return true;
    }
}
