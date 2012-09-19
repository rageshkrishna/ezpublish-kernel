<?php
/**
 * File containing the index.php for the REST Server
 *
 * ATTENTION: This is a test setup for the REST server. DO NOT USE IT IN
 * PRODUCTION!
 *
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\REST\Server;
use eZ\Publish\Core\REST\Common;

use Qafoo\RMF;

ini_set( 'html_errors', 0 );

/*
 * Configuration magic, this should actually be taken from services.ini in the
 * future.
 */

$configFile = __DIR__ . '/database.cnf';

if ( is_file( $configFile ) )
{
    $_ENV['DATABASE'] = trim( file_get_contents( $configFile ) );
}

if ( !isset( $_ENV['DATABASE'] ) )
{
    echo "The REST test server does only work with a persistent database.\n";
    echo "Please specify a database DSN in the environment variable DATABASE.\n";
    echo "Or create a database.cnf file with it in the same directory as index.php\n";
    exit( 1 );
}

// Exposing $legacyKernelHandler to be a web handler (to be used in bootstrap.php)
$legacyKernelHandler = function()
{
    return new \ezpKernelWeb;
};
require_once __DIR__ . '/../../../../../bootstrap.php';

/*
 * This is a very simple session handling for the repository, which allows the
 * integration tests to run multiple requests against a continuous repository
 * state. This is needed in many test methods, e.g. in
 * SectionServiceTest::testUpdateSection() where there is 1. the section loaded
 * and 2. updated.
 *
 * The test framework therefore issues an X-Test-Session header, with the same
 * session ID for a dedicated test method. If a session was already started,
 * the database is not reset for this request. NOTE: This does NOT work with
 * SQLite in-memory databases, since these are automatically cleared on request
 * shutdown!
 */

$reInitializeRepository = true;
if ( isset( $_SERVER['HTTP_X_TEST_SESSION'] ) )
{
    $sessionId = $_SERVER['HTTP_X_TEST_SESSION'];

    $sessionFile = __DIR__ . '/.session';

    // Only re-initialize the repository, if for the current session no session
    // file exists
    $reInitializeRepository = ( !is_file( $sessionFile )  || file_get_contents( $sessionFile ) !== $sessionId );

    file_put_contents( $sessionFile, $sessionId );
}

/*
 * The setup factory, which is also used for setting up the normal repository
 * for the integration tests, is re-used here.
 */
$setupFactory = new \eZ\Publish\API\Repository\Tests\SetupFactory\Legacy();
$repository   = $setupFactory->getRepository( $reInitializeRepository );

/*
 * The following reflects a standard REST server setup
 */

/*
 * Handlers are used to parse the input body (XML or JSON) into a common array
 * structure, as generated by json_decode( $body, true ).
 */

$handler = array(
    'json' => new Common\Input\Handler\Json(),
    'xml'  => new Common\Input\Handler\Xml(),
);

// The URL Handler is responsible for URL parsing and generation. It will be
// used in the output generators and in some parsing handlers.
$urlHandler = new Common\UrlHandler\eZPublish();

// Object with convenience methods for parsers
$parserTools = new Common\Input\ParserTools();

// Parser for field values (using FieldTypes for toHash()/fromHash() operations)
$fieldTypeParser = new Common\Input\FieldTypeParser(
    $repository->getContentService(),
    $repository->getContentTypeService(),
    $repository->getFieldTypeService()
);

/*
 * The Input Dispatcher receives the array structure as decoded by a handler
 * fitting the input format. It selects a parser based on the media type of the
 * input, which is used to transform the input into a ValueObject.
 */
