<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Controllers;

use Exception;
use SilverStripe\Admin\AdminController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\Services\WorkflowBuilderService;
use Symbiote\AdvancedWorkflow\Services\WorkflowStatusService;

/**
 * JSON API for the workflow status panel and the visual workflow builder.
 * Parses requests and checks permissions; WorkflowStatusService and
 * WorkflowBuilderService hold the logic.
 *
 * Endpoint URLs reach the client through the admin client config, so they
 * follow the site's base URL and admin route.
 */
class WorkflowBuilderController extends AdminController
{
    private static ?string $url_segment = 'workflow-ui';

    /**
     * The status panel renders in CMS edit forms, so access to any one CMS
     * section is enough; each endpoint then checks the record or definition.
     */
    private static string|array $required_permission_codes = 'CMS_ACCESS';

    private static array $allowed_actions = WorkflowBuilderController::ENDPOINTS;

    private const ENDPOINTS = [
        'workflowMeta',
        'searchUsers',
        'workflowData',
        'loadDefinition',
        'saveWorkflow',
    ];

    public function getClientConfig(): array
    {
        $endpoints = [];

        foreach (WorkflowBuilderController::ENDPOINTS as $action) {
            $endpoints[$action] = Director::absoluteURL($this->Link($action));
        }

        return array_merge(parent::getClientConfig(), ['endpoints' => $endpoints]);
    }

    public function workflowMeta(): HTTPResponse
    {
        if (!$this->canManageWorkflows()) {
            return $this->forbidden();
        }

        $service = Injector::inst()->get(WorkflowStatusService::class);

        return $this->jsonResponse($service->getAssignmentMeta());
    }

    public function searchUsers(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canManageWorkflows()) {
            return $this->forbidden();
        }

        $term = (string)$request->getVar('term');
        $service = Injector::inst()->get(WorkflowStatusService::class);

        return $this->jsonResponse($service->searchUsers($term));
    }

    public function workflowData(HTTPRequest $request): HTTPResponse
    {
        $recordId = (int)$request->getVar('ID');
        $className = (string)$request->getVar('ClassName');

        if ($recordId <= 0 || $className === '') {
            return $this->jsonResponse(['error' => 'Missing ID or ClassName'], 400);
        }

        if (!$this->isTargetClass($className)) {
            return $this->jsonResponse(['error' => 'Unknown ClassName'], 400);
        }

        $record = $this->findTargetRecord($className, $recordId);

        if (!$record instanceof DataObject) {
            return $this->jsonResponse(['error' => 'Record not found'], 404);
        }

        if (!$record->canView()) {
            return $this->forbidden();
        }

        $service = Injector::inst()->get(WorkflowStatusService::class);

        return $this->jsonResponse($service->getPanelData($record));
    }

    public function loadDefinition(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canManageWorkflows()) {
            return $this->forbidden();
        }

        $definitionId = (int)$request->getVar('ID');

        if ($definitionId <= 0) {
            return $this->jsonResponse(['error' => 'Missing definition ID'], 400);
        }

        $service = Injector::inst()->get(WorkflowBuilderService::class);
        $data = $service->loadDefinition($definitionId);

        if ($data === null) {
            return $this->jsonResponse(['error' => 'Definition not found'], 404);
        }

        return $this->jsonResponse($data);
    }

    public function saveWorkflow(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['error' => 'Invalid security token'], 400);
        }

        if (!$this->canManageWorkflows()) {
            return $this->forbidden();
        }

        $data = json_decode($request->getBody(), true);

        if (!is_array($data)) {
            return $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        $service = Injector::inst()->get(WorkflowBuilderService::class);

        try {
            return $this->jsonResponse($service->saveDefinition($data));
        } catch (Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Builder endpoints manage workflow definitions (CREATE_WORKFLOW).
     */
    private function canManageWorkflows(): bool
    {
        return WorkflowDefinition::singleton()->canCreate();
    }

    private function forbidden(): HTTPResponse
    {
        return $this->jsonResponse(['error' => 'You do not have permission to perform this action.'], 403);
    }

    /**
     * A ClassName supplied by the client is untrusted — reject anything that is
     * not a real DataObject subclass before it reaches the ORM, which fatals on
     * unknown class strings.
     */
    private function isTargetClass(string $className): bool
    {
        return is_subclass_of($className, DataObject::class, true);
    }

    /**
     * Resolve a target record, reading from the draft stage for versioned
     * classes. Returns null when the record does not exist.
     */
    private function findTargetRecord(string $className, int $recordId): ?DataObject
    {
        $record = DataObject::has_extension($className, Versioned::class)
            ? Versioned::get_by_stage($className, Versioned::DRAFT)->byID($recordId)
            : DataObject::get($className)->byID($recordId);

        return $record instanceof DataObject && $record->exists() ? $record : null;
    }

    private function jsonResponse(mixed $data, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create((string)json_encode($data), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
