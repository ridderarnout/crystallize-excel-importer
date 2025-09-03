<?php

namespace App\Console\Commands;

use App\Services\CrystallizePIMGraphQLService;
use Illuminate\Console\Command;

class DiscoverComponentInput extends Command
{
    protected $signature = 'discover:component-input';
    protected $description = 'Discover ComponentInput structure for PIM API';

    public function handle()
    {
        $service = app(CrystallizePIMGraphQLService::class);

        $query = '
            query {
                __type(name: "ComponentInput") {
                    inputFields {
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
        ';

        try {
            $this->info('=== ComponentInput STRUCTURE ===');
            $result = $service->graphqlRequest($query);
            foreach ($result['__type']['inputFields'] as $field) {
                $type = $field['type']['name'] ?: $field['type']['ofType']['name'];
                $this->line("- {$field['name']}: {$type}");
            }

        } catch (\Exception $e) {
            $this->error('ComponentInput discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}