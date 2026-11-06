<?php

namespace Symbiote\AdvancedWorkflow\Services;

use Exception;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use Symbiote\AdvancedWorkflow\Admin\WorkflowDefinitionImporter;
use Symbiote\AdvancedWorkflow\Extensions\FileWorkflowApplicable;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowTransition;

/**
 * A central point for interacting with workflows
 *
 * @author  marcus@symbiote.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */

class WorkflowService implements PermissionProvider
{
    /**
     * An array of templates that we can create from
     *
     * @var array
     */
    protected $templates;

    /**
     * Set the list of templates that can be created
     *
     * @param array $templates
     */
    public function setTemplates($templates)
    {
        $this->templates = $templates;
    }

    /**
     * Return the list of available templates
     * @return array
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    /**
     * Get a template by name
     *
     * @param string $name
     * @return WorkflowTemplate|null
     */
    public function getNamedTemplate($name)
    {
        if ($importedTemplate = singleton(WorkflowDefinitionImporter::class)->getImportedWorkflows($name)) {
            return $importedTemplate;
        }

        if (!is_array($this->templates)) {
            return;
        }
        foreach ($this->templates as $template) {
            if ($template->getName() == $name) {
                return $template;
            }
        }
    }

    /**
     * Gets the workflow definition for a given dataobject, if there is one
     *
     * Will recursively query parent elements until it finds one, if available
     *
     * @param DataObject $dataObject
     */
    public function getDefinitionFor(DataObject $dataObject)
    {
        if ($dataObject->hasExtension(WorkflowApplicable::class)
            || $dataObject->hasExtension(FileWorkflowApplicable::class)
        ) {
            if ($dataObject->WorkflowDefinitionID) {
                $definition = WorkflowDefinition::get()->setUseCache(true)->byID($dataObject->WorkflowDefinitionID);

                if ($definition) {
                    return $definition;
                }
                // Definition was deleted — fall through to parent inheritance
            }
            if ($dataObject->hasMethod('useInheritedWorkflow') && !$dataObject->useInheritedWorkflow()) {
                return null;
            }
            if ($dataObject->ParentID) {
                return $this->getDefinitionFor($dataObject->Parent());
            }
            if ($dataObject->hasMethod('workflowParent')) {
                $obj = $dataObject->workflowParent();
                if ($obj) {
                    return $this->getDefinitionFor($obj);
                }
            }
        }
        return null;
    }

    /**
     *  Retrieves a workflow definition by ID for a data object.
     *
     *  @param DataObject $object
     *  @param integer $workflowID
     *  @return WorkflowDefinition|null
     */
    public function getDefinitionByID($object, $workflowID)
    {
        // Make sure the correct extensions have been applied to the data object.

        $workflow = null;
        if ($object->hasExtension(WorkflowApplicable::class)
            || $object->hasExtension(FileWorkflowApplicable::class)
        ) {
            // Validate the workflow ID against the data object.

            if (($object->WorkflowDefinitionID == $workflowID)
                || ($workflow = $object->AdditionalWorkflowDefinitions()->byID($workflowID))
            ) {
                if (is_null($workflow)) {
                    $workflow = WorkflowDefinition::get()->setUseCache(true)->byID($workflowID);
                }
            }
        }
        return $workflow ? $workflow : null;
    }

    /**
     * Retrieves and collates the workflow definitions for a data object, where the first element will be the
     * main workflow definition.
     *
     * @param DataObject object
     * @return array
     */

    public function getDefinitionsFor($object)
    {

        // Retrieve the main workflow definition.

        $default = $this->getDefinitionFor($object);
        if ($default) {
            // Merge the additional workflow definitions.

            return array_merge(array(
                $default
            ), $object->AdditionalWorkflowDefinitions()->toArray());
        }
        return null;
    }

    /**
     * Gets the workflow for the given item
     *
     * The item can be
     *
     * a data object in which case the ActiveWorkflow will be returned,
     * an action, in which case the Workflow will be returned
     * an integer, in which case the workflow with that ID will be returned
     *
     * @param mixed $item
     * @param bool $includeComplete
     * @return WorkflowInstance|null
     */
    public function getWorkflowFor($item, $includeComplete = false)
    {
        $id = $item;

        if ($item instanceof WorkflowAction) {
            $id = $item->WorkflowID;
            return WorkflowInstance::get()->setUseCache(true)->byID($id);
        } elseif (is_object($item) && ($item->hasExtension(WorkflowApplicable::class)
                || $item->hasExtension(FileWorkflowApplicable::class))
        ) {
            $statuses = ['Active', 'Paused'];
            if ($includeComplete) {
                $statuses[] = 'Complete';
            }
            return WorkflowInstance::get()->setUseCache(true)->filter([
                'TargetClass' => DataObject::getSchema()->baseDataClass($item),
                'TargetID' => $item->ID,
                'WorkflowStatus' => $statuses,
            ])->first();
        }
    }

