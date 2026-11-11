<?php

namespace Symbiote\AdvancedWorkflow\DataObjects;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\LabelField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeMultiselectField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\AdvancedWorkflow\FormFields\WorkflowField;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

/**
 * An overall definition of a workflow
 *
 * The workflow definition has a series of steps to it. Each step has a series of possible transitions
 * that it can take - the first one that meets certain criteria is followed, which could lead to
 * another step.
 *
 * A step is either manual or automatic; an example 'manual' step would be requiring a person to review
 * a document. An automatic step might be to email a group of people, or to publish documents.
 * Basically, a manual step requires the interaction of someone to pick which action to take, an automatic
 * step will automatically determine what to do once it has finished.
 *
 * @author  marcus@symbiote.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 *
 * @method HasManyList<WorkflowAction> Actions()
 * @method HasManyList<WorkflowInstance> Instances()
 * @method ManyManyList<Member> Users()
 * @method ManyManyList<Group> Groups()
 */
class WorkflowDefinition extends DataObject
{
    private static $db = [
        'Title'                 => 'Varchar(128)',
        'Description'       => 'Text',
        'Template'          => 'Varchar',
        'TemplateVersion'   => 'Varchar',
        'RemindDays'        => 'Int',
        'Sort'              => 'Int',
        'InitialActionButtonText' => 'Varchar',
    ];

    private static $default_sort = 'Sort';

    private static $has_many = [
        'Actions'   => WorkflowAction::class,
        'Instances' => WorkflowInstance::class
    ];

    /**
     * By default, a workflow definition is bound to a particular set of users or groups.
     *
     * This is covered across to the workflow instance - it is up to subsequent
     * workflow actions to change this if needbe.
     *
     * @var array
     */
    private static $many_many = [
        'Users' => Member::class,
        'Groups' => Group::class,
    ];

    private static $icon = 'symbiote/silverstripe-advancedworkflow:images/definition.png';

    public static $default_workflow_title_base = 'My Workflow';

    public static $workflow_defs = [];

    private static $dependencies = [
        'workflowService' => '%$' . WorkflowService::class,
    ];

    private static $table_name = 'WorkflowDefinition';

    /**
     * @var WorkflowService
     */
    public $workflowService;

    /**
     * Gets the action that first triggers off the workflow
     *
     * @return WorkflowAction
     */
    public function getInitialAction()
    {
        if ($actions = $this->Actions()) {
            return $actions->First();
        }
    }

    /**
     * Ensure a sort value is set and we get a useable initial workflow title.
     */
    protected function onBeforeWrite()
    {
        if (!$this->Sort) {
            $this->Sort = DB::query('SELECT MAX("Sort") + 1 FROM "WorkflowDefinition"')->value();
        }
        if (!$this->ID && !$this->Title) {
            $this->Title = $this->getDefaultWorkflowTitle();
        }

        parent::onBeforeWrite();
    }

    /**
     * After we've been written, check whether we've got a template and to then
     * create the relevant actions etc.
     */
    protected function onAfterWrite()
    {
        parent::onAfterWrite();

        // Request via ImportForm where TemplateVersion is already set, so unset it
        $posted = Controller::curr()?->getRequest()->postVars() ?? [];
        if (isset($posted['_CsvFile']) && $this->TemplateVersion) {
            $this->TemplateVersion = null;
        }
        if ($this->numChildren() == 0 && $this->Template && !$this->TemplateVersion) {
            $this->getWorkflowService()->defineFromTemplate($this, $this->Template);
        }
    }

    /**
     * Ensure all WorkflowDefinition relations are removed on delete. If we don't do this,
     * we see issues with targets previously under the control of a now-deleted workflow,
     * becoming stuck, even if a new workflow is subsequently assigned to it.
     *
     * @return null
     */
    protected function onBeforeDelete()
    {
        parent::onBeforeDelete();

        // Delete related import
        $this->deleteRelatedImport();

        // Reset/unlink related HasMany|ManyMany relations and their orphaned objects
        $this->removeRelatedHasLists();
    }

