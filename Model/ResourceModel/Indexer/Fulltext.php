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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\CollectionFactory as EntityCollectionFactory;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\FilterableAttributeList;

/**
 * Resource Model for Custom Entity Layered Navigation Indexer.
 * * Handles the flat indexing of EAV attributes to enable high-performance
 * layered navigation SQL queries.
 */
class Fulltext
{
    /**
     * @var string Table prefix for custom entity EAV tables.
     */
    private const TABLE_PREFIX = 'smile_custom_entity_';

    /**
     * @var int Batch size for chunked database insertions.
     */
    private const BATCH_SIZE = 500;

    /**
     * @var array<string, string> Map backend types to specific table suffixes.
     */
    private const BACKEND_TABLE_MAP = [
        'int'      => 'int',
        'varchar'  => 'varchar',
        'text'     => 'text',
        'decimal'  => 'decimal',
        'datetime' => 'datetime',
    ];

    private string $mainTable = 'amadeco_custom_entity_index_eav_idx';
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
        $this->mainTable = $resourceConnection->getTableName($this->mainTable);
    }

    /**
     * Completely rebuild the index for all stores and active entities.
     *
     * @return void
     * @throws LocalizedException
     */
    public function reindexAll(): void
    {
        try {
            if (!$this->connection->isTableExists($this->mainTable)) {
                throw new LocalizedException(__('Index table does not exist: %1', $this->mainTable));
            }

            $this->connection->truncateTable($this->mainTable);
            $stores = $this->storeManager->getStores(true);
            $attributes = $this->getIndexableAttributes();

            if (empty($attributes)) {
                return;
            }

            $this->connection->beginTransaction();
            try {
                foreach ($stores as $store) {
                    $this->indexStore((int)$store->getId(), $attributes);
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw new LocalizedException(__('Failed to reindex all entities: %1', $e->getMessage()), $e);
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('An error occurred during full reindexing: %1', $e->getMessage()), $e);
        }
    }

    /**
     * Reindex specific entity IDs (Partial Reindex).
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
            $this->connection->delete($this->mainTable, ['entity_id IN (?)' => $entityIds]);
            $stores = $this->storeManager->getStores(true);
            $attributes = $this->getIndexableAttributes();

            if (empty($attributes)) {
                return;
            }

            $this->connection->beginTransaction();
            try {
                foreach ($stores as $store) {
                    $storeId = (int)$store->getId();
                    $indexedData = $this->fetchIndexData($storeId, $attributes, $entityIds);
                    $this->insertIndexData($indexedData);
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw new LocalizedException(__('Failed to reindex rows: %1', $e->getMessage()), $e);
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('An error occurred during partial reindexing: %1', $e->getMessage()), $e);
        }
    }

    /**
     * Process indexing for a specific store view using keyset pagination.
     */
    private function indexStore(int $storeId, array $attributes): void
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
                $id = (int)$entity->getId();
                $entityIds[] = $id;
                $lastEntityId = $id;
            }

            $indexedData = $this->fetchIndexData($storeId, $attributes, $entityIds);
            $this->insertIndexData($indexedData);
        }
    }

    /**
     * Fetch raw data from EAV tables with store-view scope fallback logic.
     * * @return array<int, array<string, mixed>>
     */
    private function fetchIndexData(int $storeId, array $attributes, array $entityIds): array
    {
        $indexData = [];
        $entityTable = $this->resourceConnection->getTableName('smile_custom_entity');
        $linkField = $this->getEntityLinkField();

        foreach ($attributes as $attribute) {
            $attributeId = (int)$attribute->getId();
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
     */
    private function processMultiselectRows(array $rows, int $attributeId, int $storeId, array &$indexData): void
    {
        $entityRawValues = [];
        foreach ($rows as $row) {
            // Store specific (later in loop) overrides default (earlier in loop)
            $entityRawValues[$row['entity_id']] = (string)$row['value'];
        }

        foreach ($entityRawValues as $entityId => $rawValue) {
            if ($rawValue === '') continue;

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
     */
    private function processRegularRows(array $rows, int $attributeId, int $storeId, array &$indexData): void
    {
        $processed = [];
        foreach ($rows as $row) {
            $processed[$row['entity_id']] = (string)$row['value'];
        }

        foreach ($processed as $entityId => $value) {
            if ($value !== '') {
                $indexData[] = $this->prepareRow($entityId, $attributeId, $storeId, $value);
            }
        }
    }

    /**
     * Internal helper to format index row.
     */
    private function prepareRow(int|string $entityId, int $attributeId, int $storeId, string $value): array
    {
        return [
            'entity_id'    => (int)$entityId,
            'attribute_id' => $attributeId,
            'store_id'     => $storeId,
            'value'        => $value
        ];
    }

    /**
     * Insert collected batches into the index table.
     */
    private function insertIndexData(array $indexData): void
    {
        if (empty($indexData)) return;

        foreach (array_chunk($indexData, self::BATCH_SIZE) as $batch) {
            $this->connection->insertMultiple($this->mainTable, $batch);
        }
    }

    /**
     * Get entity link field (usually entity_id or row_id for Enterprise).
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
     */
    private function getIndexableAttributes(): array
    {
        return $this->filterableAttributeList->getAllIndexableAttributes();
    }
}