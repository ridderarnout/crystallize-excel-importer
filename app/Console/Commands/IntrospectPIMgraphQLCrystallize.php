<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class IntrospectPIMgraphQLCrystallize extends Command
{
    protected $signature = 'introspect:pim';
    protected $description = 'Discover PIM API schema';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        // Check search query structure
        $searchQuery = '
            query {
                __type(name: "Query") {
                    fields {
                        name
                        args {
                            name
                            type {
                                name
                            }
                        }
                    }
                }
            }
        ';

        try {
            $this->info('Discovering Query fields...');
            $result = $service->graphqlRequest($searchQuery);

            foreach ($result['__type']['fields'] as $field) {
                if ($field['name'] === 'search') {
                    $this->info("Search field arguments:");
                    foreach ($field['args'] as $arg) {
                        $this->line("  - {$arg['name']}: {$arg['type']['name']}");
                    }
                    break;
                }
            }
        } catch (\Exception $e) {
            $this->error('Schema discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}