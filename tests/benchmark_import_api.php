<?php
/**
 * Copyright 2011 Daniel Sloof
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once 'app/Mage.php';

/**
 * Give you bulks of entities calculated with NUM_ROWS_BY_CALL
 *
 * @param $entities
 *
 * @return array
 */
function getBulksOfEntities($entities)
{
    $bulks = array();
    if (count($entities) <= NUM_ROWS_BY_CALL || !NUM_ROWS_BY_CALL) {
        $bulks[] = $entities;
    } else {
        $bulks = array_chunk($entities, NUM_ROWS_BY_CALL);
    }

    return $bulks;
}

/**
 * Gives lines mapped to entity name
 * Each error returned by API Import is associate to the index of the line in the sent array.
 * This method provide a way to know to which entity is linked this index.
 * Returns [index => name, ...]
 *
 * @param array  $entities
 * @param string $entitiesType 'catalog_product', 'attributes', 'catalog_category'
 *
 * @return array|null
 */
function getIndexedEntities(array $entities, $entitiesType)
{
    // TODO add customer error management
    switch ($entitiesType) {
        case 'catalog_product':
            $indexToSearch = 'sku';
            break;
        case 'attributes':
            $indexToSearch = 'attribute_id';
            break;
        case 'catalog_category':
            $indexToSearch = 'name';
            break;
        default:
            return;
            break;
    }

    $indexedEntities = array();
    $previous        = '';
    foreach ($entities as $key => $entity) {
        if (isset($entity[$indexToSearch]) && !empty($entity[$indexToSearch])) {
            $indexedEntities[$key] = $entity[$indexToSearch];
            $previous              = $entity[$indexToSearch];
        } else {
            $indexedEntities[$key] = 'Associated to previous ' . $previous;
        }
    }

    return $indexedEntities;
}

/**
 * Get failed entities associated with error
 *
 * @param array $errors
 * @param array $indexedEntities
 *
 * @return array[entity => error]
 */
function getFailedEntities(array $errors, array $indexedEntities)
{
    $failedEntities = array();
    foreach ($errors as $error => $failedRows) {
        foreach ($failedRows as $row) {
            $failedEntities[$indexedEntities[$row]] = $error;
        }
    }

    return $failedEntities;
}

/**
 * Prints failed entities
 *
 * @param array $failedEntities
 *
 * @return void
 */
function printFailedEntities(array $failedEntities)
{
    foreach ($failedEntities as $entity => $error) {
        printf("$entity : $error" . PHP_EOL);
    }
}

Mage::init();

define('NUM_ENTITIES', 5000);
define('NUM_ROWS_BY_CALL', false);
define('API_USER', 'apiUser');
define('API_KEY', 'someApiKey123');
define('USE_API', true);
ini_set('memory_limit', '2048M');

$helper = Mage::helper('api_import/test');

if (USE_API) {
    // Create an API connection
    $soapOptions = array(
        'encoding'   => 'UTF-8',
        'trace'      => true,
        'exceptions' => true,
        'login'      => API_USER,
        'password'   => API_KEY,
        'cache_wsdl' => 3,
        'keep_alive' => 1
    );

    try {
        $client = new SoapClient(
            Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'index.php/api/soap/?wsdl',
            $soapOptions
        );
        $session = $client->login(API_USER, API_KEY);
    } catch (Exception $e) {
        echo 'Exception :' . $e->getMessage();
    }
}