$inputDispatcher = new Common\Input\Dispatcher(
    new Common\Input\ParsingDispatcher(
        array(
            'application/vnd.ez.api.RoleInput'              => new Input\Parser\RoleInput( $urlHandler, $repository->getRoleService(), $parserTools ),
            'application/vnd.ez.api.SectionInput'           => new Input\Parser\SectionInput( $urlHandler, $repository->getSectionService() ),
            'application/vnd.ez.api.ContentCreate'          => new Input\Parser\ContentCreate(
                $urlHandler,
                $repository->getContentService(),
                $repository->getContentTypeService(),
                $fieldTypeParser,
                // Needed here since there's no ContentType in request for embedded LocationCreate
                ( $locationCreateParser = new Input\Parser\LocationCreate( $urlHandler, $repository->getLocationService(), $parserTools ) ),
                $parserTools
            ),
            'application/vnd.ez.api.ContentUpdate'          => new Input\Parser\ContentUpdate( $urlHandler ),
            'application/vnd.ez.api.PolicyCreate'           => new Input\Parser\PolicyCreate( $urlHandler, $repository->getRoleService(), $parserTools ),
            'application/vnd.ez.api.PolicyUpdate'           => new Input\Parser\PolicyUpdate( $urlHandler, $repository->getRoleService(), $parserTools ),
            'application/vnd.ez.api.RoleAssignInput'        => new Input\Parser\RoleAssignInput( $urlHandler, $parserTools ),
            'application/vnd.ez.api.LocationCreate'         => $locationCreateParser,
            'application/vnd.ez.api.LocationUpdate'         => new Input\Parser\LocationUpdate( $urlHandler, $repository->getLocationService(), $parserTools ),
            'application/vnd.ez.api.ObjectStateGroupCreate' => new Input\Parser\ObjectStateGroupCreate( $urlHandler, $repository->getObjectStateService(), $parserTools ),
            'application/vnd.ez.api.ObjectStateGroupUpdate' => new Input\Parser\ObjectStateGroupUpdate( $urlHandler, $repository->getObjectStateService(), $parserTools ),
            'application/vnd.ez.api.ObjectStateCreate'      => new Input\Parser\ObjectStateCreate( $urlHandler, $repository->getObjectStateService(), $parserTools ),
            'application/vnd.ez.api.ObjectStateUpdate'      => new Input\Parser\ObjectStateUpdate( $urlHandler, $repository->getObjectStateService(), $parserTools ),
            'application/vnd.ez.api.ContentObjectStates'    => new Input\Parser\ContentObjectStates( $urlHandler ),
            'application/vnd.ez.api.RelationCreate'         => new Input\Parser\RelationCreate( $urlHandler ),
        )
    ),
    $handler
);

/*
 * Controllers are simple classes with public methods. They are the only ones
 * working directly with the Request object provided by RMF. Their
 * responsibility is to extract the request data and dispatch the corresponding
 * call to methods of the Public API.
 */

$rootController = new Controller\Root(
    $inputDispatcher,
    $urlHandler
);

$sectionController = new Controller\Section(
    $inputDispatcher,
    $urlHandler,
    $repository->getSectionService()
);

$contentController = new Controller\Content(
    $inputDispatcher,
    $urlHandler,
    $repository->getContentService(),
    $repository->getLocationService(),
    $repository->getSectionService()
);

$contentTypeController = new Controller\ContentType(
    $inputDispatcher,
    $urlHandler,
    $repository->getContentTypeService()
);

$roleController = new Controller\Role(
    $inputDispatcher,
    $urlHandler,
    $repository->getRoleService(),
    $repository->getUserService(),
    $repository->getLocationService()
);

$locationController = new Controller\Location(
    $inputDispatcher,
    $urlHandler,
    $repository->getLocationService(),
    $repository->getContentService(),
    $repository->getTrashService()
);

$objectStateController = new Controller\ObjectState(
    $inputDispatcher,
    $urlHandler,
    $repository->getObjectStateService(),
    $repository->getContentService()
);

$trashController = new Controller\Trash(
    $inputDispatcher,
    $urlHandler,
    $repository->getTrashService(),
    $repository->getLocationService()
);

$userController = new Controller\User(
    $inputDispatcher,
    $urlHandler,
    $repository->getUserService(),
    $repository->getLocationService()
);

/*
 * Visitors are used to transform the Value Objects returned by the Public API
 * into the output format requested by the client. In some cases, it is
 * necessary to use Value Objects which are not part of the Public API itself,
 * in order to encapsulate data structures which don't exist there (e.g.
 * SectionList) or to trigger slightly different output (e.g. CreatedSection to
 * generate a "Created" response).
 *
 * A visitor uses a generator (XML or JSON) to generate the output structure
 * according to the API definition. It can also set headers for the output.
 */

