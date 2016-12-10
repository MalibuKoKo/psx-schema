<?php
/*
 * PSX is a open source PHP framework to develop RESTful APIs.
 * For the current version and informations visit <http://phpsx.org>
 *
 * Copyright 2010-2016 Christoph Kappestein <k42b3.x@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PSX\Schema;

use PSX\DateTime\Date;
use PSX\DateTime\DateTime;
use PSX\DateTime\Duration;
use PSX\DateTime\Time;
use PSX\Json\Comparator;
use PSX\Record\Record;
use PSX\Record\RecordInterface;
use PSX\Schema\Visitor\NullVisitor;
use Traversable;

/**
 * SchemaTraverser
 *
 * @author  Christoph Kappestein <k42b3.x@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    http://phpsx.org
 */
class SchemaTraverser
{
    const MAX_RECURSION_DEPTH = 6;

    protected $pathStack;
    protected $recCount;

    /**
     * Traverses through the data and validate it according to the provided 
     * schema. Calls for the visitor methods for each type which can return 
     * different objects for the type
     *
     * @param mixed $data
     * @param \PSX\Schema\SchemaInterface $schema
     * @param \PSX\Schema\VisitorInterface $visitor
     * @return mixed
     */
    public function traverse($data, SchemaInterface $schema, VisitorInterface $visitor = null)
    {
        $this->pathStack = array();
        $this->recCount  = 0;
        
        if ($visitor === null) {
            $visitor = new NullVisitor();
        }

        return $this->recTraverse($data, $schema->getDefinition(), $visitor);
    }

    protected function recTraverse($data, PropertyInterface $property, VisitorInterface $visitor)
    {
        // check recursion
        if ($property instanceof Property\RecursionType) {
            if (!empty($data)) {
                if ($this->recCount > self::MAX_RECURSION_DEPTH) {
                    throw new ValidationException($this->getCurrentPath() . ' max recursion depth reached');
                }

                $this->recCount++;

                $result = $this->recTraverse($data, $property->getOrigin(), $visitor);

                $this->recCount--;

                return $result;
            }
        }

        // if we have no constraints everything is allowed
        if (!$property->hasConstraints()) {
            return $data;
        }

        // check constraints
        $this->assertTypeConstraints($data, $property);
        $this->assertEnumConstraints($data, $property);

        $result = null;
        if ($data === null) {
            $result = $visitor->visitNull($data, $property, $this->getCurrentPath());
        } elseif (is_int($data)) {
            $this->assertNumberConstraints($data, $property);

            $result = $visitor->visitInteger($data, $property, $this->getCurrentPath());
        } elseif (is_float($data)) {
            $this->assertNumberConstraints($data, $property);

            $result = $visitor->visitNumber($data, $property, $this->getCurrentPath());
        } elseif (is_string($data)) {
            $this->assertStringConstraints($data, $property);

            $result = $this->traverseString($data, $property, $visitor);
        } elseif (is_bool($data)) {
            $result = $visitor->visitBoolean($data, $property, $this->getCurrentPath());
        } elseif (is_array($data) || $data instanceof Traversable) {
            $this->assertArrayConstraints($data, $property);

            $result = $this->traverseArray($data, $property, $visitor);
        } elseif ($data instanceof \stdClass || $data instanceof RecordInterface) {
            $this->assertObjectConstraints($data, $property, $visitor);

            $result = $this->traverseObject($data, $property, $visitor);
        }

        // check schema combinations
        $allOf = $property->getAllOf();
        $anyOf = $property->getAnyOf();
        $oneOf = $property->getOneOf();
        if (!empty($allOf)) {
            $result = $this->traverseAllOf($data, $property, $allOf, $visitor);
        } elseif (!empty($anyOf)) {
            $result = $this->traverseAnyOf($data, $anyOf, $visitor);
        } elseif (!empty($oneOf)) {
            $result = $this->traverseOneOf($data, $oneOf, $visitor);
        }

        // not constraint
        $not = $property->getNot();
        if ($not instanceof PropertyType) {
            try {
                $this->recTraverse($data, $not, $visitor);
                $match = true;
            } catch (ValidationException $e) {
                $match = false;
            }

            if ($match) {
                throw new ValidationException($this->getCurrentPath() . ' must not match the schema');
            }
        }

        return $result;
    }

