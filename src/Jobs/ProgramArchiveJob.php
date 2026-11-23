<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\ProgramRepository;

final class ProgramArchiveJob
{
    public function __construct(
        private readonly ProgramRepository $programs,
    ) {
    }

    /**
     * @return array{archived: int, ids: list<int>}
     */
    public function run(Config $config): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $today = Clock::now($config)->setTimezone($tz)->format('Y-m-d');
        $ids = $this->programs->archivePastEvents($today);

        return ['archived' => count($ids), 'ids' => $ids];
    }
}