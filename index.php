<?php

########## Cache handling ##########

/** @var callable $getCacheName Return the name of the cache file */
$getCacheName = fn(): string => __DIR__ . '/store.cache';

// If store is cached, return from cache
$store = fromCache($getCacheName);
if (!empty($store)) {
    header('X-From-Cache: 1');
    die(setHeaders($store));
}

########## File parsing ##########

/** @var $store - JSON schema store */
$store = [
    '$schema' => 'https://json.schemastore.org/schema-catalog.json',
    'version' => 1,
    'schemas' => [],
];

$jsonFiles = glob(__DIR__ . '/schemas/**/*.schema.json');

// No files found. Exit with empty schemas
if (empty($jsonFiles)) {
    die(toCache(json_encode($store), $getCacheName));
}

// Add each found JSON schema to the store.
foreach ($jsonFiles as $jsonFile) {
    addSchema($jsonFile, $store['schemas']);
}
die(toCache(json_encode($store), $getCacheName));


########## Functions ##########

function fromCache(callable $getCacheName): ?string
{
    if (!file_exists($getCacheName())) {
        return null;
    }
    $cacheContent = file_get_contents($getCacheName());
    if (empty($cacheContent)) {
        return null;
    }
    $cachedStore = unserialize($cacheContent);
    if (
        !is_array($cachedStore) || empty($cachedStore['cacheTime'] ?? null) || empty($cachedStore['store'] ?? null)
    ) {
        return null;
    }
    // Cache for 5 Minutes
    if ((time() - $cachedStore['cacheTime']) > 300) {
        return null;
    }

    return $cachedStore['store'];
}

/**
 * Add a schema to an array of schemas. Schemas must contain the keys **$schema, $id, version** and **fileMatch** or
 * else they'll be skipped.
 *
 * @param string $fileName Absolute file name of JSON schema from glob()
 * @param array  $schemas  Array of JSON schemas to add the schema to.
 *
 * @return void
 */
function addSchema(string $fileName, array &$schemas): void
{
    $fileContent = json_decode(file_get_contents($fileName), true);
    if (empty($fileContent)) {
        return;
    }
    $schema = parseSchema($fileContent);
    if (empty($schema)) {
        return;
    }
    $schemas[] = parseSchema($fileContent);
}

/**
 * Parse an array for required schema keys and return an array with that data for the JSON catalog.
 *
 * @param array $schema Array concerted from JSON schema
 *
 * @return array|null
 */
function parseSchema(array $schema): ?array
{
    if (!isset($schema['$id'], $schema['$schema'], $schema['version'], $schema['fileMatch'])) {
        return null;
    }

    return [
        'name' => $schema['title'] ?? '',
        'description' => $schema['description'] ?? '',
        'fileMatch' => $schema['fileMatch'],
        'url' => $schema['$id'],
    ];
}

/**
 * Save the store to a cache file, set HTTP headers and return the store.
 *
 * @param string   $store        Store to cache
 * @param callable $getCacheName returns the name of the cache file
 *
 * @return string
 */
function toCache(string $store, callable $getCacheName): string
{
    file_put_contents($getCacheName(), serialize([
        'cacheTime' => time(),
        'store' => $store
    ]));

    return setHeaders($store);
}

/**
 * Set HTTP headers and return $store
 *
 * @param string $store JSON schema store. Only used to calculate Content-Length
 *
 * @return string
 */
function setHeaders(string $store): string
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($store));

    return $store;
}