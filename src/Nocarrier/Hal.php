<?php
/**
 * This file is part of the Hal library
 *
 * (c) Ben Longden <ben@nocarrier.co.uk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package Nocarrier
 */

namespace Nocarrier;

/**
 * The Hal document class
 *
 * @package Nocarrier
 * @author Ben Longden <ben@nocarrier.co.uk>
 */
class Hal
{
    /**
     * The uri represented by this representation.
     *
     * @var string
     */
    protected $uri;

    /**
     * The data for this resource. An associative array of key value pairs.
     *
     * array(
     *     'price' => 30.00,
     *     'colour' => 'blue'
     * )
     *
     * @var array
     */
    protected $data;

    /**
     * An array of embedded Hal objects representing embedded resources.
     *
     * @var array
     */
    protected $resources = array();

    /**
     * A collection of \Nocarrier\HalLink objects keyed by the link relation to
     * this resource.
     *
     * array(
     *     'next' => [HalLink]
     * )
     *
     * @var array
     */
    protected $links = null;

    /**
     * Construct a new Hal object from an array of data. You can markup the
     * $data array with certain keys and values in order to affect the
     * generated JSON or XML documents if required to do so.
     *
     * '@' prefix on any array key will cause the value to be set as an
     * attribute on the XML element generated by the parent. i.e, array('x' =>
     * array('@href' => 'http://url')) will yield <x href='http://url'></x> in
     * the XML representation. The @ prefix will be stripped from the JSON
     * representation.
     *
     * Specifying the key 'value' will cause the value of this key to be set as
     * the value of the XML element instead of a child. i.e, array('x' =>
     * array('value' => 'example')) will yield <x>example</x> in the XML
     * representation. This will not affect the JSON representation.
     *
     * @param mixed $uri
     * @param array $data
     */
    public function __construct($uri = null, array $data = array())
    {
        $this->uri = $uri;
        $this->data = $data;

        $this->links = new HalLinkContainer();
    }

    /**
     * Decode a application/hal+json document into a Nocarrier\Hal object.
     *
     * @param string $text
     * @param int $max_depth
     * @static
     * @access public
     * @return \Nocarrier\Hal
     */
    public static function fromJson($text, $max_depth = 0)
    {
        $data = json_decode($text, true);
        $uri = isset($data['_links']['self']['href']) ? $data['_links']['self']['href'] : "";
        unset ($data['_links']['self']);

        $links = isset($data['_links']) ? $data['_links'] : array();
        unset ($data['_links']);

        $embedded = isset($data['_embedded']) ? $data['_embedded'] : array();
        unset ($data['_embedded']);

        $hal = new static($uri, $data);
        foreach ($links as $rel => $links) {
            if (!isset($links[0]) or !is_array($links[0])) {
                $links = array($links);
            }

            foreach ($links as $link) {
                $href = $link['href'];
                unset($link['href'], $link['title']);
                $hal->addLink($rel, $href, $link);
            }
        }

        if ($max_depth > 0) {
            foreach ($embedded as $rel => $embed) {
                if (!is_array($embed)) {
                    $hal->addResource($rel, self::fromJson(json_encode($embed), $max_depth - 1));
                } else {
                    foreach ($embed as $child_resource) {
                        $hal->addResource($rel, self::fromJson(json_encode($child_resource), $max_depth - 1));
                    }
                }
            }
        }

        return $hal;
    }

    /**
     * Decode a application/hal+xml document into a Nocarrier\Hal object.
     *
     * @param string $text
     * @param int $max_depth
     *
     * @static
     * @access public
     * @return \Nocarrier\Hal
     */
    public static function fromXml($text, $max_depth = 0)
    {
        if (!$text instanceof \SimpleXMLElement) {
            $data = new \SimpleXMLElement($text);
        } else {
            $data = $text;
        }
        $children = $data->children();
        $links = clone $children->link;
        unset ($children->link);

        $embedded = clone $children->resource;
        unset ($children->resource);

        $hal = new static((string)$data->attributes()->href, (array) $children);
        foreach ($links as $links) {
            if (!is_array($links)) {
                $links = array($links);
            }
            foreach ($links as $link) {
                $attributes = (array)$link->attributes();
                $attributes = $attributes['@attributes'];
                $rel = $attributes['rel'];
                $href = $attributes['href'];
                unset($attributes['rel'], $attributes['href']);
                $hal->addLink($rel, $href, $attributes);
            }
        }

        if ($max_depth > 0) {
            foreach ($embedded as $embed) {
                $attributes = (array)$embed->attributes();
                $attributes = $attributes['@attributes'];
                $rel        = $attributes['rel'];
                unset($attributes['rel'], $attributes['href']);

                $hal->addResource($rel, self::fromXml($embed, $max_depth - 1));
            }
        }

        return $hal;
    }

    /**
     * Add a link to the resource, identified by $rel, located at $uri.
     *
     * @param string $rel
     * @param string $uri
     * @param array $attributes
     *   Other attributes, as defined by HAL spec and RFC 5988.
     * @return \Nocarrier\Hal
     */
    public function addLink($rel, $uri, array $attributes = array())
    {
        $this->links[$rel][] = new HalLink($uri, $attributes);

        return $this;
    }

    /**
     * Add an embedded resource, identified by $rel and represented by $resource.
     *
     * @param string $rel
     * @param \Nocarrier\Hal $resource
     *
     * @return \Nocarrier\Hal
     */
    public function addResource($rel, \Nocarrier\Hal $resource = null)
    {
        $this->resources[$rel][] = $resource;

        return $this;
    }

    /**
     * Set resource's data
     */
    public function setData(Array $data = null)
    {
        $this->data = $data;
    }

    /**
     * Return an array of data (key => value pairs) representing this resource.
     *
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Return an array of Nocarrier\HalLink objects representing resources
     * related to this one.
     *
     * @return array A collection of \Nocarrier\HalLink
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Lookup and return an array of HalLink objects for a given relation.
     * Will also resolve CURIE rels if required.
     *
     * @param string $rel The link relation required
     * @return array|bool
     *   Array of HalLink objects if found. Otherwise false.
     */
    public function getLink($rel)
    {
        return $this->links->get($rel);
    }

    /**
     * Return an array of Nocarrier\Hal objected embedded in this one.
     *
     * @return array
     */
    public function getResources()
    {
        return $this->resources;
    }

    /**
     * Set resource's URI
     */
    public function setUri($uri)
    {
        $this->uri = $uri;
    }

    /**
     * Get resource's URI.
     *
     * @return mixed
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Return the current object in a application/hal+json format (links and
     * resources).
     *
     * @param bool $pretty
     *   Enable pretty-printing.
     * @return string
     */
    public function asJson($pretty = false)
    {
        $renderer = new HalJsonRenderer();

        return $renderer->render($this, $pretty);
    }

    /**
     * Return the current object in a application/hal+xml format (links and
     * resources).
     *
     * @param bool $pretty Enable pretty-printing
     * @return string
     */
    public function asXml($pretty = false)
    {
        $renderer = new HalXmlRenderer();

        return $renderer->render($this, $pretty);
    }

    /**
     * Create a CURIE link template, used for abbreviating custom link
     * relations.
     *
     * e.g,
     * $hal->addCurie('acme', 'http://.../rels/{rel}');
     * $hal->addLink('acme:test', 'http://.../test');
     *
     * @param string $name
     * @param string $uri
     *
     * @return \Nocarrier\Hal
     */
    public function addCurie($name, $uri)
    {
        return $this->addLink('curies', $uri, array('name' => $name, 'templated' => true));
    }
}
