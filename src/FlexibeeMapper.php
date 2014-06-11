<?php

namespace UniMapper\Mapper;

use UniMapper\Reflection,
    UniMapper\Query,
    UniMapper\Connection\FlexibeeConnection,
    UniMapper\Exceptions\MapperException;

/**
 * Flexibee mapper can be generally used to communicate between repository and
 * Flexibee REST API.
 */
class FlexibeeMapper extends \UniMapper\Mapper
{

    const DATETIME_FORMAT = "Y-m-d\TH:i:sP";

    /** @var \DibiConnection $connection */
    private $connection;

    public function __construct($name, FlexibeeConnection $connection)
    {
        parent::__construct($name);
        $this->connection = $connection;
    }

    protected function unmapValue($value)
    {
        $value = parent::unmapValue($value);
        if ($value === null) {
            $value = "";
        } elseif ($value instanceof \DateTime) {
            $value = $value->format(self::DATETIME_FORMAT);
        }
        return $value;
    }

    /**
     * Delete record by some conditions
     *
     * @param string $resource
     * @param array  $conditions
     */
    public function delete($resource, array $conditions)
    {
        $xml = new \SimpleXMLElement('<winstrom version="1.0" />');
        $xmlResource = $xml->addChild($resource);
        $xmlResource->addAttribute("filter", $this->convertConditions($conditions));
        $xmlResource->addAttribute("action", "delete");

        $this->connection->put(
            rawurlencode($resource) . ".xml",
            $xml->asXML(),
            "application/xml"
        );
    }

    /**
     * Use 'code:' identifier as primary identifier of entities
     *
     * @param mixed  $data         JSON from Flexibee
     * @param string $resourceName Resource name in Flexibee
     *
     * @return mixed
     *
     * @throws \UniMapper\Exceptions\MapperException
     */
    private function setCodeId($data, $resourceName)
    {
        if (!isset($data->{$resourceName})) {
            throw new MapperException("Unknown response, 'code:' prefix missing?!");
        }

        foreach ($data->{$resourceName} as $iterator => $row) {
            if (isset($row->{"external-ids"}[0])
                && substr($row->{"external-ids"}[0],0,5) === "code:"
            ) {
                $data->{$resourceName}[$iterator]->id =
                    $row->{"external-ids"}[0];
            }
        }
        return $data;
    }

    /**
     * Find single record identified by primary value
     *
     * @param string $resource
     * @param mixed  $primaryName
     * @param mixed  $primaryValue
     *
     * @return mixed
     */
    public function findOne($resource, $primaryName, $primaryValue)
    {
        $result = $this->connection->get(
            rawurlencode($resource) . "/" . rawurlencode($primaryValue) . ".json?code-as-id=true"
        );

        if (!isset($result->{$resource}[0])) {
            return false;
        }
        return $this->setCodeId($result, $resource)->{$resource}[0];
    }

    /**
     * Find records
     *
     * @param string  $resource
     * @param array   $selection
     * @param array   $conditions
     * @param array   $orderBy
     * @param integer $limit
     * @param integer $offset
     * @param array   $associations
     *
     * @return array|false
     */
    public function findAll($resource, array $selection = [], array $conditions = [], array $orderBy = [], $limit = 0, $offset = 0, array $associations = [])
    {
        // Get URL
        $url = rawurlencode($resource);

        // Apply conditions
        if (count($conditions) > 0) {
            $url .= "/" . rawurlencode("(" . $this->convertConditions($conditions) . ")");
        }

        // Set response type
        $url .= ".json";

        // Define additional parameters
        $parameters = $this->convertOrderBy($orderBy);

        // Offset and limit must be defined even if null given
        $parameters[] = "start=" . (int) $offset;
        $parameters[] = "limit=" . (int) $limit;

        // Add custom fields from entity properties definitions
        if ($selection) {
            $parameters[] = "detail=custom:" . rawurlencode(implode(",", $this->escapeProperties($selection)));
        }

        // Try to get IDs as 'code:...'
        $parameters[] = "code-as-id=true";

        // Request data
        $data = $this->connection->get($url . "?" . implode("&", $parameters));

        if (count($data->{$resource}) === 0) {
            return false;
        }

        // Set ID and return data
        return $this->setCodeId($data, $resource)->{$resource};
    }

    /**
     * Custom query
     *
     * @param string $resource
     * @param string $query
     * @param string $method
     * @param string $contentType
     * @param mixed  $data
     *
     * @return mixed
     *
     * @throws \UniMapper\Exceptions\MapperException
     */
    public function custom($resource, $query, $method, $contentType, $data)
    {
        $url = rawurlencode($resource);
        if (empty($query)) {
            $query .= ".json";
        }
        $url .= $query;

        if (empty($contentType)) {
            $contentType = "application/json";
        }

        if ($method === Query\Custom::METHOD_GET) {
            return $this->connection->get($url);
        } elseif ($method === Query\Custom::METHOD_PUT || $method === Query\Custom::METHOD_POST) {
            return $this->connection->put($url, $data, $contentType);
        } elseif ($method === Query\Custom::METHOD_DELETE) {
            return $this->connection->delete($url);
        }

        throw new MapperException("Undefined custom method '" . $method . "' used!");
    }

