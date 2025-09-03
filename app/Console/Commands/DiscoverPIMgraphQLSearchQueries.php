<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class DiscoverPIMgraphQLSearchQueries extends Command
{
    protected $signature = 'discover:search-queries';
    protected $description = 'Discover SearchQueries structure';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        $query = '
            query {
                __type(name: "SearchQueries") {
                    fields {
                        name
                        args {
                            name
                            type {
                                name
                                ofType {
                                    name
                                }
                            }
                        }
                    }
                }
            }
        ';

        try {
            $this->info('=== SearchQueries FIELDS ===');
            $result = $service->graphqlRequest($query);
            foreach ($result['__type']['fields'] as $field) {
                $this->line("- {$field['name']}");
                foreach ($field['args'] as $arg) {
                    $type = $arg['type']['name'] ?: $arg['type']['ofType']['name'];
                    $this->line("    - {$arg['name']}: {$type}");
                }
            }

        } catch (\Exception $e) {
            $this->error('Search queries discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}