    /**
     * Removes User+Group relations from this object as well as WorkflowAction relations.
     * When a WorkflowAction is deleted, its own relations are also removed:
     * - WorkflowInstance
     * - WorkflowTransition
     * @see WorkflowAction::onAfterDelete()
     *
     * @return void
     */
    private function removeRelatedHasLists()
    {
        $this->Users()->removeAll();
        $this->Groups()->removeAll();
        $this->Actions()->each(function ($action) {
            if ($orphan = WorkflowAction::get()->setUseCache(true)->byID($action->ID)) {
                $orphan->delete();
            }
        });
    }

    /**
     *
     * Deletes related ImportedWorkflowTemplate objects.
     *
     * @return void
     */
    private function deleteRelatedImport()
    {
        if ($import = DataObject::get(ImportedWorkflowTemplate::class)->filter('DefinitionID', $this->ID)->first()) {
            $import->delete();
        }
    }

    /**
     * @return int
     */
    public function numChildren()
    {
        return $this->Actions()->count();
    }

    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Title'] = _t('WorkflowDefinition.TITLE', 'Title');
        $labels['Description'] = _t('WorkflowDefinition.DESCRIPTION', 'Description');
        $labels['Template'] = _t('WorkflowDefinition.TEMPLATE_NAME', 'Source Template');
        $labels['TemplateVersion'] = _t('WorkflowDefinition.TEMPLATE_VERSION', 'Template Version');

