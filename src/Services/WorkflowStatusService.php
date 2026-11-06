<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Services;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;

/**
 * Provides workflow status data for a target record and handles
 * transition execution and commenting for the sitetree UI panel.
 */
class WorkflowStatusService
{
    use Injectable;

    private const HISTORY_LIMIT = 10;
    private const STATUS_COMPLETE = 'Complete';
    private const STATUS_CANCELLED = 'Cancelled';

    /**
     * Retrieve the full workflow status payload for a given target record.
     *
     * @return array<string, mixed>|null Null if no active workflow exists.
     */
    public function getStatusForTarget(int $targetId, string $targetClass): ?array
    {
        $instance = $this->getActiveInstance($targetId, $targetClass);

        if (!$instance instanceof WorkflowInstance || !$instance->exists()) {
            return null;
        }

        $currentAction = $instance->CurrentAction();
        $definition = $instance->Definition();

        if (!$definition instanceof WorkflowDefinition || !$definition->exists()) {
            return null;
        }

        $isFinished = in_array($instance->WorkflowStatus, [self::STATUS_COMPLETE, self::STATUS_CANCELLED], true);
        $executedActionIds = $this->getExecutedActionIds($instance);
        $isRejected = $isFinished && $this->wasRejected($instance);
        $isPublished = $isFinished && !$isRejected && $this->wasPublishedAfterWorkflow($targetId, $targetClass, $instance);

        $actions = $definition->Actions()->sort('Sort');
        $phases = $this->buildPhases($actions, $currentAction, $executedActionIds, $isFinished, $isRejected);

        // Only show transitions if the current user has access to act on this workflow
        $transitions = ['next' => [], 'back' => []];

        if (!$isFinished && $instance->canEdit()) {
            $transitions = $this->classifyTransitions($currentAction, $instance);
        }

        $currentTitle = null;
        if ($isRejected) {
            $currentTitle = 'Rejected';
        } elseif ($isFinished && $isPublished) {
            $currentTitle = 'Published';
        } elseif ($isFinished && !$isRejected) {
            $currentTitle = 'Approved — Ready to publish';
        } elseif ($currentAction instanceof WorkflowActionInstance && $currentAction->exists()) {
            $currentTitle = $this->getPhaseLabel($currentAction);
        }

        // Check if current action allows commenting — only if user has available transitions
        $allowCommenting = false;
        $hasTransitions = !empty($transitions['next']) || !empty($transitions['back']);

        if (!$isFinished && $hasTransitions && $currentAction instanceof WorkflowActionInstance && $currentAction->exists()) {
            $baseAction = $currentAction->BaseAction();

            if ($baseAction instanceof WorkflowAction && $baseAction->exists()) {
                $allowCommenting = (bool)$baseAction->AllowCommenting;
            }
        }

        $history = $this->getHistory($instance);

        // When workflow is finished, provide option to start a new one
        $availableWorkflows = [];

        if ($isFinished) {
            $availableWorkflows = $this->getAvailableWorkflows($targetId, $targetClass);
        }

        return [
            'instanceId' => $instance->ID,
            'workflowStatus' => $instance->WorkflowStatus,
            'isRejected' => $isRejected,
            'current' => $currentTitle,
            'assignedTo' => $this->getAssignedUserName($instance),
            'steps' => $phases['items'],
            'currentIndex' => $phases['currentIndex'],
            'nextTransitions' => $transitions['next'],
            'backTransitions' => $transitions['back'],
            'allowCommenting' => $allowCommenting,
            'latestComment' => $this->findLatestComment($history),
            'history' => $history,
            'availableWorkflows' => $availableWorkflows,
        ];
    }

