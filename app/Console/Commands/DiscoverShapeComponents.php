<?php

namespace App\Console\Commands;

use App\Services\CrystallizePIMGraphQLService;
use Illuminate\Console\Command;

class DiscoverShapeComponents extends Command
{
    protected $signature = 'discover:shape-components {shape}';
    protected $description = 'Discover components for a specific shape';

    public function handle()
    {
        $service = app(CrystallizePIMGraphQLService::class);
        $shapeIdentifier = $this->argument('shape');

        $query = '
            query GetShape($identifier: String!, $tenantId: ID!) {
                shape(identifier: $identifier, tenantId: $tenantId) {
                    identifier
                    name
                    components {
                        id
                        name
                        type
                    }
                }
            }
        ';

        try {
            $this->info("=== COMPONENTS FOR SHAPE: {$shapeIdentifier} ===");
            $result = $service->graphqlRequest($query, [
                'identifier' => $shapeIdentifier,
                'tenantId' => config('services.crystallize_pim.tenant_id')
            ]);

            if ($result['shape']) {
                $shape = $result['shape'];
                $this->line("Shape name: {$shape['name']}");
                $this->line("Components:");
                foreach ($shape['components'] as $component) {
                    $this->line("  - {$component['id']}: {$component['name']} ({$component['type']})");
                }
            } else {
                $this->error("Shape {$shapeIdentifier} not found");
            }

        } catch (\Exception $e) {
            $this->error('Shape discovery failed: ' . $e->getMessage());
        }

        return 0;
    }
}