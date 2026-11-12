<?php

namespace Symbiote\AdvancedWorkflow\DataObjects;

use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TreeMultiselectField;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\DataDifferencer;
use SilverStripe\Versioned\Versioned;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use Symbiote\AdvancedWorkflow\Extensions\FileWorkflowApplicable;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;

/**
 * A WorkflowInstance is created whenever a user 'starts' a workflow.
 *
 * This 'start' is triggered automatically when the user clicks the relevant
 * button (eg 'apply for approval'). This creates a standalone object
 * that maintains the state of the workflow process.
 *
 * @method WorkflowDefinition Definition()
 * @method WorkflowActionInstance CurrentAction()
 * @method Member Initiator()
 * @method HasManyList<WorkflowActionInstance> Actions()
 * @method ManyManyList<Member> Users()
 * @method ManyManyList<Group> Groups()
 *
 * @author  marcus@symbiote.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
class WorkflowInstance extends DataObject
{
    private static $db = array(
        'Title'             => 'Varchar(128)',
        'WorkflowStatus'    => "Enum('Active,Paused,Complete,Cancelled','Active')",
        'TargetClass'       => 'Varchar(255)',
        'TargetID'          => 'Int',
    );

    private static $has_one = array(
        'Definition'    => WorkflowDefinition::class,
        'CurrentAction' => WorkflowActionInstance::class,
        'Initiator'     => Member::class,
    );

    private static $has_many = array(
        'Actions' => WorkflowActionInstance::class,
    );

    /**
     * The list of users who are responsible for performing the current WorkflowAction
     *
     * @var array
     */
    private static $many_many = array(
        'Users'  => Member::class,
        'Groups' => Group::class,
    );

    private static $summary_fields = array(
        'Title',
        'WorkflowStatus',
        'Created'
    );

    private static $default_sort = array(
        '"Created"' => 'DESC'
    );

    /**
     * If set to true, actions that cannot be executed by the user will not show
     * on the frontend (just like the backend).
     *
     * @var boolean
     */
    private static $hide_disabled_actions_on_frontend = false;

    /**
     * Fields to ignore when generating a diff for data objects.
     */
    private static $diff_ignore_fields = array(
        'LastEdited',
        'Created',
        'workflowService',
        'ParentID',
        'Sort',
        'PublishJobID',
        'UnPublishJobID'
    );

    private static $table_name = 'WorkflowInstance';

    /**
     * Get the CMS view of the instance. This is used to display the log of
     * this workflow, and options to reassign if the workflow hasn't been
     * finished yet
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = new FieldList();
        $fields->push(new TabSet('Root', new Tab('Main')));

        if (Permission::check('REASSIGN_ACTIVE_WORKFLOWS')) {
            if ($this->WorkflowStatus == 'Paused' || $this->WorkflowStatus == 'Active') {
                $cmsUsers = Member::mapInCMSGroups();

                $fields->addFieldsToTab('Root.Main', array(
                    new HiddenField('DirectUpdate', '', 1),
                    new HeaderField(
                        'InstanceReassignHeader',
                        _t('WorkflowInstance.REASSIGN_HEADER', 'Reassign workflow')
                    ),
                    new CheckboxSetField('Users', _t('WorkflowDefinition.USERS', 'Users'), $cmsUsers),
                    new TreeMultiselectField('Groups', _t('WorkflowDefinition.GROUPS', 'Groups'), Group::class)
                ));
            }
        }

        if ($this->canEdit()) {
            $action = $this->CurrentAction();
            if ($action->exists()) {
                $actionFields = $this->getWorkflowFields();
                $fields->addFieldsToTab('Root.Main', $actionFields->toArray());

                $transitions = $action->getValidTransitions();
                if ($transitions) {
                    $fields->replaceField(
                        'TransitionID',
                        DropdownField::create("TransitionID", "Next action", $transitions->map())
                    );
                }
            }
        }

        $items = WorkflowActionInstance::get()->filter(array(
            'Finished'   => 1,
            'WorkflowID' => $this->ID
        ));

        $grid = GridField::create(
            'Actions',
            _t('WorkflowInstance.ActionLogTitle', 'Log'),
            $items
        );

        $fields->addFieldToTab('Root.Main', $grid);

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Title'] = _t('WorkflowInstance.TitleLabel', 'Title');
        $labels['WorkflowStatus'] = _t('WorkflowInstance.WorkflowStatusLabel', 'Workflow Status');
        $labels['TargetClass'] = _t('WorkflowInstance.TargetClassLabel', 'Target Class');
        $labels['TargetID'] = _t('WorkflowInstance.TargetIDLabel', 'Target');

        return $labels;
    }

    /**
     * See if we've been saved in context of managing the workflow directly
     */
    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $vars = $this->record;

        if (isset($vars['DirectUpdate'])) {
            // Unset now so that we don't end up in an infinite loop!
            unset($this->record['DirectUpdate']);
            $this->updateWorkflow($vars);
        }
    }

    /**
     * Update the current state of the workflow
     *
     * Typically, this is triggered by someone modifiying the workflow instance via the modeladmin form
     * side of things when administering things, such as re-assigning or manually approving a stuck workflow
     *
     * Note that this is VERY similar to AdvancedWorkflowExtension::updateworkflow
     * but without the formy bits. These two implementations should PROBABLY
     * be merged
     *
     * @todo refactor with AdvancedWorkflowExtension
     *
     * @param type $data
     * @return
     */
    public function updateWorkflow($data)
    {
        $action = $this->CurrentAction();

        if (!$this->getTarget() || !$this->getTarget()->canEditWorkflow()) {
            return;
        }

        $allowedFields = $this->getWorkflowFields()->saveableFields();
        unset($allowedFields['TransitionID']);
        foreach ($allowedFields as $field) {
            $fieldName = $field->getName();
            $action->$fieldName = $data[$fieldName];
        }
        $action->write();

        $svc = singleton(WorkflowService::class);
        if (isset($data['TransitionID']) && $data['TransitionID']) {
            $svc->executeTransition($this->getTarget(), $data['TransitionID']);
        } else {
            // otherwise, just try to execute the current workflow to see if it
            // can now proceed based on user input
            $this->execute();
        }
    }

    /**
     * Get the target-object that this WorkflowInstance "points" to.
     *
     * Workflows are not restricted to being active on SiteTree objects,
     * so we need to account for being attached to anything.
     *
     * Sets Versioned::set_reading_mode() to allow fetching of Draft _and_ Published
     * content.
     *
     * @param boolean $getLive
     * @return null|DataObject
     */
    public function getTarget($getLive = false)
    {
        if ($this->TargetID && $this->TargetClass) {
            $versionable = Injector::inst()->get($this->TargetClass)->has_extension(Versioned::class);
            $targetObject = null;

            if (!$versionable && $getLive) {
                return;
            }
            if ($versionable) {
                $targetObject = Versioned::get_by_stage(
                    $this->TargetClass,
                    $getLive ? Versioned::LIVE : Versioned::DRAFT
                )->byID($this->TargetID);
            }
            if (!$targetObject) {
                $targetObject = DataObject::get($this->TargetClass)->setUseCache(true)->byID($this->TargetID);
            }

            return $targetObject;
        }
    }

    /**
     *
     * @param boolean $getLive
     * @see {@link {$this->getTarget()}
     * @return null|DataObject
     */
    public function Target($getLive = false)
    {
        return $this->getTarget($getLive);
    }

    /**
     * Returns the field differences between the older version and current version of Target
     *
     * @return ArrayList<ArrayData>
     */
    public function getTargetDiff()
    {
        $liveTarget = $this->Target(true);
        $draftTarget = $this->Target();

        $diff = DataDifferencer::create($liveTarget, $draftTarget);
        $diff->ignoreFields($this->config()->get('diff_ignore_fields'));

        $fields = ArrayList::create();

        try {
            if ($diff && !(is_null($diff->fromRecord) && is_null($diff->toRecord))) {
                $fields = $diff->ChangedFields();
            }
        } catch (\InvalidArgumentException $iae) {
            // noop
        }

        return $fields;
    }

    /**
     * Start a workflow based on a particular definition for a particular object.
     *
     * The object is optional; if not specified, it is assumed that this workflow
     * is simply a task based checklist type of workflow.
     *
     * @param WorkflowDefinition $definition
     * @param DataObject $for
     */
    public function beginWorkflow(WorkflowDefinition $definition, ?DataObject $for = null)
    {
        if (!$this->ID) {
            $this->write();
        }

        if ($for
            && ($for->hasExtension(WorkflowApplicable::class)
                || $for->hasExtension(FileWorkflowApplicable::class))
        ) {
            $this->TargetClass = DataObject::getSchema()->baseDataClass($for);
            $this->TargetID = $for->ID;
        }

        // lets create the first WorkflowActionInstance.
        $action = $definition->getInitialAction()->getInstanceForWorkflow();
        $action->WorkflowID = $this->ID;
        $action->write();

        $title = $for && $for->hasField('Title')
            ? sprintf(_t('WorkflowInstance.TITLE_FOR_DO', '%s - %s'), $definition->Title, $for->Title)
            : sprintf(_t('WorkflowInstance.TITLE_STUB', 'Instance #%s of %s') ?? '', $this->ID, $definition->Title);

        $this->Title           = $title;
        $this->DefinitionID    = $definition->ID;
        $this->CurrentActionID = $action->ID;
        $this->InitiatorID     = Security::getCurrentUser()->ID;
        $this->write();

        $this->Users()->addMany($definition->Users());
        $this->Groups()->addMany($definition->Groups());
    }

    /**
     * Execute this workflow. In rare cases this will actually execute all actions,
     * but typically, it will stop and wait for the user to input something
     *
     * The basic process is to get the current action, and see whether it has been finished
     * by some process, if not it attempts to execute it.
     *
     * If it has been finished, we check to see if there's some transitions to follow. If there's
     * only one transition, then we execute that immediately.
     *
     * If there's multiple transitions, we just stop and wait for the user to manually
     * trigger a transition.
     *
     * If there's no transitions, we make the assumption that we've finished the workflow and
     * mark it as such.
     *
     *
     */
    public function execute()
    {
        if (!$this->CurrentActionID) {
            throw new Exception(
                sprintf(_t(
                    'WorkflowInstance.EXECUTE_EXCEPTION',
                    'Attempted to start an invalid workflow instance #%s!'
                ) ?? '', $this->ID)
            );
        }

        $action     = $this->CurrentAction();
        $transition = false;

        // if the action has already finished, it means it has either multiple (or no
        // transitions at the time), so a subsequent check should be run.
        if ($action->Finished) {
            $transition = $this->checkTransitions($action);
        } else {
            $result = $action->BaseAction()->execute($this);

            // if the action was successful, then the action has finished running and
            // next transition should be run (if only one).
            // input.
            if ($result) {
                $action->MemberID = Security::getCurrentUser()->ID;
                $action->Finished = true;
                $action->write();
                $transition = $this->checkTransitions($action);
            }
        }

        // if the action finished, and there's only one available transition then
        // move onto that step - otherwise check if the workflow has finished.
        if ($transition) {
            $this->performTransition($transition);
        } else {
            // see if there are any transitions available, even if they are not valid.
            if ($action->Finished && !count($action->BaseAction()->Transitions() ?? [])) {
                $this->WorkflowStatus  = 'Complete';
                $this->CurrentActionID = 0;
            } else {
                $this->WorkflowStatus = 'Paused';
            }

            $this->write();
        }
    }

    /**
     * Evaluate all the transitions of an action and determine whether we should
     * follow any of them yet.
     *
     * @param  WorkflowActionInstance $action
     * @return WorkflowTransition
     */
    protected function checkTransitions(WorkflowActionInstance $action)
    {
        $transitions = $action->getValidTransitions();
        // if there's JUST ONE transition, then we need should
        // immediately follow it.
        if ($transitions && $transitions->count() == 1) {
            return $transitions->First();
        }
    }

    /**
     * Transitions a workflow to the next step defined by the given transition.
     *
     * After transitioning, the action is 'executed', and next steps
     * determined.
     *
     * @param WorkflowTransition $transition
     */
    public function performTransition(WorkflowTransition $transition)
    {
        // first make sure that the transition is valid to execute!
        $action          = $this->CurrentAction();
        $allTransitions  = $action->BaseAction()->Transitions();

        $valid = $allTransitions->filter('ID', $transition->ID)->exists();
        if (!$valid) {
            throw new Exception(
                sprintf(_t(
                    'WorkflowInstance.WORKFLOW_TRANSITION_EXCEPTION',
                    'Invalid transition state for action #%s'
                ) ?? '', $action->ID)
            );
        }

        $action->actionComplete($transition);

        $definition = WorkflowAction::get()->setUseCache(true)->byID($transition->NextActionID);
        $action = $definition->getInstanceForWorkflow();
        $action->WorkflowID   = $this->ID;
        $action->write();

        $this->CurrentActionID = $action->ID;
        $this->write();
        $this->components = array(); // manually clear the has_one cache

        $action->actionStart($transition);

        $transition->extend('onTransition');
        $this->execute();
    }

    /**
     * Returns a list of all Members that are assigned to this instance, either directly or via a group.
     *
     * @todo   This could be made more efficient.
     * @return ArrayList<Member>
     */
    public function getAssignedMembers()
    {
        $list   = new ArrayList();
        $groups = $this->Groups();

        $list->merge($this->Users());

        foreach ($groups as $group) {
            $list->merge($group->Members());
        }

        $list->removeDuplicates();
        return $list;
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        $hasAccess = $this->userHasAccess($member);
        /*
         * If the next action is AssignUsersToWorkflowAction, execute() resets all user+group relations.
         * Therefore current user no-longer has permission to view this WorkflowInstance in PendingObjects
         * Gridfield, even though;
         * - She had permissions granted via the workflow definition to run the preceeding Action that took her here.
         */
        if (!$hasAccess) {
            if ($this->getMostRecentActionForUser($member)) {
                return true;
            }
        }
        return $hasAccess;
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return $this->userHasAccess($member);
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        if (Permission::checkMember($member, "DELETE_WORKFLOW")) {
            return true;
        }
        return false;
    }

    /**
     * Checks whether the given user is in the list of users assigned to this
     * workflow
     *
     * @param Member $member
     */
    protected function userHasAccess($member)
    {
        if (!$member) {
            if (!Security::getCurrentUser()) {
                return false;
            }
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }

        // This method primarily "protects" access to a WorkflowInstance, but assumes access only to be granted to
        // users assigned-to that WorkflowInstance. However; lowly authors (users entering items into a workflow) are
        // not assigned - but we still wish them to see their submitted content.
        $inWorkflowGroupOrUserTables = $this->Users()->filter('ID', $member->ID)->exists()
            || $member->inGroups($this->Groups());
        // This method is used in more than just the ModelAdmin. Check for the current controller to determine where
        // canView() expectations differ
        if ($this->getTarget() && Controller::curr()?->getAction() == 'index' && !$inWorkflowGroupOrUserTables) {
            if ($this->getVersionedConnection($this->getTarget()->ID, $member->ID)) {
                return true;
            }
            return false;
        }
        return $inWorkflowGroupOrUserTables;
    }

    /**
     * Can documents in the current workflow state be edited?
     */
    public function canEditTarget()
    {
        if ($this->CurrentActionID && ($target = $this->getTarget())) {
            return $this->CurrentAction()->canEditTarget($target);
        }
    }

    /**
     * Does this action restrict viewing of the document?
     *
     * @return boolean
     */
    public function canViewTarget()
    {
        $action = $this->CurrentAction();
        if ($action) {
            return $action->canViewTarget($this->getTarget());
        }
        return true;
    }

    /**
     * Does this action restrict the publishing of a document?
     *
     * @return boolean
     */
    public function canPublishTarget()
    {
        if ($this->CurrentActionID && ($target = $this->getTarget())) {
            return $this->CurrentAction()->canPublishTarget($target);
        }
    }

    /**
     * Get the current set of transitions that are valid for the current workflow state,
     * and are available to the current user.
     *
     * @return array
     */
    public function validTransitions()
    {
        $action    = $this->CurrentAction();
        $transitions = $action->getValidTransitions();

        // Filter by execute permission
        return $transitions->filterByCallback(function ($transition) {
            return $transition->canExecute($this);
        });
    }

    /* UI RELATED METHODS */

    /**
     * Gets fields for managing this workflow instance in its current step
     *
     * @return FieldList
     */
    public function getWorkflowFields()
    {
        $action    = $this->CurrentAction();
        $options   = $this->validTransitions();
        $wfOptions = $options->map('ID', 'Title', ' ');
        $fields    = new FieldList();

        $fields->push(new HeaderField('WorkflowHeader', $action->Title));

        $fields->push(HiddenField::create('TransitionID', ''));
        // Let the Active Action update the fields that the user can interact with so that data can be
        // stored for the workflow.
        $action->invokeWithExtensions('updateWorkflowFields', $fields);
        return $fields;
    }

    /**
     * Gets Front-End form fields from current Action
     *
     * @return FieldList
     */
    public function getFrontEndWorkflowFields()
    {
        $action = $this->CurrentAction();

        $fields = new FieldList();
        $action->updateFrontEndWorkflowFields($fields);

        return $fields;
    }

    /**
     * Gets Transitions for display as Front-End Form Actions
     *
     * @return FieldList
     */
    public function getFrontEndWorkflowActions()
    {
        $action    = $this->CurrentAction();
        $options   = $action->getValidTransitions();
        $actions   = new FieldList();

        $hide_disabled_actions_on_frontend = $this->config()->hide_disabled_actions_on_frontend;

        foreach ($options as $option) {
            $btn = new FormAction("transition_{$option->ID}", $option->Title);

            // add cancel class to passive actions, this prevents js validation (using jquery.validate)
            if ($option->Type == 'Passive') {
                $btn->addExtraClass('cancel');
            }

            // disable the button if canExecute() returns false
            if (!$option->canExecute($this)) {
                if ($hide_disabled_actions_on_frontend) {
                    continue;
                }

                $btn = $btn->performReadonlyTransformation();
                $btn->addExtraClass('hide');
            }

            $actions->push($btn);
        }

        $action->updateFrontEndWorkflowActions($actions);

        return $actions;
    }

    /**
     * Gets Front-End DataObject
     *
     * @return DataObject
     */
    public function getFrontEndDataObject()
    {
        $action = $this->CurrentAction();
        $obj = $action->getFrontEndDataObject();

        return $obj;
    }

    /**
     * Gets Front-End DataObject
     *
     * @return DataObject
     */
    public function getFrontEndRequiredFields()
    {
        $action = $this->CurrentAction();
        $validator = $action->getRequiredFields();

        return $validator;
    }

    public function setFrontendFormRequirements()
    {
        $action = $this->CurrentAction();
        $action->setFrontendFormRequirements();
    }

    public function doFrontEndAction(array $data, Form $form, HTTPRequest $request)
    {
        $action = $this->CurrentAction();
        $action->doFrontEndAction($data, $form, $request);
    }

    /**
     * We need a way to "associate" an author with this WorkflowInstance and its Target() to see if she is "allowed"
     * to view WorkflowInstances within GridFields
     * @see {@link $this->userHasAccess()}
     *
     * @param number $recordID
     * @param number $userID
     * @param number $wasPublished
     * @return boolean
     */
    public function getVersionedConnection($recordID, $userID, $wasPublished = 0)
    {
        // Turn this into an array and run through implode()
        $filter = "RecordID = {$recordID} AND AuthorID = {$userID} AND WasPublished = {$wasPublished}";
        $query = new SQLSelect();
        $query->setFrom('"SiteTree_Versions"')->setSelect('COUNT("ID")')->setWhere($filter);
        $query->firstRow();
        $hasAuthored = $query->execute();
        if ($hasAuthored) {
            return true;
        }
        return false;
    }

    /**
     * Simple method to retrieve the current action, on the current WorkflowInstance
     */
    public function getCurrentAction()
    {
        $join = '"WorkflowAction"."ID" = "WorkflowActionInstance"."BaseActionID"';
        $action = WorkflowAction::get()
            ->leftJoin('WorkflowActionInstance', $join)
            ->where('"WorkflowActionInstance"."ID" = ' . $this->CurrentActionID)
            ->first();
        if (!$action) {
            return 'N/A';
        }
        return $action->getField('Title');
    }

    /**
     * Tells us if $member has had permissions over some part of the current WorkflowInstance.
     *
     * @param $member
     * @return WorkflowAction|boolean
     */
    public function getMostRecentActionForUser($member = null)
    {
        if (!$member) {
            if (!Security::getCurrentUser()) {
                return false;
            }
            $member = Security::getCurrentUser();
        }

        // WorkflowActionInstances in reverse creation-order so we get the most recent one's first
        $history = $this->Actions()->filter(array(
            'Finished' => 1,
            'BaseAction.ClassName' => AssignUsersToWorkflowAction::class
        ))->Sort('Created', 'DESC');

        $i = 0;
        foreach ($history as $inst) {
            /*
             * This iteration represents the 1st instance in the list - the most recent AssignUsersToWorkflowAction
             * in $history.
             * If there's no match for $member here or on the _previous_ AssignUsersToWorkflowAction, then bail out:
             */
            $assignedMembers = $inst->BaseAction()->getAssignedMembers();
            if ($i <= 1 && $assignedMembers->count() > 0 && $assignedMembers->find('ID', $member->ID)) {
                return $inst;
            }
            ++$i;
        }
        return false;
    }
}