    /**
     * Execute a workflow transition by ID, optionally with a comment.
     *
     * @throws \InvalidArgumentException When transition or target cannot be found.
     */
    public function executeTransitionById(int $transitionId, ?string $comment = null): void
    {
        $transition = WorkflowTransition::get()->byID($transitionId);

        if (!$transition instanceof WorkflowTransition || !$transition->exists()) {
            throw new \InvalidArgumentException('Transition not found');
        }

        $action = $transition->Action();

        if (!$action instanceof WorkflowAction || !$action->exists()) {
            throw new \InvalidArgumentException('Action not found for transition');
        }

        $definition = $action->WorkflowDef();

        if (!$definition instanceof WorkflowDefinition || !$definition->exists()) {
            throw new \InvalidArgumentException('Workflow definition not found');
        }

        $instance = WorkflowInstance::get()
            ->filter([
                'DefinitionID' => $definition->ID,
                'WorkflowStatus' => ['Active', 'Paused'],
            ])
            ->sort('Created', 'DESC')
            ->first();

        if (!$instance instanceof WorkflowInstance || !$instance->exists()) {
            throw new \InvalidArgumentException('No active workflow instance found');
        }

        $target = $instance->getTarget();

        if (!$target instanceof DataObject) {
            throw new \InvalidArgumentException('Workflow target not found');
        }

        // Capture the action being acted on BEFORE the transition finishes it and
        // advances (or completes) the workflow. On completion CurrentActionID is
        // reset to 0, so reading the current action afterwards would drop the
        // comment for the final step.
        $actedActionId = (int)$instance->CurrentActionID;

        $workflowService = Injector::inst()->get(WorkflowService::class);
        $workflowService->executeTransition($target, $transitionId);

        if ($comment !== null && trim($comment) !== '') {
            $this->attachComment($actedActionId, $comment);
        }
    }

    /**
     * Add a comment to an existing workflow instance.
     *
     * @throws \InvalidArgumentException When instance cannot be found.
     */
    public function addCommentById(int $instanceId, string $comment): void
    {
        $instance = WorkflowInstance::get()->byID($instanceId);

        if (!$instance instanceof WorkflowInstance || !$instance->exists()) {
            throw new \InvalidArgumentException('Workflow instance not found');
        }

        $this->addCommentToInstance($instance, $comment);
    }

    /**
     * Retrieve available groups and members for workflow assignment.
     *
     * @return array{groups: array<int, string>, users: array<int, string>}
     */
    private const USER_SEARCH_LIMIT = 30;

    /**
     * Assignment metadata for the builder. Groups are returned in full (small,
     * bounded list); users are intentionally NOT returned here — they are
     * searched on demand via searchUsers() so a site with many accounts does
     * not bloat the page payload.
     *
     * @return array<string, mixed>
     */
    public function getAssignmentMeta(): array
    {
        return [
            'groups' => Group::get()->map('ID', 'Title')->toArray(),
            'users' => [],
        ];
    }

