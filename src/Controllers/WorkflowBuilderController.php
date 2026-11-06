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
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Symbiote\AdvancedWorkflow\Services\WorkflowStatusService;

/**
 * Thin controller for the workflow builder and status panel API endpoints.
 * All business logic is delegated to WorkflowStatusService and WorkflowBuilderService.
 */
class WorkflowBuilderController extends Controller
{
    private static $allowed_actions = [
        'workflowMeta',
        'searchUsers',
        'workflowData',
        'loadDefinition',
        'saveWorkflow',
        'executeTransition',
        'startWorkflow',
        'addComment',
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
     * Front-end panel endpoints run inside the CMS edit form — require a
     * logged-in CMS user.
     */
    private function canUseCms(): bool
    {
        return Permission::check('CMS_ACCESS_LeftAndMain') || Permission::check('ADMIN');
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

        $service = Injector::inst()->get(WorkflowStatusService::class);
        $data = $service->getStatusForTarget($recordId, $className);

        if ($data === null) {
            // No active workflow — return available definitions for starting
            $record = $this->findTargetRecord($className, $recordId);
            $availableDefinitions = [];

            if ($record instanceof DataObject) {
                $workflowService = Injector::inst()->get(WorkflowService::class);
                $definitions = $workflowService->getDefinitionsFor($record);

                if ($definitions) {
                    foreach ($definitions as $definition) {
                        if ($definition->getInitialAction()) {
                            $availableDefinitions[] = [
                                'id' => (int)$definition->ID,
                                'title' => $definition->InitialActionButtonText
                                    ?: 'Request Approval',
                            ];
                        }
                    }
                }
            }

            return $this->jsonResponse([
                'workflow' => null,
                'availableWorkflows' => $availableDefinitions,
            ]);
        }

        return $this->jsonResponse($data);
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

    public function executeTransition(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canUseCms()) {
            return $this->forbidden();
        }

        $body = json_decode($request->getBody(), true);
        $transitionId = (int)($body['TransitionID'] ?? $request->postVar('TransitionID') ?? 0);
        $comment = $body['Comment'] ?? $request->postVar('Comment') ?? null;

        if ($transitionId <= 0) {
            return $this->jsonResponse(['error' => 'Missing TransitionID'], 400);
        }

        $service = Injector::inst()->get(WorkflowStatusService::class);

        try {
            $service->executeTransitionById($transitionId, $comment);

            return $this->jsonResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function addComment(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canUseCms()) {
            return $this->forbidden();
        }

        $body = json_decode($request->getBody(), true);
        $instanceId = (int)($body['InstanceID'] ?? $request->postVar('InstanceID') ?? 0);
        $comment = $body['Comment'] ?? $request->postVar('Comment') ?? '';

        if ($instanceId <= 0 || trim((string)$comment) === '') {
            return $this->jsonResponse(['error' => 'Missing InstanceID or Comment'], 400);
        }

        $service = Injector::inst()->get(WorkflowStatusService::class);

        try {
            $service->addCommentById($instanceId, (string)$comment);

            return $this->jsonResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function startWorkflow(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canUseCms()) {
            return $this->forbidden();
        }

        $body = json_decode($request->getBody(), true);
        $recordId = (int)($body['RecordID'] ?? 0);
        $className = $body['ClassName'] ?? '';
        $definitionId = (int)($body['DefinitionID'] ?? 0);

        if ($recordId <= 0 || $className === '') {
            return $this->jsonResponse(['error' => 'Missing RecordID or ClassName'], 400);
        }

        if (!$this->isTargetClass($className)) {
            return $this->jsonResponse(['error' => 'Unknown ClassName'], 400);
        }

        $record = $this->findTargetRecord($className, $recordId);

        if (!$record instanceof DataObject) {
            return $this->jsonResponse(['error' => 'Record not found'], 404);
        }

        $workflowService = Injector::inst()->get(WorkflowService::class);

        try {
            // startWorkflow returns void — throws on failure
            $workflowService->startWorkflow($record, $definitionId ?: null);

            return $this->jsonResponse(['success' => true]);
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
