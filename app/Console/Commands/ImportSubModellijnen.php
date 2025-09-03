<?php

namespace App\Console\Commands;

use App\Services\CrystallizePIMGraphQLService;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ImportSubModellijnen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sub-modellijnen {file} {--dry-run : Show what would be imported without creating anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import sub-modellijnen from Excel file to Crystallize using Discovery + PIM APIs';

    private CrystallizePIMGraphQLService $pimService;
    private array $stats = [
        'processed' => 0,
        'created' => 0,
        'already_exists' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    public function handle(): int
    {
        $this->pimService = app(CrystallizePIMGraphQLService::class);
        $filePath = $this->argument('file');
        $isDryRun = $this->option('dry-run');

        // Validate file exists
        if (!file_exists(storage_path('app/' . $filePath))) {
            $this->error("File not found: storage/app/{$filePath}");
            return 1;
        }

        $this->info('Starting import of sub-modellijnen...');
        $this->info('Using Discovery API for searching + PIM API for creation');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual changes will be made');
        }

        try {
            // Read Excel file
            $data = $this->readExcelFile(storage_path('app/' . $filePath));

            if ($data->isEmpty()) {
                $this->error('No data found in Excel file');
                return 1;
            }

            $this->info("Found {$data->count()} rows to process");

            // Process the data
            $this->processImport($data, $isDryRun);

            // Show summary
            $this->showSummary();

            return 0;

        } catch (\Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            Log::error('Sub-modellijnen import failed', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            return 1;
        }
    }

    private function readExcelFile(string $filePath): Collection
    {
        $this->info('Reading Excel file...');

        // Read Excel with headers
        $data = Excel::toCollection(null, $filePath)->first();

        // Get headers from first row
        $headers = $data->first()->toArray();
        $this->info('Excel headers found: ' . implode(', ', $headers));

        // Convert to associative array using headers
        $processedData = $data->skip(1)->map(function ($row) use ($headers) {
            return collect($headers)->mapWithKeys(function ($header, $index) use ($row) {
                return [strtolower(trim($header)) => $row[$index] ?? ''];
            });
        });

        // Filter empty rows
        return $processedData->filter(function ($row) {
            return !empty($row['merk']) || !empty($row['modellijn']) || !empty($row['sub-modellijn']);
        });
    }

    private function processImport(Collection $data, bool $isDryRun): void
    {
        $pathCache = [];
        $progressBar = $this->output->createProgressBar($data->count());

        foreach ($data as $index => $row) {
            $this->stats['processed']++;

            try {
                // Extract data from row using header names
                $merkName = trim($row['merk'] ?? '');
                $modellijnName = trim($row['modellijn'] ?? '');
                $subModellijnName = trim($row['sub-modellijn'] ?? '');

                // Validate required fields
                if (empty($merkName) || empty($modellijnName) || empty($subModellijnName)) {
                    $this->warn("Row " . ($index + 2) . ": Missing required data - skipping");
                    $this->stats['skipped']++;
                    continue;
                }

                if ($isDryRun) {
                    $this->line("Would create: {$merkName} > {$modellijnName} > {$subModellijnName}");
                    $this->stats['created']++;
                } else {
                    $result = $this->createFolderStructure(
                        $merkName,
                        $modellijnName,
                        $subModellijnName,
                        $pathCache
                    );

                    // $result can be: 'created', 'exists', or 'failed'
                    if ($result === 'created') {
                        $this->stats['created']++;
                    } elseif ($result === 'exists') {
                        $this->stats['already_exists']++;
                    } else {
                        $this->stats['failed']++;
                    }
                }

                // Add small delay to prevent rate limiting
                if (!$isDryRun) {
                    $this->pimService->addDelay(100);
                }

            } catch (\Exception $e) {
                $this->error("Row " . ($index + 2) . " failed: " . $e->getMessage());
                $this->stats['failed']++;
                Log::error('Row processing failed', [
                    'row' => $index + 2,
                    'error' => $e->getMessage(),
                    'data' => $row
                ]);
            }

            $progressBar->advance();

            // Progress update every 50 items
            if (($this->stats['processed'] % 50) === 0) {
                $this->newLine(2);
                $this->info("Progress: {$this->stats['processed']}/{$data->count()} processed");
                $this->info("Created: {$this->stats['created']}, Exists: {$this->stats['already_exists']}, Failed: {$this->stats['failed']}, Skipped: {$this->stats['skipped']}");
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function createFolderStructure(
        string $merkName,
        string $modellijnName,
        string $subModellijnName,
        array &$pathCache
    ): string {
        try {
            $wasCreated = false;

            // Get or create Merk folder (with caching)
            $merkCacheKey = "merk:{$merkName}";
            if (!isset($pathCache[$merkCacheKey])) {
                $merkPath = $this->pimService->ensureMerkFolder($merkName);
                if (!$merkPath) {
                    throw new \Exception("Failed to create/find Merk folder: {$merkName}");
                }
                $pathCache[$merkCacheKey] = $merkPath;
                $this->line("  ✓ Merk: {$merkName} -> {$merkPath}");
            } else {
                $merkPath = $pathCache[$merkCacheKey];
            }

            // Get or create Modellijn folder (with caching)
            $modellijnCacheKey = "modellijn:{$merkName}:{$modellijnName}";
            if (!isset($pathCache[$modellijnCacheKey])) {
                $modellijnPath = $this->pimService->ensureModellijnFolder($modellijnName, $merkPath);
                if (!$modellijnPath) {
                    throw new \Exception("Failed to create/find Modellijn folder: {$modellijnName}");
                }
                $pathCache[$modellijnCacheKey] = $modellijnPath;
                $this->line("  ✓ Modellijn: {$modellijnName} -> {$modellijnPath}");
            } else {
                $modellijnPath = $pathCache[$modellijnCacheKey];
            }

            // Create or get Sub-modellijn folder (with caching)
            $subModellijnCacheKey = "sub-modellijn:{$merkName}:{$modellijnName}:{$subModellijnName}";
            if (!isset($pathCache[$subModellijnCacheKey])) {
                // Check if it already exists in Crystallize (not in cache)
                $discoveryService = app(\App\Services\CrystallizeDiscoveryService::class);
                $existingFolder = $discoveryService->findFolderByNameAndShape($subModellijnName, 'sub-modellijn');

                if ($existingFolder && isset($existingFolder['path']) && str_starts_with($existingFolder['path'], $modellijnPath)) {
                    // Found existing folder
                    $subModellijnPath = $existingFolder['path'];
                    $pathCache[$subModellijnCacheKey] = $subModellijnPath;
                    $this->line("  ○ Sub-modellijn (exists): {$subModellijnName} -> {$subModellijnPath}");
                    return 'exists';
                } else {
                    // Need to create new folder
                    $subModellijnPath = $this->pimService->createSubModellijn($subModellijnName, $modellijnPath);
                    if (!$subModellijnPath) {
                        throw new \Exception("Failed to create Sub-modellijn folder: {$subModellijnName}");
                    }
                    $pathCache[$subModellijnCacheKey] = $subModellijnPath;
                    $this->line("  ✓ Sub-modellijn (created): {$subModellijnName} -> {$subModellijnPath}");
                    $wasCreated = true;
                }
            } else {
                $subModellijnPath = $pathCache[$subModellijnCacheKey];
                $this->line("  ↻ Sub-modellijn (cached): {$subModellijnName} -> {$subModellijnPath}");
                return 'exists';
            }

            return $wasCreated ? 'created' : 'exists';

        } catch (\Exception $e) {
            $this->error("  ✗ Failed to create structure for {$merkName} > {$modellijnName} > {$subModellijnName}: " . $e->getMessage());
            return 'failed';
        }
    }

    private function showSummary(): void
    {
        $this->newLine();
        $this->info('=== IMPORT SUMMARY ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $this->stats['processed']],
                ['Newly Created', $this->stats['created']],
                ['Already Exists', $this->stats['already_exists']],
                ['Failed', $this->stats['failed']],
                ['Skipped', $this->stats['skipped']]
            ]
        );

        if ($this->stats['failed'] > 0) {
            $this->warn('Some imports failed. Check the logs for details.');
            $this->info('Check storage/logs/laravel.log for detailed error information');
        } else {
            $this->info('All imports completed successfully!');
        }

        $this->newLine();
        $this->info('Architecture used:');
        $this->line('- Discovery API: Finding existing folders');
        $this->line('- PIM API: Creating new folders');
        $this->line('- Cache: Preventing duplicate API calls within session');
    }
}