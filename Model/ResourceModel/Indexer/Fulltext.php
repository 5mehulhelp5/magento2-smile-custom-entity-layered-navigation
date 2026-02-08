<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @category  Amadeco
 * @package   Amadeco_SmileCustomEntityLayeredNavigation
 * @copyright Copyright (c) Amadeco (https://www.amadeco.fr) - Ilan Parmentier
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Indexer;

use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\FilterableAttributeList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\CollectionFactory as EntityCollectionFactory;

/**
 * Resource Model for Custom Entity Layered Navigation Indexer.
 *
 * Implements the "Copy and Swap" Mview strategy (inspired by Magento\CatalogRule).
 * Handles flat indexing of EAV attributes to enable high-performance layered navigation.
 */
class Fulltext
{
    /**
     * @var string Table prefix for custom entity EAV tables.
     */
    private const string TABLE_PREFIX = 'smile_custom_entity_';

    /**
     * @var int Batch size for chunked database insertions.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * @var array<string, string> Map backend types to specific table suffixes.
     */
    private const array BACKEND_TABLE_MAP = [
        'int'      => 'int',
        'varchar'  => 'varchar',
        'text'     => 'text',
        'decimal'  => 'decimal',
        'datetime' => 'datetime',
    ];

    /**
     * @var string The main index table name.
     */
    private string $mainTableName;

    /**
     * @var AdapterInterface Database Adapter.
     */
    private AdapterInterface $connection;

    /**
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param FilterableAttributeList $filterableAttributeList
     * @param EntityCollectionFactory $entityCollectionFactory
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        protected readonly ResourceConnection $resourceConnection,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly FilterableAttributeList $filterableAttributeList,
        protected readonly EntityCollectionFactory $entityCollectionFactory,
        protected readonly MetadataPool $metadataPool
    ) {
        $this->connection = $resourceConnection->getConnection();
        $this->mainTableName = $resourceConnection->getTableName('amadeco_custom_entity_index_eav_idx');
    }

    /**
     * Completely rebuild the index using "Copy and Swap" strategy.
     *
     * Ensures zero downtime and transaction safety using random table suffixes.
     *
     * @return void
     * @throws LocalizedException
     * @throws \Exception
     */
    public function reindexAll(): void
    {
        // 1. Generate random suffixes to avoid table name collisions
        $suffix = $this->getRandomSuffix();
        $tmpTableName = $this->resourceConnection->getTableName($this->mainTableName . '_tmp_' . $suffix);
        $backupTableName = $this->resourceConnection->getTableName($this->mainTableName . '_bak_' . $suffix);

        try {
            // 2. Prepare Temporary Table
            // Using createTableByDdl copies structure from live table.
            // Requires db_schema.xml to have NO Foreign Keys to avoid errno: 121.
            if ($this->connection->isTableExists($tmpTableName)) {
                $this->connection->dropTable($tmpTableName);
            }
            $tableObj = $this->connection->createTableByDdl($this->mainTableName, $tmpTableName);
            $this->connection->createTable($tableObj);

            // 3. Index Data into Temporary Table
            $this->connection->beginTransaction();
            try {
                // Pass false to getStores() to exclude Admin Store (0) and optimize index size.
                $stores = $this->storeManager->getStores(false);
                $attributes = $this->getIndexableAttributes();

                if (!empty($attributes)) {
                    foreach ($stores as $store) {
                        $storeId = (int) $store->getId();
                        $this->indexStore($storeId, $attributes, $tmpTableName);
                    }
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw $e;
            }

            // 4. Atomic Swap (Live -> Bak, Tmp -> Live)
            $this->swapTables($tmpTableName, $backupTableName);

        } catch (\Exception $e) {
            // Cleanup: Drop temp table on failure to save space
            if ($this->connection->isTableExists($tmpTableName)) {
                $this->connection->dropTable($tmpTableName);
            }
            throw new LocalizedException(
                __('An error occurred during full reindexing: %1', $e->getMessage()),
                $e
            );
        }
    }

    /**
     * Reindex specific entity IDs (Partial Reindex).
     *
     * Updates the live table directly.
     *
     * @param int[] $entityIds
     * @return void
     * @throws LocalizedException
     */
    public function reindexRows(array $entityIds): void
    {
        if (empty($entityIds)) {
            return;
        }

        try {
            $this->connection->beginTransaction();
            try {
                // Remove outdated entries for these entities
                $this->batchRowsDelete($this->mainTableName, $entityIds);

                $stores = $this->storeManager->getStores(false);
                $attributes = $this->getIndexableAttributes();

                if (!empty($attributes)) {
                    foreach ($stores as $store) {
                        $storeId = (int) $store->getId();
                        $indexedData = $this->fetchIndexData($storeId, $attributes, $entityIds);
                        $this->insertIndexData($indexedData, $this->mainTableName);
                    }
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw new LocalizedException(__('Failed to reindex rows: %1', $e->getMessage()), $e);
            }
        } catch (\Exception $e) {
            throw new LocalizedException(
                __('An error occurred during partial reindexing: %1', $e->getMessage()),
                $e
            );
        }
    }

    /**
     * Atomically swap the temporary table with the main table.
     *
     * @param string $tmpTableName
     * @param string $backupTableName
     * @return void
     */
    private function swapTables(string $tmpTableName, string $backupTableName): void
    {
        // Drop any stale backup table from previous failed runs
        if ($this->connection->isTableExists($backupTableName)) {
            $this->connection->dropTable($backupTableName);
        }

        // Rename logic:
        // 1. Current Live Table -> Backup Table
        // 2. New Temp Table -> Live Table
        $renameTables = [
            [
                'oldName' => $this->mainTableName,
                'newName' => $backupTableName
            ],
            [
                'oldName' => $tmpTableName,
                'newName' => $this->mainTableName
            ]
        ];

        // Execute Atomic Rename
        $this->connection->renameTablesBatch($renameTables);

        // Drop the backup table
        $this->connection->dropTable($backupTableName);
    }

    /**
     * Process indexing for a specific store view.
     *
     * @param int $storeId
     * @param CustomEntityAttributeInterface[] $attributes
     * @param string $targetTable
     * @return void
     */
    private function indexStore(int $storeId, array $attributes, string $targetTable): void
    {
        $lastEntityId = 0;

        while (true) {
            $collection = $this->entityCollectionFactory->create();
            $collection->addAttributeToSelect('entity_id');
            $collection->addAttributeToFilter('is_active', 1);
            $collection->addAttributeToFilter('entity_id', ['gt' => $lastEntityId]);
            $collection->setOrder('entity_id', Select::SQL_ASC);
            $collection->setPageSize(self::BATCH_SIZE);

            if ($collection->count() === 0) {
                break;
            }

            $entityIds = [];
            foreach ($collection as $entity) {
                $id = (int) $entity->getId();
                $entityIds[] = $id;
                $lastEntityId = $id;
            }

            $indexedData = $this->fetchIndexData($storeId, $attributes, $entityIds);
            $this->insertIndexData($indexedData, $targetTable);
        }
    }

    /**
     * Fetch raw data from EAV tables with store-view scope fallback logic.
     *
     * @param int $storeId
     * @param CustomEntityAttributeInterface[] $attributes
     * @param int[] $entityIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchIndexData(int $storeId, array $attributes, array $entityIds): array
    {
        $indexData = [];
        $entityTable = $this->resourceConnection->getTableName('smile_custom_entity');
        $linkField = $this->getEntityLinkField();

        foreach ($attributes as $attribute) {
            $attributeId = (int) $attribute->getId();
            $backendType = $attribute->getBackendType();

            if (!isset(self::BACKEND_TABLE_MAP[$backendType])) {
                continue;
            }

            $attributeTable = $this->resourceConnection->getTableName(
                self::TABLE_PREFIX . self::BACKEND_TABLE_MAP[$backendType]
            );

            if (!$this->connection->isTableExists($attributeTable)) {
                continue;
            }

            // Logic: Join attribute values for Store 0 AND current Store ID
            // The ORDER BY store_id ASC ensures that when we loop through results,
            // the Store View specific value overwrites the Admin value.
            $select = $this->connection->select()
                ->from(['e' => $entityTable], ['entity_id'])
                ->joinInner(
                    ['ea' => $attributeTable],
                    "e.{$linkField} = ea.{$linkField}",
                    ['value' => 'ea.value', 'store_id' => 'ea.store_id']
                )
                ->where('ea.attribute_id = ?', $attributeId)
                ->where('ea.store_id IN (?)', [0, $storeId])
                ->where('e.entity_id IN (?)', $entityIds)
                ->order('ea.store_id ' . Select::SQL_ASC);

            $rows = $this->connection->fetchAll($select);

            if (!empty($rows)) {
                $this->processAttributeRows($rows, $attribute, $attributeId, $storeId, $indexData);
            }
        }

        return $indexData;
    }

    /**
     * Route attribute processing based on frontend input type.
     *
     * @param array $rows
     * @param CustomEntityAttributeInterface $attribute
     * @param int $attributeId
     * @param int $storeId
     * @param array $indexData
     * @return void
     */
    private function processAttributeRows(
        array $rows,
        CustomEntityAttributeInterface $attribute,
        int $attributeId,
        int $storeId,
        array &$indexData
    ): void {
        if ($attribute->getFrontendInput() === 'multiselect') {
            $this->processMultiselectRows($rows, $attributeId, $storeId, $indexData);
        } else {
            $this->processRegularRows($rows, $attributeId, $storeId, $indexData);
        }
    }

    /**
     * Process multiselect attributes (exploding comma-separated values).
     *
     * @param array $rows
     * @param int $attributeId
     * @param int $storeId
     * @param array $indexData
     * @return void
     */
    private function processMultiselectRows(
        array $rows,
        int $attributeId,
        int $storeId,
        array &$indexData
    ): void {
        $entityRawValues = [];
        // Flatten rows: store-specific value overrides default (0)
        foreach ($rows as $row) {
            $entityRawValues[$row['entity_id']] = (string) $row['value'];
        }

        foreach ($entityRawValues as $entityId => $rawValue) {
            if ($rawValue === '') {
                continue;
            }
            // Explode CSV, Trim, and De-duplicate
            $values = array_unique(explode(',', $rawValue));
            foreach ($values as $val) {
                $trimVal = trim($val);
                if ($trimVal !== '') {
                    $indexData[] = $this->prepareRow($entityId, $attributeId, $storeId, $trimVal);
                }
            }
        }
    }

    /**
     * Process standard scalar attributes.
     *
     * @param array $rows
     * @param int $attributeId
     * @param int $storeId
     * @param array $indexData
     * @return void
     */
    private function processRegularRows(
        array $rows,
        int $attributeId,
        int $storeId,
        array &$indexData
    ): void {
        $processed = [];
        // Flatten rows: store-specific value overrides default (0)
        foreach ($rows as $row) {
            $processed[$row['entity_id']] = (string) $row['value'];
        }

        foreach ($processed as $entityId => $value) {
            if ($value !== '') {
                $indexData[] = $this->prepareRow($entityId, $attributeId, $storeId, $value);
            }
        }
    }

    /**
     * Internal helper to format index row.
     *
     * @param int|string $entityId
     * @param int $attributeId
     * @param int $storeId
     * @param string $value
     * @return array<string, mixed>
     */
    private function prepareRow(int|string $entityId, int $attributeId, int $storeId, string $value): array
    {
        return [
            'entity_id'    => (int) $entityId,
            'attribute_id' => $attributeId,
            'store_id'     => $storeId,
            'value'        => $value,
        ];
    }

    /**
     * Insert collected batches into the target index table.
     *
     * @param array $indexData
     * @param string $tableName
     * @return void
     */
    private function insertIndexData(array $indexData, string $tableName): void
    {
        if (empty($indexData)) {
            return;
        }
        foreach (array_chunk($indexData, self::BATCH_SIZE) as $batch) {
            $this->connection->insertMultiple($tableName, $batch);
        }
    }

    /**
     * Batch deletion strategy for row deletion.
     *
     * Avoids long transactions and locks by deleting in chunks.
     *
     * @param string $tableName
     * @param array $entityIds
     * @return void
     */
    private function batchRowsDelete(string $tableName, array $entityIds): void
    {
        while (!empty($entityIds)) {
            $batch = array_splice($entityIds, 0, self::BATCH_SIZE);
            $this->connection->delete($tableName, ['entity_id IN (?)' => $batch]);
        }
    }

    /**
     * Get entity link field (entity_id vs row_id).
     *
     * @return string
     */
    private function getEntityLinkField(): string
    {
        try {
            return $this->metadataPool->getMetadata(CustomEntityInterface::class)->getLinkField();
        } catch (\Exception) {
            return 'entity_id';
        }
    }

    /**
     * Get attributes configured as indexable.
     *
     * @return CustomEntityAttributeInterface[]
     */
    private function getIndexableAttributes(): array
    {
        return $this->filterableAttributeList->getAllIndexableAttributes();
    }

    /**
     * Generate a random 4-byte hex suffix for table names.
     *
     * @return string
     * @throws \Exception
     */
    private function getRandomSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }
}