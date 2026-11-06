<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Services;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;
use Symbiote\AdvancedWorkflow\Enum\WorkflowStepType;

/**
 * Loads and saves WorkflowDefinitions for the visual workflow builder UI.
 *
 * Saving is NON-DESTRUCTIVE and SUBCLASS-AWARE: existing actions and
 * transitions are reconciled in place (matched, updated, created, or deleted)
 * so that configuration the builder does not itself edit — email templates,
 * publish delays, user/group assignments, transition restrictions — is
 * preserved across saves, and each step persists as its real executable
 * WorkflowAction subclass rather than a bare base action.
 */
class WorkflowBuilderService
{
    use Injectable;

    /**
     * Per-type config keys exposed to the builder, mapped to their DB field.
     * Base keys apply to every action; type keys are merged on top.
     *
     * @var array<string, string>
     */
    private const BASE_CONFIG_FIELDS = [
        'allowEditing' => 'AllowEditing',
        'allowCommenting' => 'AllowCommenting',
        'comment' => 'Comment',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const TYPE_CONFIG_FIELDS = [
        'notification' => [
            'emailSubject' => 'EmailSubject',
            'emailFrom' => 'EmailFrom',
            'emailTemplate' => 'EmailTemplate',
        ],
        'publish' => [
            'publishDelay' => 'PublishDelay',
            'allowEmbargoedEditing' => 'AllowEmbargoedEditing',
        ],
        'unpublish' => [
            'unpublishDelay' => 'UnpublishDelay',
        ],
        'assign' => [
            'assignInitiator' => 'AssignInitiator',
        ],
        'setproperty' => [
            'property' => 'Property',
            'value' => 'Value',
        ],
    ];

    /**
     * Boolean config keys, so values round-trip as real booleans in the JSON.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_CONFIG_KEYS = ['allowCommenting', 'allowEmbargoedEditing', 'assignInitiator'];

    /**
     * Integer config keys.
     *
     * @var array<int, string>
     */
    private const INTEGER_CONFIG_KEYS = ['publishDelay', 'unpublishDelay'];

    /**
     * Load a workflow definition and its actions/transitions for the builder UI.
     *
     * @return array<string, mixed>|null
     */
    public function loadDefinition(int $definitionId): ?array
    {
        $definition = WorkflowDefinition::get()->byID($definitionId);

        if (!$definition instanceof WorkflowDefinition || !$definition->exists()) {
            return null;
        }

        $steps = [];

        foreach ($definition->Actions()->sort('Sort') as $action) {
            $steps[] = $this->actionToStep($action);
        }

        $definitionUserIds = $this->toIntList($definition->Users()->column('ID'));

        return [
            'id' => (int)$definition->ID,
            'title' => $definition->Title,
            'description' => $definition->Description,
            'initialActionButtonText' => $definition->InitialActionButtonText,
            'remindDays' => (int)$definition->RemindDays,
            'groupIds' => $this->toIntList($definition->Groups()->column('ID')),
            'userIds' => $definitionUserIds,
            'steps' => $steps,
            // Labels for the already-selected users only, so the picker can show
            // their chips without loading the full member list.
            'userLabels' => $this->resolveUserLabels($definitionUserIds, $steps),
        ];
    }

    /**
     * Collect every referenced user id (definition-level + per-step assignments)
     * and resolve display labels for them.
     *
     * @param array<int, int> $definitionUserIds
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, string>
     */
    private function resolveUserLabels(array $definitionUserIds, array $steps): array
    {
        $ids = $definitionUserIds;

        foreach ($steps as $step) {
            $stepUserIds = $step['config']['userIds'] ?? [];
            if (is_array($stepUserIds)) {
                $ids = array_merge($ids, $stepUserIds);
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return Injector::inst()->get(WorkflowStatusService::class)->getUserLabels($ids);
    }

    /**
     * Map a stored WorkflowAction to the builder's step representation.
     *
     * @return array<string, mixed>
     */
    private function actionToStep(WorkflowAction $action): array
    {
        $type = WorkflowStepType::fromActionClass(get_class($action));
        $transitions = [];
        $branches = [];

        foreach ($action->Transitions() as $transition) {
            $nextAction = $transition->NextAction();

            if (!$nextAction instanceof WorkflowAction || !$nextAction->exists()) {
                continue;
            }

            $nextId = (string)$nextAction->ID;
            $transitions[] = [
                'id' => (string)$transition->ID,
                'title' => $transition->Title,
                'nextStepId' => $nextId,
                'transitionType' => $transition->Type,
                'userIds' => $this->toIntList($transition->Users()->column('ID')),
                'groupIds' => $this->toIntList($transition->Groups()->column('ID')),
            ];

            // Derived approve/reject view retained for the current UI.
            $branches[$this->resolveBranchType($transition->Title)] = ['nextStepId' => $nextId];
        }

        return [
            'id' => (string)$action->ID,
            'title' => $action->Title,
            'type' => $type->value,
            'config' => $this->extractConfig($action, $type),
            'transitions' => $transitions,
            'branches' => $branches,
        ];
    }

    /**
     * Read the builder-editable config for an action, keyed by JSON config key.
     *
     * @return array<string, mixed>
     */
    private function extractConfig(WorkflowAction $action, WorkflowStepType $type): array
    {
        $config = [];

        foreach ($this->configFieldMap($type) as $key => $dbField) {
            $config[$key] = $this->normaliseConfigValue($key, $action->getField($dbField));
        }

        if ($type->assignsUsers()) {
            $config['userIds'] = $this->toIntList($action->Users()->column('ID'));
            $config['groupIds'] = $this->toIntList($action->Groups()->column('ID'));
        }

        return $config;
    }

    /**
     * Save a workflow definition from the builder UI JSON payload.
     *
     * @param array<string, mixed> $data
     * @return array{status: string, id: int}
     */
    public function saveDefinition(array $data): array
    {
        $definition = $this->resolveDefinition($data);
        $this->applyDefinitionMeta($definition, $data);

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];

        $actionMap = $this->reconcileActions($definition, $steps);
        $this->reconcileTransitions($steps, $actionMap);

        return [
            'status' => 'ok',
            'id' => (int)$definition->ID,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveDefinition(array $data): WorkflowDefinition
    {
        $definitionId = isset($data['id']) ? (int)$data['id'] : 0;

        if ($definitionId > 0) {
            $definition = WorkflowDefinition::get()->byID($definitionId);

            if ($definition instanceof WorkflowDefinition && $definition->exists()) {
                return $definition;
            }
        }

        return WorkflowDefinition::create();
    }

    /**
     * Set only the definition metadata the payload actually carries, so keys the
     * builder omits are never clobbered.
     *
     * @param array<string, mixed> $data
     */
    private function applyDefinitionMeta(WorkflowDefinition $definition, array $data): void
    {
        $definition->Title = (string)($data['title'] ?? $definition->Title ?: 'Untitled Workflow');

        if (array_key_exists('description', $data)) {
            $definition->Description = (string)$data['description'];
        }

        if (array_key_exists('initialActionButtonText', $data)) {
            $definition->InitialActionButtonText = (string)$data['initialActionButtonText'];
        }

        if (array_key_exists('remindDays', $data)) {
            $definition->RemindDays = (int)$data['remindDays'];
        }

        $definition->write();

        if (array_key_exists('groupIds', $data) && is_array($data['groupIds'])) {
            $definition->Groups()->setByIDList($this->toIntList($data['groupIds']));
        }

        if (array_key_exists('userIds', $data) && is_array($data['userIds'])) {
            $definition->Users()->setByIDList($this->toIntList($data['userIds']));
        }
    }

    /**
     * Reconcile the definition's actions against the incoming steps in place.
     *
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, WorkflowAction> Map of incoming step id to its action.
     */
    private function reconcileActions(WorkflowDefinition $definition, array $steps): array
    {
        $existing = [];
        foreach ($definition->Actions() as $action) {
            $existing[(int)$action->ID] = $action;
        }

        $map = [];
        $keptIds = [];

        foreach (array_values($steps) as $index => $step) {
            $stepId = (string)($step['id'] ?? '');
            $existingAction = $existing[(int)$stepId] ?? null;

            // Sort is 1-based: WorkflowAction::onBeforeWrite() treats a falsy
            // Sort (0) as "unset" and reassigns MAX(Sort)+1 across the whole
            // table, which would scramble step order on every save.
            $action = $this->upsertAction($definition, $existingAction, $step, $index + 1);

            $map[$stepId] = $action;
            $keptIds[(int)$action->ID] = true;
        }

        foreach ($existing as $id => $action) {
            if (!isset($keptIds[$id])) {
                $this->deleteAction($action);
            }
        }

        return $map;
    }

    /**
     * Delete an action and its own outgoing transition records (not merely
     * orphan them, which HasManyList::removeAll would do).
     */
    private function deleteAction(WorkflowAction $action): void
    {
        foreach ($action->Transitions() as $transition) {
            $transition->delete();
        }

        $action->delete();
    }

    /**
     * Create or update a single action, converting its class if the step type
     * changed, and applying config without clobbering untouched fields.
     *
     * @param array<string, mixed> $step
     */
    private function upsertAction(
        WorkflowDefinition $definition,
        ?WorkflowAction $existing,
        array $step,
        int $sort
    ): WorkflowAction {
        $type = WorkflowStepType::fromValue($step['type'] ?? null);
        $targetClass = $type->actionClass();

        if ($existing instanceof WorkflowAction) {
            $action = get_class($existing) === $targetClass
                ? $existing
                : $existing->newClassInstance($targetClass);
        } else {
            $action = $targetClass::create();
        }

        $action->Title = (string)($step['title'] ?? 'Untitled Step');
        $action->Sort = $sort;
        $action->WorkflowDefID = $definition->ID;
        $action->write();

        $this->applyActionConfig($action, $type, is_array($step['config'] ?? null) ? $step['config'] : []);

        return $action;
    }

    /**
     * Apply builder config to an action. Only keys present in the payload are
     * written, preserving any config the builder did not send.
     *
     * @param array<string, mixed> $config
     */
    private function applyActionConfig(WorkflowAction $action, WorkflowStepType $type, array $config): void
    {
        $dirty = false;

        foreach ($this->configFieldMap($type) as $key => $dbField) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $action->setField($dbField, $this->denormaliseConfigValue($key, $config[$key]));
            $dirty = true;
        }

        if ($dirty) {
            $action->write();
        }

        if (!$type->assignsUsers()) {
            return;
        }

        if (array_key_exists('userIds', $config) && is_array($config['userIds'])) {
            $action->Users()->setByIDList($this->toIntList($config['userIds']));
        }

        if (array_key_exists('groupIds', $config) && is_array($config['groupIds'])) {
            $action->Groups()->setByIDList($this->toIntList($config['groupIds']));
        }
    }

    /**
     * Reconcile every step's transitions in place.
     *
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, WorkflowAction> $actionMap
     */
    private function reconcileTransitions(array $steps, array $actionMap): void
    {
        foreach ($steps as $step) {
            $stepId = (string)($step['id'] ?? '');

            if (!isset($actionMap[$stepId])) {
                continue;
            }

            $this->reconcileActionTransitions(
                $actionMap[$stepId],
                $this->desiredTransitions($step),
                $actionMap
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $desired
     * @param array<string, WorkflowAction> $actionMap
     */
    private function reconcileActionTransitions(
        WorkflowAction $action,
        array $desired,
        array $actionMap
    ): void {
        $existing = [];
        $existingByTitle = [];
        foreach ($action->Transitions() as $transition) {
            $existing[(int)$transition->ID] = $transition;
            $existingByTitle[strtolower($transition->Title)] = $transition;
        }

        $keptIds = [];

        foreach ($desired as $spec) {
            $target = $actionMap[$spec['nextStepId']] ?? null;

            if (!$target instanceof WorkflowAction) {
                continue;
            }

            $transition = $this->matchTransition($spec, $existing, $existingByTitle)
                ?? WorkflowTransition::create();

            $transition->Title = (string)$spec['title'];
            $transition->ActionID = $action->ID;
            $transition->NextActionID = $target->ID;

            if (isset($spec['transitionType'])) {
                $transition->Type = (string)$spec['transitionType'];
            }

            $transition->write();
            $keptIds[(int)$transition->ID] = true;

            $this->applyTransitionRestrictions($transition, $spec);
        }

        foreach ($existing as $id => $transition) {
            if (!isset($keptIds[$id])) {
                $transition->delete();
            }
        }
    }

    /**
     * Find the existing transition a spec refers to — by id first, then title.
     *
     * @param array<string, mixed> $spec
     * @param array<int, WorkflowTransition> $existing
     * @param array<string, WorkflowTransition> $existingByTitle
     */
    private function matchTransition(array $spec, array $existing, array $existingByTitle): ?WorkflowTransition
    {
        $id = (int)($spec['id'] ?? 0);
        if ($id > 0 && isset($existing[$id])) {
            return $existing[$id];
        }

        $title = strtolower((string)($spec['title'] ?? ''));

        return $existingByTitle[$title] ?? null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function applyTransitionRestrictions(WorkflowTransition $transition, array $spec): void
    {
        if (array_key_exists('userIds', $spec) && is_array($spec['userIds'])) {
            $transition->Users()->setByIDList($this->toIntList($spec['userIds']));
        }

        if (array_key_exists('groupIds', $spec) && is_array($spec['groupIds'])) {
            $transition->Groups()->setByIDList($this->toIntList($spec['groupIds']));
        }
    }

    /**
     * Normalise a step's transitions into a common spec list. Prefers an
     * explicit `transitions` array; falls back to the approve/reject `branches`
     * shape used by the current UI.
     *
     * @param array<string, mixed> $step
     * @return array<int, array<string, mixed>>
     */
    private function desiredTransitions(array $step): array
    {
        if (isset($step['transitions']) && is_array($step['transitions'])) {
            return array_values(array_filter(
                array_map([$this, 'normaliseTransitionSpec'], $step['transitions']),
                static fn ($spec) => $spec !== null
            ));
        }

        $branches = is_array($step['branches'] ?? null) ? $step['branches'] : [];
        $specs = [];

        foreach ($branches as $branchName => $branch) {
            $nextStepId = (string)($branch['nextStepId'] ?? '');

            if ($nextStepId === '') {
                continue;
            }

            $specs[] = [
                'title' => ucfirst((string)$branchName),
                'nextStepId' => $nextStepId,
            ];
        }

        return $specs;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    private function normaliseTransitionSpec($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $nextStepId = (string)($raw['nextStepId'] ?? '');

        if ($nextStepId === '') {
            return null;
        }

        return [
            'id' => $raw['id'] ?? null,
            'title' => (string)($raw['title'] ?? 'Next'),
            'nextStepId' => $nextStepId,
            'transitionType' => $raw['transitionType'] ?? null,
            'userIds' => $raw['userIds'] ?? null,
            'groupIds' => $raw['groupIds'] ?? null,
        ];
    }

    /**
     * The config key => DB field map for a given step type.
     *
     * @return array<string, string>
     */
    private function configFieldMap(WorkflowStepType $type): array
    {
        return array_merge(self::BASE_CONFIG_FIELDS, self::TYPE_CONFIG_FIELDS[$type->value] ?? []);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normaliseConfigValue(string $key, $value)
    {
        if (in_array($key, self::BOOLEAN_CONFIG_KEYS, true)) {
            return (bool)$value;
        }

        if (in_array($key, self::INTEGER_CONFIG_KEYS, true)) {
            return (int)$value;
        }

        return $value === null ? '' : (string)$value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function denormaliseConfigValue(string $key, $value)
    {
        if (in_array($key, self::BOOLEAN_CONFIG_KEYS, true)) {
            return (int)(bool)$value;
        }

        if (in_array($key, self::INTEGER_CONFIG_KEYS, true)) {
            return (int)$value;
        }

        return (string)$value;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function toIntList(array $ids): array
    {
        return array_values(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));
    }

    private function resolveBranchType(string $transitionTitle): string
    {
        $lower = strtolower($transitionTitle);

        if (str_contains($lower, 'reject') || str_contains($lower, 'back') || str_contains($lower, 'change')) {
            return 'reject';
        }

        return 'approve';
    }
}
