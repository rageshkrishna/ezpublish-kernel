<?php
/**
 * File containing the eZ\Publish\SPI\Persistence\Content\Search\Document class
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */

namespace eZ\Publish\SPI\Persistence\Content\Search;

use eZ\Publish\API\Repository\Values\ValueObject;

/**
 * Base class for documents.
 */
class Document extends ValueObject
{
    /**
     * An array of fields
     *
     * @var \eZ\Publish\SPI\Persistence\Content\Search\Field[]
     */
    public $fields = array();

    /**
     * An array of sub-documents
     *
     * @var \eZ\Publish\SPI\Persistence\Content\Search\Document[]
     */
    public $documents = array();
}