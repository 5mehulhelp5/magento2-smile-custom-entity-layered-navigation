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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter;

use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter\ItemFactory;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter\Attribute as AttributeResource;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\ResourceModel\Layer\Filter\AttributeFactory as AttributeResourceFactory;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filter\StripTags;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\StoreManagerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;

/**
 * Layer Attribute Filter.
 *
 * Handles filtering logic for custom entity attributes (select, multiselect).
 * It acts as the bridge between the Layer (Controller/State) and the ResourceModel (Database).
 */
class Attribute extends AbstractFilter
{
    /**
     * @var AttributeResource
     */
    private AttributeResource $resource;

    /**
     * @param ItemFactory $filterItemFactory
     * @param StoreManagerInterface $storeManager
     * @param Layer $layer
     * @param DataBuilder $itemDataBuilder
     * @param AttributeFactory $filterAttributeFactory
     * @param StringUtils $stringUtil
     * @param StripTags $tagFilter
     * @param array $data
     */
    public function __construct(
        ItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        DataBuilder $itemDataBuilder,
        AttributeResourceFactory $resourceFactory,
        protected readonly StringUtils $stringUtil,
        protected readonly StripTags $tagFilter,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $data
        );
        $this->resource = $resourceFactory->create();

        $attribute = $this->getAttributeModel();
        $this->_requestVar = $attribute->getAttributeCode();
    }

    /**
     * Retrieve resource instance
     *
     * @return AttributeResource
     */
    protected function _getResource()
    {
        return $this->resource;
    }

    /**
     * Apply the filter to the collection.
     *
     * Retrieves the filter value from the request, validates it, applied it to the resource model,
     * and updates the Layer State.
     *
     * @param RequestInterface $request
     * @return $this
     * @throws LocalizedException
     */
    public function apply(RequestInterface $request): static
    {
        // 1. Get the filter value from the request using the attribute code
        $filter = $request->getParam($this->_requestVar);
        if (is_array($filter)) {
            return $this;
        }

        $text = $this->getOptionText($filter);
        if ($filter && $this->stringUtil->strlen($text)) {
            $this->_getResource()->applyFilterToCollection($this, $filter);
            $this->getLayer()->getState()->addFilter($this->_createItem($text, $filter));
            $this->_items = [];
        }

        return $this;
    }

    /**
     * Get data array for building attribute filter items.
     *
     * Retrieves options from the attribute source, gets counts from the resource model,
     * and builds the data array for the frontend.
     *
     * @return array<int, array<string, mixed>>
     * @throws LocalizedException
     */
    protected function _getItemsData(): array
    {
        $attribute = $this->getAttributeModel();
        $options = $attribute->getFrontend()->getSelectOptions();
        $optionsCount = $this->_getResource()->getCount($this);
        foreach ($options as $option) {
            if (is_array($option['value'])) {
                continue;
            }
            if ($this->stringUtil->strlen($option['value'])) {
                // Check filter type
                if ($this->getAttributeIsFilterable($attribute) === self::ATTRIBUTE_OPTIONS_ONLY_WITH_RESULTS) {
                    if (!empty($optionsCount[$option['value']])) {
                        $this->itemDataBuilder->addItemData(
                            $this->tagFilter->filter($option['label']),
                            $option['value'],
                            $optionsCount[$option['value']]
                        );
                    }
                } else {
                    $this->itemDataBuilder->addItemData(
                        $this->tagFilter->filter($option['label']),
                        $option['value'],
                        $optionsCount[$option['value']] ?? 0
                    );
                }
            }
        }

        return $this->itemDataBuilder->build();
    }
}