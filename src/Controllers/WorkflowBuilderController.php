<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\Services\WorkflowBuilderService;
use Symbiote\AdvancedWorkflow\Services\WorkflowStatusService;

/**
 * Thin controller for the workflow builder and status panel API endpoints.
 * All business logic is delegated to WorkflowStatusService and WorkflowBuilderService.
 */
class WorkflowBuilderController extends Controller
{
    /**
     * Permission::check() treats this code as "holds any CMS_ACCESS_* code".
     */
    private const ANY_CMS_ACCESS = 'CMS_ACCESS';

    private static $allowed_actions = [
        'workflowMeta',
        'searchUsers',
        'workflowData',
        'loadDefinition',
        'saveWorkflow',
    ];

    protected function init(): void
    {
        parent::init();

        // Set reading mode to Draft for all operations (CMS context)
        Versioned::set_reading_mode('Stage.Stage');
    }

    /**
     * Builder/admin endpoints manage workflow definitions — restrict to users
     * who may create/edit them (CREATE_WORKFLOW via WorkflowDefinition::canCreate).
     */
    private function canManageWorkflows(): bool
    {
        return WorkflowDefinition::singleton()->canCreate();
    }

    /**
     * The status panel renders inside CMS edit forms, so any user with access
     * to at least one CMS section may call it; per-record access is checked
     * against the record itself.
     */
    private function canUseCms(): bool
    {
        return Permission::check(WorkflowBuilderController::ANY_CMS_ACCESS);
    }

    private function forbidden(): HTTPResponse
    {
        return $this->jsonResponse(['error' => 'You do not have permission to perform this action.'], 403);
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
        if (!$this->canUseCms()) {
            return $this->forbidden();
        }

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
        if (!$this->canManageWorkflows()) {
            return $this->forbidden();
        }

        $body = $request->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        $service = Injector::inst()->get(WorkflowBuilderService::class);

        try {
            $result = $service->saveDefinition($data);

            return $this->jsonResponse($result);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
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
