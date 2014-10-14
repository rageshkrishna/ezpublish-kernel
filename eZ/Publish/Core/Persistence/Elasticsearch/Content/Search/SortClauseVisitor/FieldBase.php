<?php
/**
 * File containing the abstract Field sort clause visitor class
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */

namespace eZ\Publish\Core\Persistence\Elasticsearch\Content\Search\SortClauseVisitor;

use eZ\Publish\Core\Persistence\Elasticsearch\Content\Search\SortClauseVisitor;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\Core\Persistence\Elasticsearch\Content\Search\FieldNameGenerator;
use eZ\Publish\SPI\Persistence\Content\Type\Handler as ContentTypeHandler;
use eZ\Publish\Core\Persistence\Solr\Content\Search\FieldRegistry;

/**
 * Base class for Field sort clauses
 */
abstract class FieldBase extends SortClauseVisitor
{
    /**
     * Field registry
     *
     * @var \eZ\Publish\Core\Persistence\Solr\Content\Search\FieldRegistry
     */
    protected $fieldRegistry;

    /**
     * Content type handler
     *
     * @var \eZ\Publish\SPI\Persistence\Content\Type\Handler
     */
    protected $contentTypeHandler;

    /**
     * Field name generator
     *
     * @var \eZ\Publish\Core\Persistence\Elasticsearch\Content\Search\FieldNameGenerator
     */
    protected $fieldNameGenerator;

    /**
     * @param \eZ\Publish\SPI\Persistence\Content\Type\Handler $contentTypeHandler
     * @param \eZ\Publish\Core\Persistence\Solr\Content\Search\FieldRegistry $fieldRegistry
     * @param \eZ\Publish\Core\Persistence\Elasticsearch\Content\Search\FieldNameGenerator $fieldNameGenerator
     */
    public function __construct(
        ContentTypeHandler $contentTypeHandler,
        FieldRegistry $fieldRegistry,
        FieldNameGenerator $fieldNameGenerator
    )
    {
        $this->contentTypeHandler = $contentTypeHandler;
        $this->fieldRegistry = $fieldRegistry;
        $this->fieldNameGenerator = $fieldNameGenerator;
    }

    /**
     * Get field type information
     *
     * TODO: extract/abstract FieldMap (and handle custom field?? TBD for sort)
     *
     * @param string $contentTypeIdentifier
     * @param string $fieldDefinitionIdentifier
     * @param string $languageCode
     *
     * @return array
     */
    protected function getFieldTypes( $contentTypeIdentifier, $fieldDefinitionIdentifier, $languageCode )
    {
        $types = array();

        foreach ( $this->contentTypeHandler->loadAllGroups() as $group )
        {
            foreach ( $this->contentTypeHandler->loadContentTypes( $group->id ) as $contentType )
            {
                if ( $contentType->identifier !== $contentTypeIdentifier )
                {
                    continue;
                }

                foreach ( $contentType->fieldDefinitions as $fieldDefinition )
                {
                    if ( $fieldDefinition->identifier !== $fieldDefinitionIdentifier )
                    {
                        continue;
                    }

                    // TODO: find a better way to handle non-translatable fields?
                    if ( $languageCode === null || $fieldDefinition->isTranslatable )
                    {
                        $fieldType = $this->fieldRegistry->getType( $fieldDefinition->fieldType );

                        foreach ( $fieldType->getIndexDefinition() as $name => $type )
                        {
                            $types[$type->type] =
                                $this->fieldNameGenerator->getTypedName(
                                    $this->fieldNameGenerator->getName(
                                        $name,
                                        $fieldDefinition->identifier,
                                        $contentType->identifier
                                    ),
                                    $type
                                );
                        }
                    }

                    break 3;
                }
            }
        }

        return $types;
    }

    /**
     * @param null|string $languageCode
     *
     * @return mixed
     */
    protected function getNestedFilterTerm( $languageCode )
    {
        if ( $languageCode === null )
        {
            return array(
                "fields_doc.meta_is_main_translation_b" => true,
            );
        }

        return array(
            "fields_doc.meta_language_code_s" => $languageCode,
        );
    }
}