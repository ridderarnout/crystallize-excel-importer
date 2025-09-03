<?php

namespace App\Console\Commands;

use App\Services\CrystallizePIMGraphQLService;
use Illuminate\Console\Command;

class DiscoverSingleLineInput extends Command
{
    protected $signature = 'discover:single-line-input';
    protected $description = 'Discover SingleLineContentInput structure';

    public function handle()
    {
        $service = app(CrystallizePIMGraphQLService::class);

        $query = '
            query {
                __type(name: "SingleLineContentInput") {
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
            $this->info('=== SingleLineContentInput STRUCTURE ===');
            $result = $service->graphqlRequest($query);
            foreach ($result['__type']['inputFields'] as $field) {
                $type = $field['type']['name'] ?: $field['type']['ofType']['name'];
                $this->line("- {$field['name']}: {$type}");
            }

        } catch (\Exception $e) {
            $this->error('SingleLineContentInput discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}