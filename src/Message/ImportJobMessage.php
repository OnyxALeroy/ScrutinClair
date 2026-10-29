<?php

namespace App\Message;

final class ImportJobMessage
{
    public function __construct(public readonly int $jobId)
    {
    }
}
