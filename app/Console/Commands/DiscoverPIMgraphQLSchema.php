<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class DiscoverPIMgraphQLSchema extends Command
{
    protected $signature = 'discover:pim-schema';
    protected $description = 'Discover complete PIM API schema';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        // Get all Query fields
        $queryFieldsQuery = '
            query {
                __type(name: "Query") {
                    fields {
                        name
                        description
                        type {
                            name
                        }
                    }
                }
            }
        ';

        // Get all Mutation fields
        $mutationFieldsQuery = '
            query {
                __type(name: "Mutation") {
                    fields {
                        name
                        description
                        type {
                            name
                        }
                    }
                }
            }
        ';

        try {
            $this->info('=== QUERY FIELDS ===');
            $result = $service->graphqlRequest($queryFieldsQuery);
            foreach ($result['__type']['fields'] as $field) {
                $this->line("- {$field['name']}: {$field['description']} -> {$field['type']['name']}");
            }

            $this->newLine();
            $this->info('=== MUTATION FIELDS ===');
            $result = $service->graphqlRequest($mutationFieldsQuery);
            foreach ($result['__type']['fields'] as $field) {
                $this->line("- {$field['name']}: {$field['description']} -> {$field['type']['name']}");
            }

        } catch (\Exception $e) {
            $this->error('Schema discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}