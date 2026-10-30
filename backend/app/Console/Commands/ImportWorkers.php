<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Services\WorkerImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportWorkers extends Command
{
    protected $signature = 'visitorportal:import-workers {file : CSV file with the worker master list} {--dry-run : Validate the file without saving anything} {--deactivate-missing : Deactivate every active worker that is not in the file (the file must be the full list of active workers)}';

    protected $description = 'Import or update the worker master list from a CSV file';

    public function handle(WorkerImporter $importer): int
    {
        try {
            $result = $importer->import((string) $this->argument('file'), (bool) $this->option('dry-run'), (bool) $this->option('deactivate-missing'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error['line'] > 0 ? "Line {$error['line']}: {$error['message']}" : $error['message']);
        }

        $this->info(sprintf(
            '%s created %d, updated %d, unchanged %d, skipped %d, deactivated %d.',
            $this->option('dry-run') ? '[dry run]' : 'Done:',
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'],
            $result['deactivated'],
        ));

        return $result['skipped'] > 0 ? self::INVALID : self::SUCCESS;
    }
}
