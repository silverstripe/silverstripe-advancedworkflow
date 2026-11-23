<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Extensions;

use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;

/**
 * WorkflowService.enable_workflow_panel switches pages between the classic
 * action bar workflow buttons and the workflow status panel.
 *
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint
 */
class WorkflowPanelFlagTest extends SapphireTest
{
    protected $usesDatabase = true;

    private CMSPageEditController $controller;

    private WorkflowDefinition $definition;

    private SiteTree $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = CMSPageEditController::create();
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();

        $this->logInWithPermission('ADMIN');

        $this->definition = WorkflowDefinition::create(['Title' => 'Review']);
        $this->definition->write();
        SimpleApprovalWorkflowAction::create([
            'Title' => 'Approve content',
            'WorkflowDefID' => $this->definition->ID,
        ])->write();

        $this->page = SiteTree::create([
            'Title' => 'Flagged page',
            'WorkflowDefinitionID' => $this->definition->ID,
        ]);
        $this->page->write();
    }

    protected function tearDown(): void
    {
        $this->controller->popCurrent();
        parent::tearDown();
    }

    public function testPanelIsDisabledByDefault(): void
    {
        $this->assertFalse(Injector::inst()->get(WorkflowService::class)->isWorkflowPanelEnabled());
    }

    public function testActionBarHasStartButtonWhenPanelDisabled(): void
    {
        $this->assertContains($this->startActionName(), $this->actionNames($this->page->getCMSActions()));
    }

    public function testActionBarOmitsStartButtonWhenPanelEnabled(): void
    {
        $this->enablePanel();

        $this->assertNotContains($this->startActionName(), $this->actionNames($this->page->getCMSActions()));
    }

    public function testPanelOnlyRendersForPages(): void
    {
        $this->enablePanel();
        $service = Injector::inst()->get(WorkflowService::class);

        $this->assertTrue($service->rendersWorkflowPanelFor($this->page));
        $this->assertFalse($service->rendersWorkflowPanelFor(Member::create()));
    }

    public function testPanelNeverRendersWhenDisabled(): void
    {
        $this->assertFalse(Injector::inst()->get(WorkflowService::class)->rendersWorkflowPanelFor($this->page));
    }

    public function testApprovedWorkflowDoesNotGrantPublishWhenPanelDisabled(): void
    {
        $this->completeWorkflowForPage();

        $this->assertNotTrue($this->canPublishAsAuthor());
    }

    public function testApprovedWorkflowGrantsPublishWhenPanelEnabled(): void
    {
        $this->enablePanel();
        $this->completeWorkflowForPage();

        $this->assertTrue($this->canPublishAsAuthor());
    }

    public function testEditAfterApprovalRevokesPublishWhenPanelEnabled(): void
    {
        $this->enablePanel();
        $instance = $this->completeWorkflowForPage();
        $this->page->LastEdited = date('Y-m-d H:i:s', strtotime($instance->LastEdited) + 60);

        $this->assertNotTrue($this->canPublishAsAuthor());
    }

    private function enablePanel(): void
    {
        Config::modify()->set(WorkflowService::class, 'enable_workflow_panel', true);
    }

    private function startActionName(): string
    {
        return 'action_startworkflow-' . $this->definition->ID;
    }

    /**
     * @return array<int, string>
     */
    private function actionNames(FieldList $actions): array
    {
        $names = [];
        foreach ($actions->flattenFields() as $field) {
            $names[] = $field->getName();
        }

        return $names;
    }

    private function completeWorkflowForPage(): WorkflowInstance
    {
        $instance = WorkflowInstance::create([
            'Title' => 'Completed review',
            'WorkflowStatus' => WorkflowInstance::STATUS_COMPLETE,
            'TargetClass' => SiteTree::class,
            'TargetID' => $this->page->ID,
            'DefinitionID' => $this->definition->ID,
        ]);
        $instance->write();
        $this->page->LastEdited = $instance->LastEdited;

        return $instance;
    }

    /**
     * Calls the extension directly: SiteTree::canPublish() short-circuits for
     * administrators, which would hide the workflow decision.
     */
    private function canPublishAsAuthor(): ?bool
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $extension = Injector::inst()->create(WorkflowApplicable::class);
        $extension->setOwner($this->page);

        return $extension->canPublish();
    }
}