    /**
     * Get all the workflow action instances for an item
     *
     * @return DataList<WorkflowActionInstance>|void
     */
    public function getWorkflowHistoryFor($item, $limit = null)
    {
        if ($active = $this->getWorkflowFor($item, true)) {
            $limit = $limit ? "0,$limit" : '';
            return $active->Actions('', 'ID DESC ', null, $limit);
        }
    }

    /**
     * Get all the available workflow definitions
     *
     * @return DataList<WorkflowDefinition>
     */
    public function getDefinitions()
    {
        return DataList::create(WorkflowDefinition::class);
    }

    /**
     * Given a transition ID, figure out what should happen to
     * the given $subject.
     *
     * In the normal case, this will load the current workflow instance for the object
     * and then transition as expected. However, in some cases (eg to start the workflow)
     * it is necessary to instead create a new instance.
     *
     * @param DataObject $target
     * @param int $transitionId
     * @throws Exception
     */
    public function executeTransition(DataObject $target, $transitionId)
    {
        $workflow   = $this->getWorkflowFor($target);
        $transition = WorkflowTransition::get()->setUseCache(true)->byID($transitionId);

        if (!$transition) {
            throw new Exception(_t('WorkflowService.INVALID_TRANSITION_ID', "Invalid transition ID $transitionId"));
        }

        if (!$workflow) {
            throw new Exception(_t(
                'WorkflowService.INVALID_WORKFLOW_TARGET',
                "A transition was executed on a target that does not have a workflow."
            ));
        }

        if ($transition->Action()->WorkflowDefID != $workflow->DefinitionID) {
            throw new Exception(_t(
                'WorkflowService.INVALID_TRANSITION_WORKFLOW',
                "Transition #$transition->ID is not attached to workflow #$workflow->ID."
            ));
        }

        $workflow->performTransition($transition);
    }

    /**
     * Starts the workflow for the given data object, assuming it or a parent has
     * a definition specified.
     *
     * @param DataObject $object
     * @param int $workflowID
     */
    public function startWorkflow(DataObject $object, $workflowID = null)
    {
        $existing = $this->getWorkflowFor($object);
        if ($existing) {
            throw new ExistingWorkflowException(_t(
                'WorkflowService.EXISTING_WORKFLOW_ERROR',
                "That object already has a workflow running"
            ));
        }

        $definition = null;
        if ($workflowID) {
            // Retrieve the workflow definition that has been triggered.

            $definition = $this->getDefinitionByID($object, $workflowID);
        }
        if (is_null($definition)) {
            // Fall back to the main workflow definition.

            $definition = $this->getDefinitionFor($object);
        }

        if ($definition) {
            $instance = new WorkflowInstance();
            $instance->beginWorkflow($definition, $object);
            $instance->execute();
        }
    }

    /**
     * Get all the workflows that this user is responsible for
     *
     * @param Member $user The user to get workflows for
     * @return ArrayList<WorkflowInstance> The list of workflow instances this user owns
     */
    public function usersWorkflows(Member $user)
    {
        $groupIds = $user->Groups()->column('ID');

        $groupInstances = null;

        $filter = array('');

        if (is_array($groupIds)) {
            $groupInstances = DataList::create(WorkflowInstance::class)
                ->filter(array('Group.ID:ExactMatchMulti' => $groupIds))
                ->where('"WorkflowStatus" != \'Complete\'');
        }

        $userInstances = DataList::create(WorkflowInstance::class)
            ->filter(array('Users.ID:ExactMatch' => $user->ID))
            ->where('"WorkflowStatus" != \'Complete\'');

        if ($userInstances) {
            $userInstances = $userInstances->toArray();
        } else {
            $userInstances = array();
        }

        if ($groupInstances) {
            $groupInstances = $groupInstances->toArray();
        } else {
            $groupInstances = array();
        }

        $all = array_merge($groupInstances, $userInstances);

        return ArrayList::create($all);
    }

    /**
     * Get items that the passed-in user has awaiting for them to action
     *
     * @param Member $member
     * @return DataList<WorkflowInstance>
     */
    public function userPendingItems(Member $user)
    {
        // Don't restrict anything for ADMIN users
        $userInstances = DataList::create(WorkflowInstance::class)
            ->where('"WorkflowStatus" != \'Complete\'')
            ->sort('LastEdited DESC');

        if (Permission::checkMember($user, 'ADMIN')) {
            return $userInstances;
        }
        $instances = new ArrayList();
        foreach ($userInstances as $inst) {
            $instToArray = $inst->getAssignedMembers();
            if (!count($instToArray ?? [])>0 || !in_array($user->ID, $instToArray->column() ?? [])) {
                continue;
            }
            $instances->push($inst);
        }

        return $instances;
    }

