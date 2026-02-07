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
 * Handles the database-level application of filters to the collection and
 * calculates counts for faceted navigation.
 */
class Attribute extends AbstractDb
{
    /**
     * @var string Main index table alias for filtering
     */
    private const FILTER_TABLE_ALIAS_SUFFIX = '_idx';

    /**
     * @var string Main index table alias for aggregation (counts)
     */
    private const AGGREGATION_TABLE_ALIAS_SUFFIX = '_agg';

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
     * Adds an INNER JOIN to the collection's select object based on the provided values.
     * Supports both single (select) and multiple (multiselect) values.
     *
     * @param AbstractFilter $filter
     * @param int|string|array $value
     * @return $this
     */
    public function applyFilterToCollection(AbstractFilter $filter, mixed $value): static
    {
        $collection = $filter->getLayer()->getEntityCollection();
        $attribute  = $filter->getAttributeModel();
        $connection = $this->getConnection();
        $tableAlias = $attribute->getAttributeCode() . self::FILTER_TABLE_ALIAS_SUFFIX;

        $valueCondition = is_array($value)
            ? $connection->quoteInto("{$tableAlias}.value IN (?)", $value)
            : $connection->quoteInto("{$tableAlias}.value = ?", $value);

        $conditions = [
            "{$tableAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$tableAlias}.attribute_id = ?", (int) $attribute->getAttributeId()),
            $connection->quoteInto("{$tableAlias}.store_id = ?", (int) $collection->getStoreId()),
            $valueCondition,
        ];

        $collection->getSelect()->join(
            [$tableAlias => $this->getMainTable()],
            implode(' AND ', $conditions),
            []
        );

        $collection->getSelect()->distinct(true);

        return $this;
    }

    /**
     * Retrieve array with entity counts per attribute option.
     *
     * Clones the current collection select to maintain context while removing
     * the current filter to allow for "OR" faceted navigation counts.
     *
     * @param AbstractFilter $filter
     * @return array<string, string|int>
     */
    public function getCount(AbstractFilter $filter): array
    {
        // 1. Clone the current collection to keep context (Category, Search, etc.)
        $select = clone $filter->getLayer()->getEntityCollection()->getSelect();

        // 2. Reset query structure parts that are irrelevant for aggregation
        $select->reset(Select::COLUMNS);
        $select->reset(Select::ORDER);
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);
        $select->reset(Select::GROUP);

        // 3. Multiselect Logic: "Exclude Self"
        // Remove the existing filter for this attribute so we can see counts for other options.
        $filterAlias = $filter->getAttributeModel()->getAttributeCode() . self::FILTER_TABLE_ALIAS_SUFFIX;
        $fromPart    = $select->getPart(Select::FROM);

        if (isset($fromPart[$filterAlias])) {
            unset($fromPart[$filterAlias]);
            $select->setPart(Select::FROM, $fromPart);
        }

        // 4. Join for Aggregation
        $connection = $this->getConnection();
        $attribute  = $filter->getAttributeModel();
        $aggAlias   = $attribute->getAttributeCode() . self::AGGREGATION_TABLE_ALIAS_SUFFIX;

        $conditions = [
            "{$aggAlias}.entity_id = e.entity_id",
            $connection->quoteInto("{$aggAlias}.attribute_id = ?", (int) $attribute->getAttributeId()),
            $connection->quoteInto("{$aggAlias}.store_id = ?", (int) $filter->getStoreId()),
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