    protected function traverseArray($data, PropertyInterface $property, VisitorInterface $visitor)
    {
        $data   = $this->normalizeArray($data);
        $result = [];
        $keys   = [];

        $items = $property->getItems();
        if ($items === null) {
            // items defaults to empty schema
            $items = new PropertyType();
        }

        if ($items instanceof PropertyInterface) {
            foreach ($data as $index => $value) {
                array_push($this->pathStack, $index);

                $result[] = $this->recTraverse($value, $items, $visitor);
                $keys[] = $index;

                array_pop($this->pathStack);
            }
        } elseif (is_array($items)) {
            foreach ($items as $index => $prop) {
                if (!array_key_exists($index, $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' property "' . $index . '" does not exist');
                }

                array_push($this->pathStack, $index);

                $result[] = $this->recTraverse($data[$index], $prop, $visitor);
                $keys[] = $index;

                array_pop($this->pathStack);
            }
        }

        $remainingKeys = array_diff(array_keys($data), $keys);
        if (!empty($remainingKeys)) {
            $additionalItems = $property->getAdditionalItems();
            if ($additionalItems === null) {
                $additionalItems = true;
            }

            if (is_bool($additionalItems)) {
                if ($additionalItems === true) {
                    foreach ($remainingKeys as $key) {
                        $result[$key] = $data[$key];
                    }
                } else {
                    throw new ValidationException($this->getCurrentPath() . ' property "' . implode(', ', $remainingKeys) . '" does not exist');
                }
            } elseif ($additionalItems instanceof PropertyInterface) {
                foreach ($remainingKeys as $key) {
                    array_push($this->pathStack, $key);

                    $result[$key] = $this->recTraverse($data[$key], $additionalItems, $visitor);

                    array_pop($this->pathStack);
                }
            }
        }

        return $visitor->visitArray($result, $property, $this->getCurrentPath());
    }

    protected function traverseObject($data, PropertyInterface $property, VisitorInterface $visitor)
    {
        $data   = $this->normalizeObject($data);
        $result = new \stdClass();
        $keys   = [];

        $properties = $property->getProperties();
        if (!empty($properties)) {
            foreach ($properties as $key => $prop) {
                array_push($this->pathStack, $key);

                if (array_key_exists($key, $data)) {
                    $result->{$key} = $this->recTraverse($data[$key], $prop, $visitor);
                    $keys[] = $key;
                }

                array_pop($this->pathStack);
            }
        }

        $patternProperties = $property->getPatternProperties();
        if (!empty($patternProperties)) {
            foreach ($patternProperties as $pattern => $prop) {
                // check whether we have keys which match this pattern and 
                // are not already a fixed property

                foreach ($data as $key => $value) {
                    if (preg_match('~' . $pattern . '~', $key)) {
                        array_push($this->pathStack, $key);

                        $result->{$key} = $this->recTraverse($data[$key], $prop, $visitor);
                        $keys[] = $key;

                        array_pop($this->pathStack);
                    }
                }
            }
        }

        $remainingKeys = array_diff(array_keys($data), $keys);
        if (!empty($remainingKeys)) {
            $additionalProperties = $property->getAdditionalProperties();
            if ($additionalProperties === null) {
                $additionalProperties = true;
            }

            if (is_bool($additionalProperties)) {
                if ($additionalProperties === true) {
                    foreach ($remainingKeys as $key) {
                        $result->{$key} = $data[$key];
                    }
                } else {
                    throw new ValidationException($this->getCurrentPath() . ' property "' . implode(', ', $remainingKeys) . '" does not exist');
                }
            } elseif ($additionalProperties instanceof PropertyInterface) {
                foreach ($remainingKeys as $key) {
                    array_push($this->pathStack, $key);

                    $result->{$key} = $this->recTraverse($data[$key], $additionalProperties, $visitor);

                    array_pop($this->pathStack);
                }
            }
        }

        return $visitor->visitObject($result, $property, $this->getCurrentPath());
    }
    
