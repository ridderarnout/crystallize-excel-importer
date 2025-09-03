<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class CrystallizePIMGraphQLService
{
    private Client $client;
    private string $apiUrl;
    private string $tenantId;
    private array $pathCache = [];

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);

        $this->apiUrl = config('services.crystallize_pim.api_url');
        $this->tenantId = config('services.crystallize_pim.tenant_id');
    }

    /**
     * Make a GraphQL request to Crystallize PIM API
     */
    public function graphqlRequest(string $query, array $variables = []): array
    {
        try {
            $payload = [
                'query' => $query
            ];

            // Only add variables if they exist and are not empty
            if (!empty($variables)) {
                $payload['variables'] = $variables;
            }

            $response = $this->client->post($this->apiUrl, [
                'headers' => [
                    'X-Crystallize-Access-Token-Id' => config('services.crystallize_pim.access_token_id'),
                    'X-Crystallize-Access-Token-Secret' => config('services.crystallize_pim.access_token_secret'),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['errors'])) {
                throw new \Exception('GraphQL Error: ' . json_encode($data['errors']));
            }

            return $data['data'];
        } catch (GuzzleException $e) {
            Log::error('Crystallize PIM API request failed', [
                'error' => $e->getMessage(),
                'query' => $query,
                'variables' => $variables
            ]);
            throw new \Exception('API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Create folder in Crystallize PIM using the correct API structure
     */
    public function createFolder(string $name, string $parentPath, string $shapeIdentifier): ?array
    {
        $mutation = '
            mutation CreateFolder($input: CreateFolderInput!) {
                folder {
                    create(
                        disableComponentValidation: false
                        input: $input
                        language: "nl"
                    ) {
                        id
                        name
                    }
                }
            }
        ';

        // Get parent ID from path if not root
        $parentId = null;
        if ($parentPath !== '/') {
            $parentId = $this->getParentIdFromPath($parentPath);
            if (!$parentId) {
                Log::error("Could not find parent ID for path: {$parentPath}");
                return null;
            }
        }

        $input = [
            'name' => $name,
            'shapeIdentifier' => $shapeIdentifier,
            'tenantId' => $this->tenantId
        ];

        // Add tree structure if we have a parent
        if ($parentId) {
            $input['tree'] = [
                'parentId' => $parentId
            ];
        }

        try {
            $result = $this->graphqlRequest($mutation, ['input' => $input]);

            $createdFolder = $result['folder']['create'];
            Log::info("Created folder: {$name} with ID {$createdFolder['id']}");

            return $createdFolder;
        } catch (\Exception $e) {
            Log::error("Failed to create folder {$name}", [
                'parentPath' => $parentPath,
                'shapeIdentifier' => $shapeIdentifier,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Publish a folder in both languages
     */
    public function publishFolder(string $folderId): bool
    {
        $mutation = '
            mutation PublishFolder($id: ID!, $language: String!) {
                folder {
                    publish(id: $id, language: $language) {
                        id
                    }
                }
            }
        ';

        try {
            // Publish in Dutch
            $this->graphqlRequest($mutation, [
                'id' => $folderId,
                'language' => 'nl'
            ]);

            // Publish in English
            $this->graphqlRequest($mutation, [
                'id' => $folderId,
                'language' => 'en'
            ]);

            Log::info("Published folder {$folderId} in both languages");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to publish folder {$folderId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get or create a Merk folder (uses Discovery to find, PIM to create)
     */
    public function ensureMerkFolder(string $merkName): ?string
    {
        $discoveryService = app(\App\Services\CrystallizeDiscoveryService::class);
        $folder = $discoveryService->findFolderByNameAndShape($merkName, 'merk');

        if ($folder && isset($folder['path'])) {
            Log::info("Found existing Merk folder via Discovery: {$merkName} at {$folder['path']}");

            // 🛡️ Safety: Always return path, never ID
            if (!str_starts_with($folder['path'], '/')) {
                Log::warning("Discovery returned invalid path for Merk {$merkName}, falling back to expected slug");
                return '/' . $this->generateSlug($merkName);
            }

            return $folder['path'];
        }

        // Create new Merk folder using PIM API at root level
        Log::info("Creating Merk folder via PIM: {$merkName}");
        $createdFolder = $this->createFolder($merkName, '/', 'merk');

        if ($createdFolder) {
            $this->publishFolder($createdFolder['id']);

            // Build the expected path immediately
            $expectedPath = '/' . $this->generateSlug($merkName);

            // Optional: Verify with Discovery API after a short delay
            sleep(1);
            $newFolder = $discoveryService->findFolderByNameAndShape($merkName, 'merk');

            // 🛡️ Safety: Always return path if available, otherwise fallback
            $safePath = $newFolder['path'] ?? $expectedPath;

            if (!str_starts_with($safePath, '/')) {
                Log::warning("Discovery returned invalid path for new Merk {$merkName}, using expected slug");
                $safePath = $expectedPath;
            }

            Log::debug("ensureMerkFolder returning new path", [
                'merkName' => $merkName,
                'returning' => $safePath,
                'newFolder_id' => $newFolder['id'] ?? 'no_id',
                'createdFolder_id' => $createdFolder['id']
            ]);

            return $safePath;
        }

        return null;
    }

    /**
     * Get or create a Modellijn folder (uses Discovery to find, PIM to create)
     */
    public function ensureModellijnFolder(string $modellijnName, string $merkPath): ?string
    {
        $discoveryService = app(\App\Services\CrystallizeDiscoveryService::class);

        // 🛡️ SAFETY CHECK: If merkPath looks like an ID instead of a path, resolve it
        if (!str_starts_with($merkPath, '/')) {
            Log::warning("ensureModellijnFolder received merkPath as ID instead of path", [
                'given' => $merkPath
            ]);

            $resolvedPath = $discoveryService->getFolderPath($merkPath);
            if ($resolvedPath) {
                Log::info("Resolved merkPath ID {$merkPath} to path {$resolvedPath}");
                $merkPath = $resolvedPath;
            } else {
                Log::error("Failed to resolve merkPath ID to path", ['merkPath' => $merkPath]);
                return null;
            }
        }

        $folder = $discoveryService->findFolderByNameAndShape(
            $modellijnName,
            'modellijn',
            $merkPath // <-- scope to correct merk
        );

        if ($folder && isset($folder['path'])) {
            Log::info("Found existing Modellijn folder via Discovery: {$modellijnName} at {$folder['path']}");
            return $folder['path'];
        }

        // Create new Modellijn folder using PIM API
        Log::info("Creating Modellijn folder via PIM: {$modellijnName} under {$merkPath}");
        $createdFolder = $this->createFolder($modellijnName, $merkPath, 'modellijn');

        if ($createdFolder) {
            $this->publishFolder($createdFolder['id']);

            // Build the expected path immediately
            $expectedPath = $merkPath . '/' . $this->generateSlug($modellijnName);

            // Optional: Verify with Discovery API after a short delay
            sleep(1);
            $newFolder = $discoveryService->findFolderByNameAndShape(
                $modellijnName,
                'modellijn',
                $merkPath
            );

            // Return Discovery path if available, otherwise use expected path
            return $newFolder['path'] ?? $expectedPath;
        }

        return null;
    }

    /**
     * Create a Sub-modellijn folder (uses Discovery to check, PIM to create)
     */
    /**
     * Create a Sub-modellijn folder (uses Discovery to check, PIM to create)
     */
    public function createSubModellijn(string $subModellijnName, string $modellijnPath): ?string
    {
        Log::info("Creating Sub-modellijn via PIM: {$subModellijnName} under {$modellijnPath}");

        // Check if it already exists using Discovery API (scoped by parent path)
        $discoveryService = app(\App\Services\CrystallizeDiscoveryService::class);
        $existingFolder = $discoveryService->findFolderByNameAndShape(
            $subModellijnName,
            'sub-modellijn',
            $modellijnPath
        );

        if ($existingFolder && isset($existingFolder['path'])) {
            return $existingFolder['path'];
        }

        // Create new Sub-modellijn folder using the correct shape identifier
        $createdFolder = $this->createFolder($subModellijnName, $modellijnPath, 'sub-modellijn');

        if ($createdFolder) {
            $this->publishFolder($createdFolder['id']);
            // Return the path immediately without waiting for Discovery API
            return $modellijnPath . '/' . $this->generateSlug($subModellijnName);
        }

        Log::error("Failed to create sub-modellijn folder: {$subModellijnName}");
        return null;
    }
    /**
     * Generate a URL-friendly slug from a string
     */
    private function generateSlug(string $text): string
    {
        // Convert to lowercase
        $slug = strtolower($text);

        // Convert accented characters to ASCII equivalents
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

        // Remove or replace any remaining special characters with hyphens
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);

        // Remove multiple consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug);

        // Remove leading and trailing hyphens
        $slug = trim($slug, '-');

        // Handle empty slug
        if (empty($slug)) {
            $slug = 'unnamed';
        }

        return $slug;
    }

    /**
     * Get parent folder ID from path using Discovery API
     */
    private function getParentIdFromPath(string $path): ?string
    {
        $discoveryService = app(\App\Services\CrystallizeDiscoveryService::class);

        // Use Discovery API to search for the parent folder by path
        $query = '
            query FindFolderByPath($path: String!) {
                search(
                    path: $path
                    options: {}
                    pagination: {limit: 1}
                ) {
                    hits {
                        id
                        name
                        path
                    }
                }
            }
        ';

        try {
            $result = $discoveryService->graphqlRequest($query, ['path' => $path]);
            $folder = $result['search']['hits'][0] ?? null;

            if ($folder) {
                // Clean the ID by removing "-nl-published" suffix
                return preg_replace('/-nl-published$/', '', $folder['id']);
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error finding parent ID for path {$path}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Add delay to prevent rate limiting
     */
    public function addDelay(int $milliseconds = 100): void
    {
        usleep($milliseconds * 1000);
    }
}