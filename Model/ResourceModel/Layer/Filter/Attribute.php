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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter;

use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter\AbstractFilter;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Resource model for custom entity attribute filter operations.
 *
 * Handles the database-level application of filters to the collection.
 * Relies on the flattened Index Table.
 *
 * Refactored to remove FIND_IN_SET and optimize for flattened index structure.
 */
class Attribute extends AbstractDb
{
    /**
     * @var string Main index table alias suffix for filtering
     */
    private const string FILTER_TABLE_ALIAS_SUFFIX = '_idx';

    /**
     * @var string Main index table alias suffix for aggregation (counts)
     */
    private const string AGGREGATION_TABLE_ALIAS_SUFFIX = '_agg';

    /**
     * Attribute constructor.
     *
     * @param Context $context
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize connection and define main table.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('amadeco_custom_entity_index_eav_idx', 'entity_id');
    }

    /**
     * Apply attribute filter to entity collection.
     *
     * Adds an INNER JOIN to the collection's select object.
     * Strictly filters by the current Store ID.
     *
     * @param AbstractFilter $filter
     * @param int|string|array $value
     * @return $this
     */
    public function applyFilterToCollection(AbstractFilter $filter, mixed $value): static
    {
        $collection = $filter->getLayer()->getEntityCollection();
        $attribute = $filter->getAttributeModel();
        $connection = $this->getConnection();

        $tableAlias = $attribute->getAttributeCode() . self::FILTER_TABLE_ALIAS_SUFFIX;
        $storeId = (int) $collection->getStoreId();
        $attributeId = (int) $attribute->getAttributeId();

        // 1. Normalize Input: Always treat value as an array
        // Multiselects might come as CSV strings or arrays.
        // Flattened index allows us to use IN (?) for both select and multiselect.
        $valueArr = is_array($value) ? $value : explode(',', (string) $value);

        // Filter out empty strings to avoid invalid SQL IN () syntax
        $valueArr = array_filter($valueArr, fn($v) => (string) $v !== '');

        if (empty($valueArr)) {
            return $this;
        }

        // 2. Build Value Condition
        // Uses standard IN (?) clause which leverages the database index on the `value` column.
        $valueCondition = $connection->quoteInto("{$tableAlias}.value IN (?)", $valueArr);

        // 3. Build Join Conditions
        // We strictly check store_id = $storeId.
        $joinConditions = [
            "{$tableAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$tableAlias}.attribute_id = ?", $attributeId),
            $connection->quoteInto("{$tableAlias}.store_id = ?", $storeId),
            $valueCondition,
        ];

        $collection->getSelect()->join(
            [$tableAlias => $this->getMainTable()],
            implode(' AND ', $joinConditions),
            []
        );

        // 4. Optimization: Group By Entity ID to handle duplicates from one-to-many joins
        // This is crucial for multiselects where one entity might match multiple selected values.
        $collection->getSelect()->group('e.entity_id');

        return $this;
    }

    /**
     * Retrieve array with entity counts per attribute option.
     *
     * @param AbstractFilter $filter
     * @return array<string, string|int>
     */
    public function getCount(AbstractFilter $filter): array
    {
        // 1. Clone Select to isolate count query
        $select = clone $filter->getLayer()->getEntityCollection()->getSelect();

        // 2. Clean up Select for Aggregation
        $select->reset(Select::COLUMNS);
        $select->reset(Select::ORDER);
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);
        $select->reset(Select::GROUP);

        // 3. Remove "Self-Filter" (Multiselect logic)
        // If we are filtering by "Red", we still want to see counts for "Blue"
        // in the same attribute filter block.
        $filterAlias = $filter->getAttributeModel()->getAttributeCode() . self::FILTER_TABLE_ALIAS_SUFFIX;
        $fromPart = $select->getPart(Select::FROM);

        if (isset($fromPart[$filterAlias])) {
            unset($fromPart[$filterAlias]);
            $select->setPart(Select::FROM, $fromPart);
        }

        // 4. Join Aggregation Table
        $connection = $this->getConnection();
        $attribute = $filter->getAttributeModel();
        $aggAlias = $attribute->getAttributeCode() . self::AGGREGATION_TABLE_ALIAS_SUFFIX;
        $storeId = (int) $filter->getStoreId();
        $attributeId = (int) $attribute->getAttributeId();

        $conditions = [
            "{$aggAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$aggAlias}.attribute_id = ?", $attributeId),
            $connection->quoteInto("{$aggAlias}.store_id = ?", $storeId),
        ];

        $select->join(
            [$aggAlias => $this->getMainTable()],
            implode(' AND ', $conditions),
            [
                'value',
                'count' => new Expression("COUNT(DISTINCT {$aggAlias}.entity_id)")
            ]
        )->group(
            "{$aggAlias}.value"
        );

        return $connection->fetchPairs($select);
    }
}