<?php

namespace App\Console\Commands;

use App\Services\CrystallizeService;
use Illuminate\Console\Command;

class DiscoverPIMgraphQLFolderAPI extends Command
{
    protected $signature = 'discover:folder-api';
    protected $description = 'Discover folder query and mutation structure';

    public function handle()
    {
        $service = app(CrystallizeService::class);

        // Discover folder query structure
        $folderQueryQuery = '
            query {
                __type(name: "Query") {
                    fields(includeDeprecated: false) {
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
                        type {
                            name
                            fields {
                                name
                                type {
                                    name
                                }
                            }
                        }
                    }
                }
            }
        ';

        // Discover FolderMutations structure
        $folderMutationQuery = '
            query {
                __type(name: "FolderMutations") {
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
            $this->info('=== FOLDER QUERY STRUCTURE ===');
            $result = $service->graphqlRequest($folderQueryQuery);
            foreach ($result['__type']['fields'] as $field) {
                if ($field['name'] === 'folder') {
                    $this->line("folder query arguments:");
                    foreach ($field['args'] as $arg) {
                        $type = $arg['type']['name'] ?: $arg['type']['ofType']['name'];
                        $this->line("  - {$arg['name']}: {$type}");
                    }
                    break;
                }
            }

            $this->newLine();
            $this->info('=== FOLDER MUTATIONS ===');
            $result = $service->graphqlRequest($folderMutationQuery);
            foreach ($result['__type']['fields'] as $field) {
                $this->line("- {$field['name']}");
                foreach ($field['args'] as $arg) {
                    $type = $arg['type']['name'] ?: $arg['type']['ofType']['name'];
                    $this->line("    - {$arg['name']}: {$type}");
                }
            }

        } catch (\Exception $e) {
            $this->error('API discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}