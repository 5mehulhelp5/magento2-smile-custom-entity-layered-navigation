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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer;

use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Smile\CustomEntity\Model\CustomEntity\Attribute;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Layer\Filter\AbstractFilter;

/**
 * Filter List Model for Custom Entity Layered Navigation.
 *
 * Manages the available attribute filters for the current layer.
 * Filter types are injected via di.xml based on frontend_input type.
 */
class FilterList implements ResetAfterRequestInterface
{
    /**
     * @var AbstractFilter[]
     */
    private array $filters = [];

    /**
     * @var string[]
     */
    private array $filterTypes;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param FilterableAttributeList $filterableAttributes
     * @param array $filters Filter types configuration (injected via di.xml)
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly FilterableAttributeList $filterableAttributes,
        array $filters = []
    ) {
        $this->filterTypes = $filters;
    }

    /**
     * Retrieve list of filters
     *
     * @param Layer $layer
     * @return AbstractFilter[]
     */
    public function getFilters(Layer $layer): array
    {
        if (!count($this->filters)) {
            // Passing $layer to getList ensures attributes are filtered by the current Attribute Set ID
            foreach ($this->filterableAttributes->getList($layer) as $attribute) {
                $this->filters[] = $this->createAttributeFilter($attribute, $layer);
            }
        }
        return $this->filters;
    }

    /**
     * Create filter instance
     *
     * @param Attribute $attribute
     * @param Layer $layer
     * @return AbstractFilter
     */
    protected function createAttributeFilter(Attribute $attribute, Layer $layer): AbstractFilter
    {
        $filterClassName = $this->getAttributeFilterClass($attribute);

        return $this->objectManager->create(
            $filterClassName,
            [
                'data' => ['attribute_model' => $attribute],
                'layer' => $layer
            ]
        );
    }

    /**
     * Get Attribute Filter Class Name based on frontend input type
     *
     * @param Attribute $attribute
     * @return string
     */
    protected function getAttributeFilterClass(Attribute $attribute): string
    {
        $frontendInput = $attribute->getFrontendInput();

        // Use the specific class mapped to the input type (e.g. 'boolean', 'multiselect')
        // Fallback to 'select' mapping if the specific input type is not defined
        return $this->filterTypes[$frontendInput] ?? $this->filterTypes['select'];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->filters = [];
    }
}