        return $labels;
    }

    public function getCMSFields()
    {

        $cmsUsers = Member::mapInCMSGroups();

        $fields = new FieldList(new TabSet('Root'));

        $fields->addFieldToTab('Root.Main', new TextField('Title', $this->fieldLabel('Title')));
        $fields->addFieldToTab('Root.Main', new TextareaField('Description', $this->fieldLabel('Description')));
        $fields->addFieldToTab('Root.Main', TextField::create(
            'InitialActionButtonText',
            _t('WorkflowDefinition.INITIAL_ACTION_BUTTON_TEXT', 'Initial Action Button Text')
        ));
        if ($this->ID) {
            $fields->addFieldToTab(
                'Root.Main',
                new CheckboxSetField('Users', _t('WorkflowDefinition.USERS', 'Users'), $cmsUsers)
            );
            $fields->addFieldToTab(
                'Root.Main',
                new TreeMultiselectField('Groups', _t('WorkflowDefinition.GROUPS', 'Groups'), Group::class)
            );
        }

        if (class_exists(AbstractQueuedJob::class)) {
            $fields->addFieldToTab(
                'Root.Main',
                NumericField::create(
                    'RemindDays',
                    _t('WorkflowDefinition.REMINDEREMAIL', 'Reminder Email')
                )->setDescription(_t(
                    __CLASS__ . '.ReminderEmailDescription',
                    'Send reminder email after the specified number of days without action.'
                ))
            );
        }

        if ($this->ID) {
            if ($this->Template) {
                $template = $this->getWorkflowService()->getNamedTemplate($this->Template);
                $fields->addFieldToTab(
                    'Root.Main',
                    new ReadonlyField('Template', $this->fieldLabel('Template'), $this->Template)
                );
                $fields->addFieldToTab(
                    'Root.Main',
                    new ReadonlyField(
                        'TemplateDesc',
                        _t('WorkflowDefinition.TEMPLATE_INFO', 'Template Info'),
                        $template ? $template->getDescription() : ''
                    )
                );
                $fields->addFieldToTab(
                    'Root.Main',
                    $tv = new ReadonlyField('TemplateVersion', $this->fieldLabel('TemplateVersion'))
                );
                $tv->setRightTitle(sprintf(_t(
                    'WorkflowDefinition.LATEST_VERSION',
                    'Latest version is %s'
                ) ?? '', $template ? $template->getVersion() : ''));
            }

            $fields->addFieldToTab('Root.Main', new WorkflowField(
                'Workflow',
                _t('WorkflowDefinition.WORKFLOW', 'Workflow'),
                $this
            ));
        } else {
            // add in the 'template' info
            $templates = $this->getWorkflowService()->getTemplates();

            if (is_array($templates)) {
                $items = ['' => ''];
                foreach ($templates as $template) {
                    $items[$template->getName()] = $template->getName();
                }
                $templates = array_combine(array_keys($templates ?? []), array_keys($templates ?? []));

                $fields->addFieldToTab(
                    'Root.Main',
                    $dd = DropdownField::create(
                        'Template',
                        _t(
                            'WorkflowDefinition.CHOOSE_TEMPLATE',
                            'Choose template (optional)'
                        ),
                        $items
                    )
                );
                $dd->setHasEmptyDefault(true);
                $dd->setRightTitle(_t(
                    'WorkflowDefinition.CHOOSE_TEMPLATE_RIGHT',
                    'If set, this workflow definition will be automatically updated if the template is changed'
                ));
            }

            /*
             * Uncomment to allow pre-uploaded exports to appear in a new DropdownField.
             *
             * $import = singleton('WorkflowDefinitionImporter')->getImportedWorkflows();
             * if (is_array($import)) {
             *     $_imports = array('' => '');
             *     foreach ($imports as $import) {
             *         $_imports[$import->getName()] = $import->getName();
             *     }
             *     $imports = array_combine(array_keys($_imports), array_keys($_imports));
             *     $fields->addFieldToTab('Root.Main', new DropdownField('Import', _t(
             *         'WorkflowDefinition.CHOOSE_IMPORT',
             *         'Choose import (optional)'
             *     ), $imports));
             * }
             */

            $message = _t(
                'WorkflowDefinition.ADDAFTERSAVING',
                'You can add workflow steps after you save for the first time.'
            );
            $fields->addFieldToTab('Root.Main', new LiteralField(
                'AddAfterSaving',
                "<p class='message notice'>$message</p>"
            ));
        }

        if ($this->ID && Permission::check('VIEW_ACTIVE_WORKFLOWS')) {
            $active = $this->Instances()->filter([
                'WorkflowStatus' => ['Active', 'Paused']
            ]);

            $active = GridField::create(
                'Active',
                _t('WorkflowDefinition.WORKFLOWACTIVEIINSTANCES', 'Active Workflow Instances'),
                $active,
                GridFieldConfig_RecordEditor::create()
            );

            $active->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
            $active->getConfig()->removeComponentsByType(GridFieldDeleteAction::class);

            if (!Permission::check('REASSIGN_ACTIVE_WORKFLOWS')) {
                $active->getConfig()->removeComponentsByType(GridFieldEditButton::class);
                $active->getConfig()->addComponent(GridFieldViewButton::create());
                $active->getConfig()->addComponent(GridFieldDetailForm::create());
            }

            $completed = $this->Instances()->filter([
                'WorkflowStatus' => ['Complete', 'Cancelled']
            ]);

            $config = GridFieldConfig_Base::create();
            $config->addComponent(GridFieldEditButton::create());
            $config->addComponent(GridFieldDetailForm::create());

            $completed = GridField::create(
                'Completed',
                _t('WorkflowDefinition.WORKFLOWCOMPLETEDIINSTANCES', 'Completed Workflow Instances'),
                $completed,
                $config
            );

            $fields->findOrMakeTab(
                'Root.Active',
                _t('WorkflowEmbargoExpiryExtension.ActiveWorkflowStateTitle', 'Active')
            );
            $fields->addFieldToTab('Root.Active', $active);

            $fields->findOrMakeTab(
                'Root.Completed',
                _t('WorkflowEmbargoExpiryExtension.CompletedWorkflowStateTitle', 'Completed')
            );
            $fields->addFieldToTab('Root.Completed', $completed);
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function updateAdminActions($actions)
    {
        if ($this->Template) {
            $template = $this->getWorkflowService()->getNamedTemplate($this->Template);
            if ($template && $this->TemplateVersion != $template->getVersion()) {
                $label = sprintf(_t(
                    'WorkflowDefinition.UPDATE_FROM_TEMLPATE',
                    'Update to latest template version (%s)'
                ) ?? '', $template->getVersion());
                $actions->push($action = FormAction::create('updatetemplateversion', $label));
            }
        }
    }

    public function updateFromTemplate()
    {
        if ($this->Template) {
            $template = $this->getWorkflowService()->getNamedTemplate($this->Template);
            $template->updateDefinition($this);
        }
    }

    /**
     * If a workflow-title doesn't already exist, we automatically create a suitable default title
     * when users attempt to create title-less workflow definitions or upload/create Workflows that would
     * otherwise have the same name.
     *
     * @return string
     * @todo    Filter query on current-user's workflows. Avoids confusion when other users may already have
     *          'My Workflow 1' and user sees 'My Workflow 2'
     */
    public function getDefaultWorkflowTitle()
    {
        // Where is the title coming from that we wish to test?
        $incomingTitle = $this->incomingTitle();
        $defs = WorkflowDefinition::get()->map()->toArray();
        $tmp = [];

        foreach ($defs as $def) {
            $parts = preg_split("#\s#", preg_quote($def ?? '', '#'), -1, PREG_SPLIT_NO_EMPTY);
            $lastPart = array_pop($parts);
            $match = implode(' ', $parts);
            // @todo do all this in one preg_match_all() call
            if (preg_match("#$match#", $incomingTitle ?? '')) {
                // @todo use a simple incrementer??
                if ($incomingTitle.' '.$lastPart == $def) {
                    array_push($tmp, $lastPart);
                }
            }
        }

        $incr = 1;
        if (count($tmp ?? [])) {
            sort($tmp, SORT_NUMERIC);
            $incr = (int)end($tmp)+1;
        }
        return $incomingTitle.' '.$incr;
    }

    /**
     * Return the workflow definition title according to the source
     *
     * @return string
     */
    public function incomingTitle()
    {
        $req = Controller::curr()?->getRequest();
        if (isset($req['_CsvFile']['name']) && !empty($req['_CsvFile']['name'])) {
            $import = ImportedWorkflowTemplate::get()->filter('Filename', $req['_CsvFile']['name'])->first();
            $incomingTitle = $import->Name;
        } elseif (isset($req['Template']) && !empty($req['Template'])) {
            $incomingTitle = $req['Template'];
        } elseif (isset($req['Title']) && !empty($req['Title'])) {
            $incomingTitle = $req['Title'];
        } else {
            $incomingTitle = WorkflowDefinition::$default_workflow_title_base;
        }
        return $incomingTitle;
    }

    /**
     * Determines if target can be published directly when no workflow has started yet
     * Opens extension hook to allow an extension to determine if this is allowed as well
     *
     * By default returns false
     *
     * @param $member
     * @param $target
     * @return Boolean
     */
    public function canWorkflowPublish($member, $target)
    {
        $publish = $this->extendedCan('canWorkflowPublish', $member, $target);
        if (is_null($publish)) {
            $publish = Permission::checkMember($member, 'ADMIN');
        }
        return $publish;
    }

    /**
     *
     * @param Member $member
     * @param array $context
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        if (is_null($member)) {
            if (!Security::getCurrentUser()) {
                return false;
            }
            $member = Security::getCurrentUser();
        }
        return Permission::checkMember($member, 'CREATE_WORKFLOW');
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        return $this->userHasAccess($member);
    }

    /**
     *
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return $this->canCreate($member);
    }

    /**
     *
     * @param Member $member
     * @return boolean
     * @see {@link $this->onBeforeDelete()}
     */
    public function canDelete($member = null)
    {
        if (!$member) {
            if (!Security::getCurrentUser()) {
                return false;
            }
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, 'ADMIN')) {
            return true;
        }

        /*
         * DELETE_WORKFLOW should trump all other canDelete() return values on
         * related objects.
         * @see {@link $this->onBeforeDelete()}
         */
        return Permission::checkMember($member, 'DELETE_WORKFLOW');
    }

    /**
     * Checks whether the passed user is able to view this ModelAdmin
     *
     * @param Member $member
     * @return bool
     */
    protected function userHasAccess($member)
    {
        if (!$member) {
            if (!Security::getCurrentUser()) {
                return false;
            }
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, "VIEW_ACTIVE_WORKFLOWS")) {
            return true;
        }
    }

    /**
     * @param WorkflowService $workflowService
     * @return $this
     */
    public function setWorkflowService(WorkflowService $workflowService)
    {
        $this->workflowService = $workflowService;
        return $this;
    }

    /**
     * @return WorkflowService
     */
    public function getWorkflowService()
    {
        return $this->workflowService;
    }
}