$valueObjectVisitors = array(
    // Errors

    '\\eZ\\Publish\\API\\Repository\\Exceptions\\InvalidArgumentException'  => new Output\ValueObjectVisitor\InvalidArgumentException( $urlHandler,  true ),
    '\\eZ\\Publish\\API\\Repository\\Exceptions\\NotFoundException'         => new Output\ValueObjectVisitor\NotFoundException( $urlHandler,  true ),
    '\\eZ\\Publish\\API\\Repository\\Exceptions\\BadStateException'         => new Output\ValueObjectVisitor\BadStateException( $urlHandler,  true ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Exceptions\\BadRequestException'    => new Output\ValueObjectVisitor\BadRequestException( $urlHandler,  true ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Exceptions\\ForbiddenException'     => new Output\ValueObjectVisitor\ForbiddenException( $urlHandler,  true ),
    '\\Exception'                                                           => new Output\ValueObjectVisitor\Exception( $urlHandler,  true ),

    // Section

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\SectionList'                => new Output\ValueObjectVisitor\SectionList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedSection'             => new Output\ValueObjectVisitor\CreatedSection( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\Content\\Section'              => new Output\ValueObjectVisitor\Section( $urlHandler ),

    // Content

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ContentList'                => new Output\ValueObjectVisitor\ContentList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestContent'                => new Output\ValueObjectVisitor\RestContent( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedContent'             => new Output\ValueObjectVisitor\CreatedContent( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\VersionList'                => new Output\ValueObjectVisitor\VersionList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedVersion'             => new Output\ValueObjectVisitor\CreatedVersion( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\Content\\VersionInfo'          => new Output\ValueObjectVisitor\VersionInfo( $urlHandler ),

    // The following two visitors are quite similar, as they both generate
    // <Version> resource. However, "Version" visitor DOES NOT generate embedded
    // <Fields> and <Relations> elements, while "Content" visitor DOES
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\Version'                    => new Output\ValueObjectVisitor\Version( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\Content\\Content'              => new Output\ValueObjectVisitor\Content(
        $urlHandler,
        new Common\Output\FieldTypeSerializer( $repository->getFieldTypeService() )
    ),

    // User

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestUserGroup'            => new Output\ValueObjectVisitor\RestUserGroup( $urlHandler ),

    // ContentType

    '\\eZ\\Publish\\API\\Repository\\Values\\ContentType\\ContentType'      => new Output\ValueObjectVisitor\ContentType(
        $urlHandler
    ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\FieldDefinitionList'        => new Output\ValueObjectVisitor\FieldDefinitionList(
        $urlHandler
    ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestFieldDefinition'        => new Output\ValueObjectVisitor\RestFieldDefinition(
        $urlHandler,
        new Common\Output\FieldTypeSerializer( $repository->getFieldTypeService() )
    ),

    // Relation

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RelationList'               => new Output\ValueObjectVisitor\RelationList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestRelation'               => new Output\ValueObjectVisitor\RestRelation( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedRelation'            => new Output\ValueObjectVisitor\CreatedRelation( $urlHandler ),

    // Role

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RoleList'                   => new Output\ValueObjectVisitor\RoleList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedRole'                => new Output\ValueObjectVisitor\CreatedRole( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\User\\Role'                    => new Output\ValueObjectVisitor\Role( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\User\\Policy'                  => new Output\ValueObjectVisitor\Policy( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedPolicy'              => new Output\ValueObjectVisitor\CreatedPolicy( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\PolicyList'                 => new Output\ValueObjectVisitor\PolicyList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RoleAssignmentList'         => new Output\ValueObjectVisitor\RoleAssignmentList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestUserRoleAssignment'     => new Output\ValueObjectVisitor\RestUserRoleAssignment( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\RestUserGroupRoleAssignment' => new Output\ValueObjectVisitor\RestUserGroupRoleAssignment( $urlHandler ),

    // Location

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedLocation'            => new Output\ValueObjectVisitor\CreatedLocation( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\Content\\Location'             => new Output\ValueObjectVisitor\Location( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\LocationList'               => new Output\ValueObjectVisitor\LocationList( $urlHandler ),

    // Trash

    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\Trash'                      => new Output\ValueObjectVisitor\Trash( $urlHandler ),
    '\\eZ\\Publish\\API\\Repository\\Values\\Content\\TrashItem'            => new Output\ValueObjectVisitor\TrashItem( $urlHandler ),

    // Object state

    '\\eZ\\Publish\\API\\Repository\\Values\\ObjectState\\ObjectStateGroup' => new Output\ValueObjectVisitor\ObjectStateGroup( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedObjectStateGroup'    => new Output\ValueObjectVisitor\CreatedObjectStateGroup( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ObjectStateGroupList'       => new Output\ValueObjectVisitor\ObjectStateGroupList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Common\\Values\\RestObjectState'            => new Output\ValueObjectVisitor\RestObjectState( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\CreatedObjectState'         => new Output\ValueObjectVisitor\CreatedObjectState( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ObjectStateList'            => new Output\ValueObjectVisitor\ObjectStateList( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Common\\Values\\ContentObjectStates'        => new Output\ValueObjectVisitor\ContentObjectStates( $urlHandler ),

    // REST specific
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ResourceRedirect'           => new Output\ValueObjectVisitor\ResourceRedirect( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\PermanentRedirect'          => new Output\ValueObjectVisitor\PermanentRedirect( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ResourceDeleted'            => new Output\ValueObjectVisitor\ResourceDeleted( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ResourceCreated'            => new Output\ValueObjectVisitor\ResourceCreated( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\ResourceSwapped'            => new Output\ValueObjectVisitor\ResourceSwapped( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Server\\Values\\NoContent'                  => new Output\ValueObjectVisitor\NoContent( $urlHandler ),
    '\\eZ\\Publish\\Core\\REST\\Common\\Values\\Root'                       => new Output\ValueObjectVisitor\Root( $urlHandler ),
);

/*
 * We use a simple derived implementation of the RMF dispatcher here, which
 * first authenticates the user and then triggers the parent dispatching
 * process.
 *
 * The RMF dispatcher is the core of the MVC. It selects a controller method on
 * basis of the request URI (regex match) and the HTTP verb, which is then executed.
 * After the controller has been executed, the view (second parameter) is
 * triggered to send the result to the client. The Accept Header View
 * Dispatcher selects from different view configurations the output format
 * based on the Accept HTTP header sent by the client.
 *
 * The used inner views are custom to the REST server and dispatch the received
 * Value Object to one of the visitors registered above.
 */

$dispatcher = new AuthenticatingDispatcher(
    new RMF\Router\Regexp(
        array(
            // /

            '(^/$)' => array(
                'GET' => array( $rootController, 'loadRootResource' ),
            ),

            // /content/sections

            '(^/content/sections$)' => array(
                'GET'  => array( $sectionController, 'listSections' ),
                'POST' => array( $sectionController, 'createSection' ),
            ),
            '(^/content/sections\?identifier=.*$)' => array(
                'GET'  => array( $sectionController, 'loadSectionByIdentifier' ),
            ),
            '(^/content/sections/[0-9]+$)' => array(
                'GET'    => array( $sectionController, 'loadSection' ),
                'PATCH'  => array( $sectionController, 'updateSection' ),
                'DELETE' => array( $sectionController, 'deleteSection' ),
            ),

            // /content/objects

            '(^/content/objects$)' => array(
                'POST' => array( $contentController, 'createContent' ),
            ),
            '(^/content/objects\?remoteId=[0-9a-z]+$)' => array(
                'GET'   => array( $contentController, 'loadContentInfoByRemoteId' ),
            ),
            '(^/content/objects/[0-9]+$)' => array(
                'PATCH' => array( $contentController, 'updateContentMetadata' ),
                'GET' => array( $contentController, 'loadContent' ),
                'DELETE' => array( $contentController, 'deleteContent' ),
                'COPY' => array( $contentController, 'copyContent' ),
            ),
            '(^/content/objects/[0-9]+/relations$)' => array(
                'GET' => array( $contentController, 'redirectCurrentVersionRelations' ),
            ),
            '(^/content/objects/[0-9]+/versions$)' => array(
                'GET' => array( $contentController, 'loadContentVersions' ),
            ),
            '(^/content/objects/[0-9]+/versions/[0-9]+/relations$)' => array(
                'GET' => array( $contentController, 'loadVersionRelations' ),
                'POST' => array( $contentController, 'createRelation' ),
            ),
            '(^/content/objects/[0-9]+/versions/[0-9]+/relations/[0-9]+$)' => array(
                'GET' => array( $contentController, 'loadVersionRelation' ),
                'DELETE' => array( $contentController, 'removeRelation' ),
            ),
            '(^/content/objects/[0-9]+/versions/[0-9]+$)' => array(
                'GET' => array( $contentController, 'loadContentInVersion' ),
                'DELETE' => array( $contentController, 'deleteContentVersion' ),
                'COPY' => array( $contentController, 'createDraftFromVersion' ),
                'PUBLISH' => array( $contentController, 'publishVersion' ),
            ),
            '(^/content/objects/[0-9]+/currentversion$)' => array(
                'GET' => array( $contentController, 'redirectCurrentVersion' ),
                'COPY' => array( $contentController, 'createDraftFromCurrentVersion' ),
            ),
            '(^/content/objects/[0-9]+/locations$)' => array(
                'GET' => array( $locationController, 'loadLocationsForContent' ),
                'POST' => array( $locationController, 'createLocation' ),
            ),
            '(^/content/objects/[0-9]+/objectstates$)' => array(
                'GET' => array( $objectStateController, 'getObjectStatesForContent' ),
                'PATCH' => array( $objectStateController, 'setObjectStatesForContent' ),
            ),

            // /content/objectstategroups

            '(^/content/objectstategroups$)' => array(
                'GET' => array( $objectStateController, 'loadObjectStateGroups' ),
                'POST' => array( $objectStateController, 'createObjectStateGroup' ),
            ),
            '(^/content/objectstategroups/[0-9]+$)' => array(
                'GET' => array( $objectStateController, 'loadObjectStateGroup' ),
                'PATCH' => array( $objectStateController, 'updateObjectStateGroup' ),
                'DELETE' => array( $objectStateController, 'deleteObjectStateGroup' ),
            ),
            '(^/content/objectstategroups/[0-9]+/objectstates$)' => array(
                'GET' => array( $objectStateController, 'loadObjectStates' ),
                'POST' => array( $objectStateController, 'createObjectState' ),
            ),
            '(^/content/objectstategroups/[0-9]+/objectstates/[0-9]+$)' => array(
                'GET' => array( $objectStateController, 'loadObjectState' ),
                'PATCH' => array( $objectStateController, 'updateObjectState' ),
                'DELETE' => array( $objectStateController, 'deleteObjectState' ),
            ),

            // content/locations

            '(^/content/locations\?remoteId=[0-9a-z]+$)' => array(
                'GET' => array( $locationController, 'loadLocationByRemoteId' ),
            ),
            '(^/content/locations/[0-9/]+$)' => array(
                'GET'    => array( $locationController, 'loadLocation' ),
                'PATCH'  => array( $locationController, 'updateLocation' ),
                'DELETE' => array( $locationController, 'deleteSubtree' ),
                'COPY'   => array( $locationController, 'copySubtree' ),
                'MOVE'   => array( $locationController, 'moveSubtree' ),
                'SWAP'   => array( $locationController, 'swapLocation' ),
            ),
            '(^/content/locations/[0-9/]+/children$)' => array(
                'GET'    => array( $locationController, 'loadLocationChildren' ),
            ),

            // /content/types

            '(^/content/types/[0-9]+$)' => array(
                'GET'   => array( $contentTypeController, 'loadContentType' ),
            ),
            '(^/content/types/[0-9]+/fieldDefinitions$)' => array(
                'GET'   => array( $contentTypeController, 'loadFieldDefinitionList' ),
            ),
            '(^/content/types/[0-9]+/fieldDefinitions/[0-9]+$)' => array(
                'GET'   => array( $contentTypeController, 'loadFieldDefinition' ),
            ),

            // /content/trash

            '(^/content/trash$)' => array(
                'GET'    => array( $trashController, 'loadTrashItems' ),
                'DELETE' => array( $trashController, 'emptyTrash' ),
            ),
            '(^/content/trash/[0-9]+$)' => array(
                'GET'    => array( $trashController, 'loadTrashItem' ),
                'DELETE' => array( $trashController, 'deleteTrashItem' ),
                'MOVE'   => array( $trashController, 'restoreTrashItem' ),
            ),

            // /user

            '(^/user/policies\?userId=[0-9]+$)' => array(
                'GET' => array( $roleController, 'listPoliciesForUser' ),
            ),
            '(^/user/roles$)' => array(
                'GET' => array( $roleController, 'listRoles' ),
                'POST' => array( $roleController, 'createRole' ),
            ),
            '(^/user/roles\?identifier=.*$)' => array(
                'GET'  => array( $roleController, 'loadRoleByIdentifier' ),
            ),
            '(^/user/roles/[0-9]+$)' => array(
                'GET'    => array( $roleController, 'loadRole' ),
                'PATCH'  => array( $roleController, 'updateRole' ),
                'DELETE' => array( $roleController, 'deleteRole' ),
            ),
            '(^/user/roles/[0-9]+/policies$)' => array(
                'GET'    => array( $roleController, 'loadPolicies' ),
                'POST'   => array( $roleController, 'addPolicy' ),
                'DELETE' => array( $roleController, 'deletePolicies' ),
            ),
            '(^/user/roles/[0-9]+/policies/[0-9]+$)' => array(
                'GET'    => array( $roleController, 'loadPolicy' ),
                'PATCH'  => array( $roleController, 'updatePolicy' ),
                'DELETE' => array( $roleController, 'deletePolicy' ),
            ),
            '(^/user/users/[0-9]+/roles$)' => array(
                'GET'  => array( $roleController, 'loadRoleAssignmentsForUser' ),
                'POST'  => array( $roleController, 'assignRoleToUser' ),
            ),
            '(^/user/users/[0-9]+/roles/[0-9]+$)' => array(
                'GET'  => array( $roleController, 'loadRoleAssignmentForUser' ),
                'DELETE'  => array( $roleController, 'unassignRoleFromUser' ),
            ),
            '(^/user/groups/root$)' => array(
                'GET'  => array( $userController, 'loadRootUserGroup' ),
            ),
            '(^/user/groups/[0-9/]+$)' => array(
                'GET'  => array( $userController, 'loadUserGroup' ),
            ),
            '(^/user/groups/[0-9/]+/roles$)' => array(
                'GET'  => array( $roleController, 'loadRoleAssignmentsForUserGroup' ),
                'POST'  => array( $roleController, 'assignRoleToUserGroup' ),
            ),
            '(^/user/groups/[0-9/]+/roles/[0-9]+$)' => array(
                'GET'  => array( $roleController, 'loadRoleAssignmentForUserGroup' ),
                'DELETE'  => array( $roleController, 'unassignRoleFromUserGroup' ),
            ),
        )
    ),
    new RMF\View\AcceptHeaderViewDispatcher(
        array(
            '(^application/vnd\\.ez\\.api\\.[A-Za-z]+\\+json$)' => (
                $jsonVisitor = new View\Visitor(
                    new Common\Output\Visitor(
                        new Common\Output\Generator\Json(
                            new Common\Output\Generator\Json\FieldTypeHashGenerator()
                        ),
                        $valueObjectVisitors
                    )
                )
            ),
            '(^application/vnd\\.ez\\.api\\.[A-Za-z]+\\+xml$)'  => (
                $xmlVisitor = new View\Visitor(
                    new Common\Output\Visitor(
                        new Common\Output\Generator\Xml(
                            new Common\Output\Generator\Xml\FieldTypeHashGenerator()
                        ),
                        $valueObjectVisitors
                    )
                )
            ),
            '(^application/json$)'  => $jsonVisitor,
            '(^application/xml$)'  => $xmlVisitor,
            // '(^.*/.*$)'  => new View\InvalidApiUse(),
            // Fall back gracefully to XML visiting. Also helps support responses
            // without Accept header (e.g. DELETE requests).
            '(^.*/.*$)'  => $xmlVisitor,
        )
    ),
    // This is just used for integration tests, DO NOT USE IN PRODUCTION
    new Authenticator\IntegrationTest( $repository )
    // For productive use, e.g. use
    // new Authenticator\BasicAuth( $repository )
);

/*
 * The simple request abstraction class provided by RMF allows handlers to be
 * registered, which extract request data and provide it via property access in
 * a manor of lazy loading.
 */

$request = new RMF\Request\HTTP();
$request->addHandler( 'body', new RMF\Request\PropertyHandler\RawBody() );

$request->addHandler(
    'contentType',
    new RMF\Request\PropertyHandler\Override(
        array(
            new RMF\Request\PropertyHandler\Server( 'CONTENT_TYPE' ),
            new RMF\Request\PropertyHandler\Server( 'HTTP_CONTENT_TYPE' ),
        )
    )
);

$request->addHandler(
    'method',
    new RMF\Request\PropertyHandler\Override(
        array(
            new RMF\Request\PropertyHandler\Server( 'HTTP_X_HTTP_METHOD_OVERRIDE' ),
            new RMF\Request\PropertyHandler\Server( 'REQUEST_METHOD' ),
        )
    )
);

$request->addHandler( 'destination', new RMF\Request\PropertyHandler\Server( 'HTTP_DESTINATION' ) );

// ATTENTION: Only used for test setup
$request->addHandler( 'testUser', new RMF\Request\PropertyHandler\Server( 'HTTP_X_TEST_USER' ) );

// For the use of Authenticator\BasicAuth:
// $request->addHandler( 'username', new RMF\Request\PropertyHandler\Server( 'PHP_AUTH_USER' ) );
// $request->addHandler( 'password', new RMF\Request\PropertyHandler\Server( 'PHP_AUTH_PW' ) );

/*
 * This triggers working of the MVC.
 */
$dispatcher->dispatch( $request );