    /**
     * Search members by name/email for the assignment picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function searchUsers(string $term): array
    {
        $members = Member::get();
        $term = trim($term);

        if ($term !== '') {
            $members = $members->filterAny([
                'FirstName:PartialMatch' => $term,
                'Surname:PartialMatch' => $term,
                'Email:PartialMatch' => $term,
            ]);
        }

        $members = $members->sort('FirstName')->limit(self::USER_SEARCH_LIMIT);
        $results = [];

        foreach ($members as $member) {
            $results[] = ['id' => (int)$member->ID, 'name' => $this->memberLabel($member)];
        }

        return $results;
    }

    /**
     * Resolve display labels for a set of member IDs (used to render chips for
     * already-selected users without loading the full member list).
     *
     * @param array<int, mixed> $ids
     * @return array<int, string> Map of member ID to label.
     */
    public function getUserLabels(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));

        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach (Member::get()->byIDs($ids) as $member) {
            $labels[(int)$member->ID] = $this->memberLabel($member);
        }

        return $labels;
    }

    private function memberLabel(Member $member): string
    {
        $name = trim(($member->FirstName ?? '') . ' ' . ($member->Surname ?? ''));

        if ($name === '') {
            return (string)($member->Email ?: ('Member #' . $member->ID));
        }

        return $member->Email ? sprintf('%s (%s)', $name, $member->Email) : $name;
    }

    private function getActiveInstance(int $targetId, string $targetClass): ?WorkflowInstance
    {
        $classesToCheck = array_values(ClassInfo::ancestry($targetClass));

        return WorkflowInstance::get()
            ->filter([
                'TargetID' => $targetId,
                'TargetClass' => $classesToCheck,
            ])
            ->sort('Created', 'DESC')
            ->first();
    }

    /**
     * Get available workflow definitions that can be started on a target record.
     *
     * @return array<int, array{id: int, title: string}>
     */
    private function getAvailableWorkflows(int $targetId, string $targetClass): array
    {
        // Ensure we read from Draft stage (API may default to Live)
        $record = Versioned::get_by_stage($targetClass, Versioned::DRAFT)->byID($targetId);

        if (!$record instanceof DataObject || !$record->exists()) {
            $classesToCheck = array_values(ClassInfo::ancestry($targetClass));

            foreach ($classesToCheck as $class) {
                $record = Versioned::get_by_stage($class, Versioned::DRAFT)->byID($targetId);

                if ($record instanceof DataObject && $record->exists()) {
                    break;
                }
            }
        }

        if (!$record instanceof DataObject || !$record->exists()) {
            return [];
        }

        $workflowService = Injector::inst()->get(WorkflowService::class);

        // Only check for active/paused — if one exists, can't start another
        $activeInstance = $workflowService->getWorkflowFor($record, false);

        if ($activeInstance) {
            return [];
        }

        $definitions = $workflowService->getDefinitionsFor($record);
        $available = [];

        if (!$definitions) {
            return [];
        }

        foreach ($definitions as $definition) {
            $initialAction = $definition->getInitialAction();

            if ($initialAction) {
                $available[] = [
                    'id' => (int)$definition->ID,
                    'title' => $definition->InitialActionButtonText
                        ?: 'Request Approval',
                ];
            }
        }

        return $available;
    }

    /**
     * Check if the target page was published after the workflow completed.
     * Compares the live record's LastEdited against the workflow's completion time.
     */
    private function wasPublishedAfterWorkflow(int $targetId, string $targetClass, WorkflowInstance $instance): bool
    {
        $record = DataObject::get_by_id($targetClass, $targetId);

        if (!$record instanceof DataObject) {
            return false;
        }

        if (!$record->hasMethod('isPublished') || !$record->isPublished()) {
            return false;
        }

        $workflowCompleted = strtotime($instance->LastEdited ?? '');

        if ($workflowCompleted === false) {
            return false;
        }

        $liveRecord = Versioned::get_by_stage($targetClass, Versioned::LIVE)->byID($targetId);

        if (!$liveRecord) {
            return false;
        }

        $liveLastEdited = strtotime($liveRecord->LastEdited ?? '');

        return $liveLastEdited >= $workflowCompleted;
    }

    /**
     * Build editor-facing phases from the raw workflow actions.
     * Filters out internal automation steps and shows only meaningful phases:
     * Submitted → [Approval steps] → Published/Rejected
     *
     * @return array{items: array<int, array<string, mixed>>, currentIndex: int}
     */
    private function buildPhases(
        $actions,
        ?WorkflowActionInstance $currentAction,
        array $executedActionIds,
        bool $isFinished,
        bool $isRejected
    ): array {
        $phases = [];
        $currentBaseActionId = null;
        $currentSort = null;

        if ($currentAction instanceof WorkflowActionInstance && $currentAction->exists()) {
            $currentBaseActionId = (int)$currentAction->BaseActionID;
            $baseAction = $currentAction->BaseAction();
            if ($baseAction instanceof WorkflowAction && $baseAction->exists()) {
                $currentSort = (int)$baseAction->Sort;
            }
        }

        // Identify user-facing actions (approval steps) from the definition
        $approvalActions = [];
        foreach ($actions as $action) {
            if ($this->isApprovalAction($action->ClassName)) {
                $approvalActions[] = $action;
            }
        }

        // Phase 1: Submitted (always present — completed once workflow starts)
        $phases[] = [
            'id' => 'submitted',
            'title' => 'Submitted',
            'status' => 'completed',
        ];

        // Phase 2+: Each approval action becomes a phase
        $foundActive = false;
        foreach ($approvalActions as $action) {
            $status = 'pending';

            if ($currentBaseActionId === (int)$action->ID) {
                $status = 'active';
                $foundActive = true;
            } elseif (in_array((int)$action->ID, $executedActionIds, true)) {
                $status = 'completed';
            } elseif (!$foundActive && $currentSort !== null && $currentSort < (int)$action->Sort) {
                $status = 'active';
                $foundActive = true;
            }

            $phases[] = [
                'id' => $action->ID,
                'title' => $action->Title,
                'status' => $status,
            ];
        }

        // Final phase: Approved (ready to publish) or Rejected
        $finalStatus = 'pending';
        $finalTitle = 'Approved';
        if ($isRejected) {
            $finalStatus = 'rejected';
            $finalTitle = 'Rejected';
        } elseif ($isFinished && !$isRejected) {
            $finalStatus = 'completed';
        }

        $phases[] = [
            'id' => 'outcome',
            'title' => $finalTitle,
            'status' => $finalStatus,
        ];

        // Determine currentIndex
        $currentIndex = 0;
        foreach ($phases as $i => $phase) {
            if ($phase['status'] === 'active') {
                $currentIndex = $i;
                break;
            }
            if ($phase['status'] === 'rejected') {
                $currentIndex = $i;
                break;
            }
        }

        // If workflow is finished and no active phase found, point to the last phase
        if ($isFinished && $currentIndex === 0) {
            $currentIndex = count($phases) - 1;
        }

        return [
            'items' => $phases,
            'currentIndex' => $currentIndex,
        ];
    }

    /**
     * Check if an action class is a user-facing approval action.
     */
    private function isApprovalAction(string $className): bool
    {
        $shortName = strtolower(basename(str_replace('\\', '/', $className)));

        return str_contains($shortName, 'approval') || str_contains($shortName, 'simpleapproval');
    }

    /**
     * Get a user-friendly phase label for the current action.
     */
    private function getPhaseLabel(WorkflowActionInstance $currentAction): string
    {
        $baseAction = $currentAction->BaseAction();

        if (!$baseAction instanceof WorkflowAction || !$baseAction->exists()) {
            return $currentAction->getTitle();
        }

        if ($this->isApprovalAction($baseAction->ClassName)) {
            return $baseAction->Title;
        }

        // For non-approval actions (assign, notify), show as "Processing"
        return 'Processing';
    }

    /**
     * Classify transitions into forward/back groups, filtered by canExecute permission.
     *
     * @return array{next: array<int, array<string, mixed>>, back: array<int, array<string, mixed>>}
     */
    private function classifyTransitions(?WorkflowActionInstance $currentAction, ?WorkflowInstance $instance = null): array
    {
        $next = [];
        $back = [];

        if (!$currentAction instanceof WorkflowActionInstance || !$currentAction->exists()) {
            return ['next' => $next, 'back' => $back];
        }

        $baseAction = $currentAction->BaseAction();

        if (!$baseAction instanceof WorkflowAction || !$baseAction->exists()) {
            return ['next' => $next, 'back' => $back];
        }

        $transitions = $baseAction->Transitions();
        $currentSort = (int)$baseAction->Sort;

        foreach ($transitions as $transition) {
            // Only show transitions this user is allowed to execute
            if ($instance instanceof WorkflowInstance && !$transition->canExecute($instance)) {
                continue;
            }

            $targetAction = $transition->NextAction();

            if (!$targetAction instanceof WorkflowAction || !$targetAction->exists()) {
                continue;
            }

            $entry = [
                'id' => $transition->ID,
                'title' => $transition->Title,
            ];

            if ((int)$targetAction->Sort > $currentSort) {
                $next[] = $entry;
            } else {
                $back[] = $entry;
            }
        }

        return ['next' => $next, 'back' => $back];
    }

    private function getAssignedUserName(WorkflowInstance $instance): ?string
    {
        $users = $instance->Users();

        if ($users->count() > 0) {
            $first = $users->first();
            return $first instanceof Member ? $first->getName() : null;
        }

        $groups = $instance->Groups();

        if ($groups->count() > 0) {
            $first = $groups->first();
            return $first instanceof Group ? $first->Title : null;
        }

        return null;
    }

    /**
     * @return array<int, array{message: string|null, date: string|null, author: string|null}>
     */
    /**
     * Get IDs of all definition actions that were executed in this workflow instance.
     *
     * @return int[]
     */
    private function getExecutedActionIds(WorkflowInstance $instance): array
    {
        $actionInstances = WorkflowActionInstance::get()
            ->filter([
                'WorkflowID' => $instance->ID,
                'Finished' => 1,
            ]);

        $ids = [];
        foreach ($actionInstances as $actionInstance) {
            $baseId = (int)$actionInstance->BaseActionID;
            if ($baseId > 0) {
                $ids[] = $baseId;
            }
        }

        return array_unique($ids);
    }

    /**
     * Determine if a finished workflow ended via a rejection path.
     * Checks the executed action titles for rejection indicators.
     */
    private function wasRejected(WorkflowInstance $instance): bool
    {
        $actionInstances = WorkflowActionInstance::get()
            ->filter([
                'WorkflowID' => $instance->ID,
                'Finished' => 1,
            ])
            ->sort('Created', 'DESC');

        foreach ($actionInstances as $actionInstance) {
            $title = strtolower($actionInstance->getTitle());

            if (str_contains($title, 'reject') || str_contains($title, 'cancel')) {
                return true;
            }
        }

        return false;
    }

    private function getHistory(WorkflowInstance $instance): array
    {
        $actionInstances = WorkflowActionInstance::get()
            ->filter([
                'WorkflowID' => $instance->ID,
                'Finished' => 1,
            ])
            ->sort('Created', 'DESC')
            ->limit(self::HISTORY_LIMIT);

        $history = [];

        foreach ($actionInstances as $actionInstance) {
            $member = $actionInstance->Member();
            $baseAction = $actionInstance->BaseAction();
            $title = $actionInstance->getTitle();
            $comment = trim((string)$actionInstance->Comment);
            $actionType = $baseAction instanceof WorkflowAction && $baseAction->exists()
                ? $this->resolveActionType($baseAction->ClassName)
                : 'action';

            $history[] = [
                'title' => $title,
                'comment' => $comment !== '' ? $comment : null,
                'date' => $actionInstance->Created,
                'actionType' => $actionType,
                'author' => $member instanceof Member && $member->exists()
                    ? $member->getName()
                    : null,
            ];
        }

        return $history;
    }

    /**
     * Find the most recent comment from history entries.
     * Returns a standalone object for display, or null if no comments exist.
     *
     * @param array<int, array<string, mixed>> $history Sorted newest-first.
     * @return array{comment: string, author: string|null, step: string}|null
     */
    private function findLatestComment(array $history): ?array
    {
        foreach ($history as $entry) {
            if ($entry['comment'] !== null && trim((string)$entry['comment']) !== '') {
                return [
                    'comment' => $entry['comment'],
                    'author' => $entry['author'],
                    'step' => $entry['title'],
                ];
            }
        }

        return null;
    }

    /**
     * Resolve a WorkflowAction class name to a human-readable type.
     */
    private function resolveActionType(string $className): string
    {
        $shortName = strtolower(basename(str_replace('\\', '/', $className)));

        if (str_contains($shortName, 'assign')) {
            return 'assignment';
        }

        if (str_contains($shortName, 'notify') || str_contains($shortName, 'email')) {
            return 'notification';
        }

        if (str_contains($shortName, 'publish')) {
            return 'publish';
        }

        if (str_contains($shortName, 'approve') || str_contains($shortName, 'approval')) {
            return 'approval';
        }

        if (str_contains($shortName, 'reject') || str_contains($shortName, 'cancel')) {
            return 'rejection';
        }

        return 'action';
    }

    private function addCommentToInstance(WorkflowInstance $instance, string $comment): void
    {
        $this->attachComment((int)$instance->CurrentActionID, $comment);
    }

    /**
     * Persist a comment onto a specific action-instance record, re-fetched fresh
     * by ID so writing only touches its Comment (never resurrecting stale state).
     */
    private function attachComment(int $actionInstanceId, string $comment): void
    {
        if ($actionInstanceId <= 0) {
            return;
        }

        $actionInstance = WorkflowActionInstance::get()->byID($actionInstanceId);

        if ($actionInstance instanceof WorkflowActionInstance && $actionInstance->exists()) {
            $actionInstance->Comment = $comment;
            $actionInstance->write();
        }
    }
}
