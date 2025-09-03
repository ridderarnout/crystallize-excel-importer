<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class CrystallizeDiscoveryService
{
    private Client $client;
    private string $apiUrl;
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

        $this->apiUrl = config('services.crystallize_discovery.api_url');
    }

    /**
     * Make a GraphQL request to Crystallize Discovery API
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
                    'X-Crystallize-Static-Auth-Token' => config('services.crystallize_discovery.access_token'),
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
            Log::error('Crystallize Discovery API request failed', [
                'error' => $e->getMessage(),
                'query' => $query,
                'variables' => $variables
            ]);
            throw new \Exception('API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Find a folder by name and shape, optionally scoped under a parent path
     */
    public function findFolderByNameAndShape(string $folderName, string $shape, string $parentPath = null): ?array
    {
        $cacheKey = "discovery_search:{$shape}:{$folderName}:{$parentPath}";

        if (isset($this->pathCache[$cacheKey])) {
            return $this->pathCache[$cacheKey];
        }

        $query = '
        query DiscoverySearchFolder($name: String!, $shape: String!) {
            search(
                language: nl
                term: ""
                pagination: {limit: 20} # fetch more to allow filtering in PHP
                filters: {name: {equals: $name}, shape: {equals: $shape}}
            ) {
                hits {
                    id
                    name
                    shape
                    path
                }
            }
        }
    ';

        try {
            $result = $this->graphqlRequest($query, [
                'name' => $folderName,
                'shape' => $shape
            ]);

            $folders = $result['search']['hits'] ?? [];

            // If parentPath is provided, filter by it
            if ($parentPath) {
                $folders = array_filter($folders, function ($folder) use ($parentPath) {
                    return str_starts_with($folder['path'], $parentPath);
                });
            }

            $folder = reset($folders) ?: null;

            if ($folder) {
                // Clean the ID by removing "-nl-published" suffix
                $folder['cleanId'] = preg_replace('/-nl-published$/', '', $folder['id']);
                Log::info("Found folder via Discovery API: {$folderName} at {$folder['path']}");
            }

            $this->pathCache[$cacheKey] = $folder;
            return $folder;
        } catch (\Exception $e) {
            Log::error("Error finding folder {$folderName} with shape {$shape} via Discovery API", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get folder path by ID using WorkatoQuery (your second query)
     */
    public function getFolderPath(string $folderId): ?string
    {
        $cacheKey = "discovery_path:{$folderId}";

        if (isset($this->pathCache[$cacheKey])) {
            return $this->pathCache[$cacheKey];
        }

        $query = '
            query WorkatoQuery($path: String!) {
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
            $result = $this->graphqlRequest($query, [
                'path' => $folderId
            ]);

            $folder = $result['search']['hits'][0] ?? null;
            $path = $folder['path'] ?? null;

            $this->pathCache[$cacheKey] = $path;
            return $path;
        } catch (\Exception $e) {
            Log::error("Error getting path for folder ID {$folderId} via Discovery API", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Search for folders by name pattern (more flexible search)
     */
    public function searchFolders(string $searchTerm, string $shape = null): array
    {
        $query = '
            query SearchFolders($term: String!, $shape: String) {
                search(
                    language: nl
                    term: $term
                    pagination: {limit: 10}
                    filters: {shape: {equals: $shape}}
                ) {
                    hits {
                        id
                        name
                        shape
                        path
                    }
                }
            }
        ';

        $variables = ['term' => $searchTerm];
        if ($shape) {
            $variables['shape'] = $shape;
        }

        try {
            $result = $this->graphqlRequest($query, $variables);
            $folders = $result['search']['hits'] ?? [];

            Log::info("Discovery search found " . count($folders) . " folders for term: {$searchTerm}");

            return $folders;
        } catch (\Exception $e) {
            Log::error("Error searching folders with term {$searchTerm}", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clear path cache
     */
    public function clearCache(): void
    {
        $this->pathCache = [];
        Log::info("Discovery API cache cleared");
    }
}