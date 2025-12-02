<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class PipeRunner
{
    public function pipe(string $program, string $payload): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($program, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Unable to run pipe program "%s".', $program));
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        if ($status !== 0) {
            $message = sprintf(
                'Pipe program "%s" exited with code %d. %s %s',
                $program,
                $status,
                $stdout !== '' ? 'stdout: ' . trim($stdout) : '',
                $stderr !== '' ? 'stderr: ' . trim($stderr) : ''
            );

            throw new \RuntimeException(trim($message));
        }
    }
}
