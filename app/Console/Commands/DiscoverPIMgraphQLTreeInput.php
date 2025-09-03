<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class DiscoverPIMgraphQLTreeInput extends Command
{
    protected $signature = 'discover:tree-input';
    protected $description = 'Discover TreeNodeInput structure';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        $query = '
            query {
                __type(name: "TreeNodeInput") {
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
            $this->info('=== TreeNodeInput STRUCTURE ===');
            $result = $service->graphqlRequest($query);
            foreach ($result['__type']['inputFields'] as $field) {
                $type = $field['type']['name'] ?: $field['type']['ofType']['name'];
                $this->line("- {$field['name']}: {$type}");
            }

        } catch (\Exception $e) {
            $this->error('Tree input discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}