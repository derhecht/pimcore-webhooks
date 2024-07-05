<?php

namespace WebHookBundle\Utils;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\MultiSelectOptionsProviderInterface;


class OptionsProvider implements MultiSelectOptionsProviderInterface
{
    /**
     * @param array $context
     * @param Data $fieldDefinition
     * @return array
     */

    public function getOptions($context, $fieldDefinition): array
    {
        $classesList = new ClassDefinition\Listing();
        $classesList->setOrderKey('name');
        $classesList->setOrder('asc');
        $classesList->setCondition('name NOT LIKE ?', $context['class']->getName() . '%');

        $classes = $classesList->load();
        $result = array();
        foreach ($classes as $class) {
            $result[] = array("key" => $class->getName(), "value" => $class->getName());
        }
        return $result;
    }

    /**
     * Returns the value which is defined in the 'Default value' field
     * @param array $context
     * @param Data $fieldDefinition
     * @return mixed
     */
    public function getDefaultValue($context, $fieldDefinition)
    {
        return null;
    }

    /**
     * @param array $context
     * @param Data $fieldDefinition
     * @return bool
     */
    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return true;
    }

}