    public function count($resource, array $conditions)
    {
        // Get URL
        $url = rawurlencode($resource);

        // Apply conditions
        if (count($conditions > 0)) {
            $url .= "/" . rawurlencode("(" . $this->convertConditions($conditions) . ")");
        }

        return $this->connection->get($url . ".json?detail=id&add-row-count=true")->{"@rowCount"};
    }

    /**
     * Insert
     *
     * @param string $resource
     * @param array  $values
     *
     * @return mixed Primary value
     */
    public function insert($resource, array $values)
    {
        $result = $this->connection->put(
            rawurlencode($resource) . ".json?code-in-response=true",
            array(
                "@update" => "fail",
                $resource => $values
            )
        );

        if (isset($result->results)) {
            foreach ($result->results as $result) {
                if (isset($result->ref)
                    && strpos($result->ref, $resource) !== false
                ) {
                    if (isset($result->code)) {
                        return "code:" . $result->code;
                    } elseif (isset($result->id)) {
                        return $result->id;
                    }
                }
            }
        }
    }

    protected function convertConditions(array $conditions)
    {
        $result = null;

        foreach ($conditions as $condition) {

            if (is_array($condition[0])) {
                // Nested conditions

                list($nestedConditions, $joiner) = $condition;
                $converted = "(" . $this->convertConditions($nestedConditions) . ")";
                // Add joiner if not first condition
                if ($result !== null) {
                    $result .= " " . $joiner . " ";
                }
                $result .= $converted;

            } else {
                // Simple condition

                list($propertyName, $operator, $value, $joiner) = $condition;

                // Value
                if (is_array($value)) {
                    $value = "('" . implode("','", $value) . "')";
                } elseif ($value instanceof \DateTime) {
                    $value = "'" . $value->format(self::DATETIME_FORMAT) . "'";
                } else {
                    $leftPercent = $rightPercent = false;
                    if (substr($value, 0, 1) === "%") {
                        $value = substr($value, 1);
                        $leftPercent = true;
                    }
                    if (substr($value, -1) === "%") {
                        $value = substr($value, 0, -1);
                        $rightPercent = true;
                    }
                    $value = "'" . $value . "'";
                }

                // Operator
                if ($operator === "COMPARE") {
                    if ($rightPercent && !$leftPercent) {
                        $operator = "BEGINS";
                    } elseif ($leftPercent && !$rightPercent) {
                        $operator = "ENDS";
                    } else {
                        $operator = "LIKE SIMILAR";
                    }
                }

                $formatedCondition = $propertyName . " " . $operator . " " . $value;

                // Check if is it first condition
                if ($result === null) {
                    $result = $formatedCondition;
                } else {
                    $result .= " " . $joiner . " " . $formatedCondition;
                }
            }
        }

        return $result;
    }

    private function convertOrderBy($items)
    {
        $result = [];
        foreach ($items as $name => $direction) {

            if ($direction === "asc") {
                $direction = "A";
            } else {
                $direction = "D";
            }
            $result[] = "order=" . rawurlencode($name  . "@" . $direction);
        }
        return $result;
    }

    /**
     * Update data by set of conditions
     *
     * @param string $resource
     * @param array  $values
     * @param array  $conditions
     */
    public function update($resource, array $values, array $conditions)
    {
        $xml = new \SimpleXMLElement('<winstrom version="1.0" />');
        $xmlResource = $xml->addChild($resource);
        $xmlResource->addAttribute("filter", $this->convertConditions($conditions));
        $xmlResource->addAttribute("create", "fail");

        foreach ($values as $name => $value) {
            if (!is_array($value)) {    // @todo skip arrays temporary

                // @todo Experimental support for @attribute (@removeAll)
                $specialAttributes = array();
                if (strpos($name, "@") !== false) {
                    $specialAttributes = explode("@", $name);
                    $name = array_shift($specialAttributes);
                }

                $itemResource = $xmlResource->addChild($name, $value);
                foreach ($specialAttributes as $attribute) {
                    $itemResource->addAttribute($attribute, $value);
                }
            }
        }

        try {
            $this->connection->put(
                rawurlencode($resource) . ".xml",
                $xml->asXML(),
                "application/xml"
            );
        } catch (UniMapper\Exceptions\FlexibeeException $exception) {

        }
    }

    /**
     * Escape properties with @ char (polozky@removeAll), @showAs ...
     *
     * @param array $properties
     *
     * @return array
     */
    private function escapeProperties(array $properties)
    {
        foreach ($properties as $index => $item) {

            if ($this->endsWith($item, "@removeAll")) {
                $properties[$index] = substr($item, 0, -10);
            } elseif ($this->endsWith($item, "@showAs") || $this->endsWith($item, "@action")) {
                $properties[$index] = substr($item, 0, -7);
            }
        }
        return $properties;
    }

    private function endsWith($haystack, $needle)
    {
        return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
    }

}