    protected function traverseString($data, PropertyInterface $property, VisitorInterface $visitor)
    {
        switch ($property->getFormat()) {
            case PropertyType::FORMAT_BINARY:
                if (!preg_match('~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~', $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be a valid Base64 encoded string [RFC4648]');
                }

                return $visitor->visitBinary($data, $property, $this->getCurrentPath());
                break;

            case PropertyType::FORMAT_DATETIME:
                if (!preg_match('/^' . DateTime::getPattern() . '$/', $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be a valid date-time format [RFC3339]');
                }

                return $visitor->visitDateTime($data, $property, $this->getCurrentPath());
                break;

            case PropertyType::FORMAT_DATE:
                if (!preg_match('/^' . Date::getPattern() . '$/', $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be a valid full-date format [RFC3339]');
                }

                return $visitor->visitDate($data, $property, $this->getCurrentPath());
                break;

            case PropertyType::FORMAT_DURATION:
                if (!preg_match('/^' . Duration::getPattern() . '$/', $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be a valid duration format [ISO8601]');
                }

                return $visitor->visitDuration($data, $property, $this->getCurrentPath());
                break;

            case PropertyType::FORMAT_TIME:
                if (!preg_match('/^' . Time::getPattern() . '$/', $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be a valid full-time format [RFC3339]');
                }

                return $visitor->visitTime($data, $property, $this->getCurrentPath());
                break;

            case PropertyType::FORMAT_URI:
                // we dont validate uri values

                return $visitor->visitUri($data, $property, $this->getCurrentPath());
                break;

            case 'email':
                if (!filter_var($data, FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException($this->getCurrentPath() . ' must contain a valid email address');
                }

                return $visitor->visitString($data, $property, $this->getCurrentPath());
                break;

            case 'ipv4':
                if (!filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new ValidationException($this->getCurrentPath() . ' must contain a valid IPv4 address');
                }

                return $visitor->visitString($data, $property, $this->getCurrentPath());
                break;

            case 'ipv6':
                if (!filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new ValidationException($this->getCurrentPath() . ' must contain a valid IPv6 address');
                }

                return $visitor->visitString($data, $property, $this->getCurrentPath());
                break;

            default:
                return $visitor->visitString($data, $property, $this->getCurrentPath());
                break;
        }
    }

    protected function traverseAllOf($data, PropertyInterface $property, array $properties, VisitorInterface $visitor)
    {
        $count  = count($properties);
        $match  = 0;
        $result = [];

        foreach ($properties as $index => $prop) {
            try {
                $result[] = $this->recTraverse($data, $prop, new NullVisitor());

                $match++;
            } catch (ValidationException $e) {
            }
        }

        if ($count !== $match) {
            throw new ValidationException($this->getCurrentPath() . ' must match all required schemas (matched only ' . $match . ' out of ' . $count . ')');
        }

        // we must merge the result
        $data = new \stdClass();
        foreach ($result as $row) {
            if ($row instanceof \stdClass) {
                foreach ($row as $key => $value) {
                    $data->{$key} = $value;
                }
            }
        }

        return $visitor->visitObject($data, $property, $this->getCurrentPath());
    }

    protected function traverseAnyOf($data, array $properties, VisitorInterface $visitor)
    {
        $match  = 0;
        $result = null;

        foreach ($properties as $index => $prop) {
            try {
                $result = $this->recTraverse($data, $prop, $visitor);

                $match++;
                break;
            } catch (ValidationException $e) {
            }
        }

        if ($match === 0) {
            throw new ValidationException($this->getCurrentPath() . ' must match any required schema');
        }

        return $result;
    }

    protected function traverseOneOf($data, array $properties, VisitorInterface $visitor)
    {
        $match  = 0;
        $result = null;

        foreach ($properties as $index => $prop) {
            try {
                $result = $this->recTraverse($data, $prop, $visitor);

                $match++;
            } catch (ValidationException $e) {
            }
        }

        if ($match !== 1) {
            throw new ValidationException($this->getCurrentPath() . ' must match one required schema');
        }

        return $result;
    }

    protected function assertTypeConstraints($data, PropertyInterface $property)
    {
        $type = $property->getType();
        if ($type !== null) {
            if (is_string($type)) {
                if ($this->isNotOfType($type, $data)) {
                    throw new ValidationException($this->getCurrentPath() . ' must be of type ' . $type);
                }
            } elseif (is_array($type)) {
                $found = false;
                foreach ($type as $typ) {
                    if (!$this->isNotOfType($typ, $data)) {
                        $found = true;
                        break;
                    }
                }

                if ($found === false) {
                    throw new ValidationException($this->getCurrentPath() . ' must be of type [' . implode(', ', $type) . ']');
                }
            }
        }
    }

    protected function assertEnumConstraints($data, PropertyInterface $property)
    {
        $enum = $property->getEnum();
        if ($enum !== null) {
            $found = false;
            foreach ($enum as $row) {
                if (Comparator::compare($row, $data)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $enum = json_encode($enum);

                throw new ValidationException($this->getCurrentPath() . ' is not in enum ' . $enum);
            }
        }
    }

    protected function assertArrayConstraints(array $data, PropertyInterface $property)
    {
        $minItems = $property->getMinItems();
        if ($minItems !== null) {
            if (count($data) < $minItems) {
                throw new ValidationException($this->getCurrentPath() . ' must contain more or equal then ' . $minItems . ' items');
            }
        }

        $maxItems = $property->getMaxItems();
        if ($maxItems !== null) {
            if (count($data) > $maxItems) {
                throw new ValidationException($this->getCurrentPath() . ' must contain less or equal then ' . $maxItems . ' items');
            }
        }

        $uniqueItems = $property->getUniqueItems();
        if ($uniqueItems !== null && $uniqueItems === true) {
            foreach ($data as $index => $row) {
                $found = false;
                foreach ($data as $innerIndex => $innerRow) {
                    if ($index != $innerIndex && Comparator::compare($row, $innerRow)) {
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    throw new ValidationException($this->getCurrentPath() . ' must contain only unique items');
                }
            }
        }
    }

    protected function assertObjectConstraints(\stdClass $data, PropertyInterface $property, VisitorInterface $visitor)
    {
        $keys = array_keys(get_object_vars($data));

        $minProperties = $property->getMinProperties();
        if ($minProperties !== null) {
            if (count($keys) < $minProperties) {
                throw new ValidationException($this->getCurrentPath() . ' must contain more or equal then ' . $minProperties . ' properties');
            }
        }

        $maxProperties = $property->getMaxProperties();
        if ($maxProperties !== null) {
            if (count($keys) > $maxProperties) {
                throw new ValidationException($this->getCurrentPath() . ' must contain less or equal then ' . $maxProperties . ' properties');
            }
        }

        $required = $property->getRequired();
        if ($required !== null) {
            $diff = array_diff($required, $keys);
            if (count($diff) > 0) {
                throw new ValidationException($this->getCurrentPath() . ' the following properties are required: ' . implode(', ', $diff));
            }
        }

        $dependencies = $property->getDependencies();
        if (!empty($dependencies)) {
            foreach ($dependencies as $name => $prop) {
                if (in_array($name, $keys)) {
                    if ($prop instanceof PropertyInterface) {
                        $this->recTraverse($data, $prop, $visitor);
                    } elseif (is_array($prop)) {
                        $diff = array_diff($prop, $keys);
                        if (count($diff) > 0) {
                            throw new ValidationException($this->getCurrentPath() . ' the property ' . $name . ' depends on the following properties: ' . implode(', ', $diff));
                        }
                    }
                }
            }
        }
    }

    protected function assertNumberConstraints($data, PropertyInterface $property)
    {
        $maximum = $property->getMaximum();
        if ($maximum !== null) {
            if ($property->getExclusiveMaximum()) {
                if ($data >= $maximum) {
                    throw new ValidationException($this->getCurrentPath() . ' must be lower then ' . $maximum);
                }
            } else {
                if ($data > $maximum) {
                    throw new ValidationException($this->getCurrentPath() . ' must be lower or equal then ' . $maximum);
                }
            }
        }

        $minimum = $property->getMinimum();
        if ($minimum !== null) {
            if ($property->getExclusiveMinimum()) {
                if ($data <= $minimum) {
                    throw new ValidationException($this->getCurrentPath() . ' must be greater then ' . $minimum);
                }
            } else {
                if ($data < $minimum) {
                    throw new ValidationException($this->getCurrentPath() . ' must be greater or equal then ' . $minimum);
                }
            }
        }

        $multipleOf = $property->getMultipleOf();
        if ($multipleOf !== null) {
            $result = $data / $multipleOf;
            $base   = (int) $result;

            // its important to make a loose comparison
            if ($data > 0 && $result - $base != 0) {
                throw new ValidationException($this->getCurrentPath() . ' must be a multiple of ' . $multipleOf);
            }
        }
    }

    protected function assertStringConstraints($data, PropertyInterface $property)
    {
        $minLength = $property->getMinLength();
        if ($minLength !== null) {
            if (strlen($data) < $minLength) {
                throw new ValidationException($this->getCurrentPath() . ' must contain more or equal then ' . $minLength . ' characters');
            }
        }

        $maxLength = $property->getMaxLength();
        if ($maxLength !== null) {
            if (strlen($data) > $maxLength) {
                throw new ValidationException($this->getCurrentPath() . ' must contain less or equal then ' . $maxLength . ' characters');
            }
        }

        $pattern = $property->getPattern();
        if ($pattern !== null) {
            $result = preg_match('/' . $pattern . '/', $data);
            if (!$result) {
                throw new ValidationException($this->getCurrentPath() . ' does not match pattern [' . $pattern . ']');
            }
        }
    }

    private function normalizeArray($data)
    {
        if ($data instanceof Traversable) {
            $data = iterator_to_array($data, false);
        }

        return $data;
    }

    private function normalizeObject($data)
    {
        if ($data instanceof RecordInterface) {
            $data = $data->getProperties();
        } elseif ($data instanceof \stdClass) {
            $data = (array) $data;
        }

        return $data;
    }

    private function getCurrentPath()
    {
        return '/' . implode('/', $this->pathStack);
    }

    private function isNotOfType($type, $data)
    {
        if ($type === PropertyType::TYPE_NULL && $data !== null) {
            return true;
        } elseif ($type === PropertyType::TYPE_BOOLEAN && !is_bool($data)) {
            return true;
        } elseif ($type === PropertyType::TYPE_OBJECT && !$data instanceof \stdClass) {
            return true;
        } elseif ($type === PropertyType::TYPE_ARRAY && !is_array($data)) {
            return true;
        } elseif ($type === PropertyType::TYPE_NUMBER && !(is_int($data) || is_float($data))) {
            return true;
        } elseif ($type === PropertyType::TYPE_INTEGER && !is_int($data)) {
            return true;
        } elseif ($type === PropertyType::TYPE_STRING && !is_string($data)) {
            return true;
        }

        return false;
    }
}
