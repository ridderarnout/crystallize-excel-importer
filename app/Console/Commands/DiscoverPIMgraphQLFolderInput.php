<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class DiscoverPIMgraphQLFolderInput extends Command
{
    protected $signature = 'discover:folder-input';
    protected $description = 'Discover CreateFolderInput structure';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        $query = '
            query {
                __type(name: "CreateFolderInput") {
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
            $this->info('=== CreateFolderInput STRUCTURE ===');
            $result = $service->graphqlRequest($query);
            foreach ($result['__type']['inputFields'] as $field) {
                $type = $field['type']['name'] ?: $field['type']['ofType']['name'];
                $this->line("- {$field['name']}: {$type}");
            }

        } catch (\Exception $e) {
            $this->error('Input discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}