    /**
     * Get items that the passed-in user has submitted for workflow review
     *
     * @param Member $member
     * @return DataList<WorkflowInstance>
     */
    public function userSubmittedItems(Member $user)
    {
        $userInstances = DataList::create(WorkflowInstance::class)
            ->where('"WorkflowStatus" != \'Complete\'')
            ->sort('LastEdited DESC');

        // Restrict the user if they're not an ADMIN.
        if (!Permission::checkMember($user, 'ADMIN')) {
            $userInstances = $userInstances->filter('InitiatorID:ExactMatch', $user->ID);
        }

        return $userInstances;
    }

    /**
     * Generate a workflow definition based on a template
     *
     * @param WorkflowDefinition $definition
     * @param string $templateName
     * @return WorkflowDefinition|null
     */
    public function defineFromTemplate(WorkflowDefinition $definition, $templateName)
    {
        $template = null;
        /* @var $template WorkflowTemplate */

        if (!is_array($this->templates)) {
            return;
        }

        $template = $this->getNamedTemplate($templateName);

        if (!$template) {
            return;
        }

        $template->createRelations($definition);

        // Set the version and do the write at the end so that we don't trigger an infinite loop!!
        if (!$definition->Description) {
            $definition->Description = $template->getDescription();
        }
        $definition->TemplateVersion = $template->getVersion();
        $definition->RemindDays = $template->getRemindDays();
        $definition->Sort = $template->getSort();
        $definition->write();
        return $definition;
    }

    /**
     * Reorders actions within a definition
     *
     * @param WorkflowDefinition|WorkflowAction $objects The objects to be reordered
     * @param array $newOrder An array of IDs of the actions in the order they should be.
     */
    public function reorder($objects, $newOrder)
    {
        $sortVals = array_values($objects->map('ID', 'Sort')->toArray() ?? []);
        sort($sortVals);

        // save the new ID values - but only use existing sort values to prevent
        // conflicts with items not in the table
        foreach ($newOrder as $key => $id) {
            if (!$id) {
                continue;
            }
            $object = $objects->find('ID', $id);
            $object->Sort = $sortVals[$key];
            $object->write();
        }
    }

    /**
     *
     * @return array
     */
    public function providePermissions()
    {
        return array(
            'CREATE_WORKFLOW' => array(
                'name' => _t('AdvancedWorkflow.CREATE_WORKFLOW', 'Create workflow'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help' => _t('AdvancedWorkflow.CREATE_WORKFLOW_HELP', 'Users can create workflow definitions'),
                'sort' => 0
            ),
            'DELETE_WORKFLOW' => array(
                'name' => _t('AdvancedWorkflow.DELETE_WORKFLOW', 'Delete workflow'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help' => _t(
                    'AdvancedWorkflow.DELETE_WORKFLOW_HELP',
                    'Users can delete workflow definitions and active workflows'
                ),
                'sort' => 1
            ),
            'APPLY_WORKFLOW' => array(
                'name' => _t('AdvancedWorkflow.APPLY_WORKFLOW', 'Apply workflow'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help' => _t('AdvancedWorkflow.APPLY_WORKFLOW_HELP', 'Users can apply workflows to items'),
                'sort' => 2
            ),
            'VIEW_ACTIVE_WORKFLOWS' => array(
                'name'     => _t('AdvancedWorkflow.VIEWACTIVE', 'View active workflows'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help'     => _t(
                    'AdvancedWorkflow.VIEWACTIVEHELP',
                    'Users can view active workflows via the workflows admin panel'
                ),
                'sort'     => 3
            ),
            'REASSIGN_ACTIVE_WORKFLOWS' => array(
                'name'     => _t('AdvancedWorkflow.REASSIGNACTIVE', 'Reassign active workflows'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help'     => _t(
                    'AdvancedWorkflow.REASSIGNACTIVEHELP',
                    'Users can reassign active workflows to different users and groups'
                ),
                'sort'     => 4
            ),
            'EDIT_EMBARGOED_WORKFLOW' => array(
                'name'     => _t('AdvancedWorkflow.EDITEMBARGO', 'Editable embargoed item in workflow'),
                'category' => _t('AdvancedWorkflow.ADVANCED_WORKFLOW', 'Advanced Workflow'),
                'help'     => _t(
                    'AdvancedWorkflow.EDITEMBARGOHELP',
                    'Allow users to edit items that have been embargoed by a workflow'
                ),
                'sort'     => 5
            ),
        );
    }
}