$entityTypes = array(
    'product' => array(
        'entity' => Mage_ImportExport_Model_Export_Entity_Product::getEntityTypeCode(),
        'model'  => 'catalog/product',
        'types'  => array(
            'simple',
            'simpleFail',
            'configurable',
            'bundle',
            'grouped',
            'image',
            'localizable'
        ),
        'behavior' => 'append'
    ),
    'attributeSets' => array(
        'entity' => 'attributeSets',
        'types'  => array(
            'standard'
        ),
        'behavior' => 'append'
    ),
    'attributes' => array(
        'entity' => 'attributes',
        'types'  => array(
            'standard'
        ),
        'behavior' => 'append'
    ),
    'attributeAssociations' => array(
        'entity' => 'attributeAssociations',
        'types'  => array(
            'standard'
        ),
        'behavior' => 'append'
    ),
    'customer' => array(
        'entity' => Mage_ImportExport_Model_Export_Entity_Customer::getEntityTypeCode(),
        'model'  => 'customer/customer',
        'types'  => array(
            'standard'
        ),
        'behavior' => 'append'
    ),
    'category' => array(
        'entity' => Danslo_ApiImport_Model_Import_Entity_Category::getEntityTypeCode(),
        'model'  => 'catalog/category',
        'types'  => array(
            'standard'
        ),
        'behavior' => 'append'
    )
);

foreach ($entityTypes as $typeName => $entityType) {
    foreach ($entityType['types'] as $subType) {
        // Generation method depends on product type.
        printf('Generating %d %s %ss...' . PHP_EOL, NUM_ENTITIES, $subType, $typeName);
        $entities = $helper->{sprintf('generateRandom%s%s', ucfirst($subType), ucfirst($typeName))}(NUM_ENTITIES);

        // Attempt to import generated products.
        printf('Starting import...' . PHP_EOL);
        $totalTime = microtime(true);

        $typesRelatedToAttributes = array('attributeSets', 'attributes', 'attributeAssociations');
        $failedEntities = array();

        if (USE_API) {
            $bulks = getBulksOfEntities($entities);

            foreach ($bulks as $bulk) {
                try {
                    $indexedEntities = getIndexedEntities($bulk, $entityType['entity']);
                    if (in_array($entityType['entity'], $typesRelatedToAttributes)) {
                        $client->call(
                            $session,
                            'import.import' . ucfirst($entityType['entity']),
                            array(
                                $bulk,
                                $entityType['behavior']
                            )
                        );
                    } else {
                        $client->call($session, 'import.importEntities', array($bulk, $entityType['entity']));
                    }
                } catch (Exception $e) {
                    $errors = json_decode($e->getMessage(), true);

                    if (null !== $errors) {
                        $failedEntities = array_merge(
                            $failedEntities,
                            getFailedEntities($errors, $indexedEntities)
                        );
                    } else {
                        printf($e->getMessage() . PHP_EOL);
                    }
                }
            }
            if (!empty($failedEntities)) {
                printFailedEntities($failedEntities);
            }
        } else {
            try {
                $indexedEntities = getIndexedEntities($entities, $entityType['entity']);
                if (in_array($entityType['entity'], $typesRelatedToAttributes)) {
                    $method = 'import' . ucfirst($entityType['entity']);
                    Mage::getModel('api_import/import_api')->$method($entities, $entityType['behavior']);
                } else {
                    Mage::getModel('api_import/import_api')->importEntities($entities, $entityType['entity'], 'append');
                }
            } catch (Mage_Api_Exception $e) {
                $errors = json_decode($e->getCustomMessage(), true);
                $failedEntities = getFailedEntities($errors, $indexedEntities);
                printFailedEntities($failedEntities);
            } catch (Exception $e) {
                printf($e->getMessage() . PHP_EOL);
            }
        }
        printf('Done! Magento reports %d %s.' . PHP_EOL, count($entities), 'rows');
        $totalTime = microtime(true) - $totalTime;

        // Generate some rough statistics.
        printf('========== Import statistics ==========' . PHP_EOL);
        printf("Total duration:\t\t%fs"    . PHP_EOL, $totalTime);
        printf("Average per %s:\t%fs" . PHP_EOL, $typeName, $totalTime / NUM_ENTITIES);
        printf("%ss per second:\t%fs" . PHP_EOL, ucfirst($typeName), 1 / ($totalTime / NUM_ENTITIES));
        printf("%ss per hour:\t%fs"   . PHP_EOL, ucfirst($typeName), (60 * 60) / ($totalTime / NUM_ENTITIES));
        printf('=======================================' . PHP_EOL . PHP_EOL);
    }
}

// Cleanup.
if (USE_API) {
    $client->endSession($session);
}
