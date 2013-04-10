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
require_once dirname(__FILE__) . '/../../../app/bootstrap.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);

$entryPoint = new Mage_Core_Model_EntryPoint_Http(new Mage_Core_Model_Config_Primary(BP, $_SERVER));

define('NUM_ENTITIES', 100);
define('API_USER', 'apiUser');
define('API_KEY', 'someApiKey123');
define('USE_API', false);

$helper = Mage::helper('Danslo_ApiImport_Helper_Test');

if (USE_API) {
    // Create an API connection. Standard timeout for Zend_Http_Client is 10 seconds, so we must lengthen it.
    $client = new Zend_XmlRpc_Client(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'api/xmlrpc/');
    $client->getHttpClient()->setConfig(array('timeout' => -1));
    $session = $client->call('login', array(API_USER, API_KEY));
}

$entityTypes = array(
    'product' => array(
        'entity' => Mage_ImportExport_Model_Export_Entity_Product::getEntityTypeCode(),
        'model'  => 'Mage_Catalog_Model_Product',
        'types'  => array(
            'simple',
            'configurable',
            'bundle',
            'grouped'
        )
    ),
    'customer' => array(
        'entity' => Mage_ImportExport_Model_Import_Entity_CustomerComposite::getEntityTypeCode(),
        'model'  => 'Mage_Customer_Model_Customer',
        'types'  => array(
            'standard'
        )
    ),
    'category' => array(
        'entity' => Danslo_ApiImport_Model_Import_Entity_Category::getEntityTypeCode(),
        'model'  => 'Mage_Catalog_Model_Category',
        'types'  => array(
            'standard'
        )
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

        try {
            if (USE_API) {
                $client->call('call', array($session, 'import.importEntities', array($entities, $entityType['entity'])));
            } else {
                Mage::getModel('Danslo_ApiImport_Model_Import_Api')->importEntities($entities, $entityType['entity']);
            }
        }
        catch(Exception $e) {
            printf('Import failed: %s' . PHP_EOL, $e->getMessage());
            if (USE_API) {
                printf('Server returned: %s' . PHP_EOL, $client->getHttpClient()->getLastResponse()->getBody());
            }
            exit;
        }
        printf('Done! Magento reports %d %ss.' . PHP_EOL, Mage::getModel($entityType['model'])->getCollection()->count(), $typeName);
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
    $client->call('endSession', array($session));
}
