<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Controllers;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\SapphireTest;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Controllers\WorkflowBuilderController;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;

class WorkflowBuilderControllerTest extends SapphireTest
{
    protected $usesDatabase = true;

    private const PAGES_SECTION_ACCESS = 'CMS_ACCESS_CMSMain';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
    }

    /**
     * A record with no workflow is a normal state, not a failure — the endpoint
     * must answer 200 so the panel can render its "no active workflow" state.
     */
    public function testWorkflowDataReturnsEmptyPayloadWhenNoWorkflow(): void
    {
        $page = $this->createPage();

        $response = $this->callWorkflowData((string)$page->ID, SiteTree::class);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($body['workflow']);
        $this->assertSame([], $body['availableWorkflows']);
    }

    /**
     * Authors usually hold access to the Pages section only, not to every CMS
     * section; they must still see the panel and its start button.
     */
    public function testWorkflowDataAllowsUsersWithAccessToASingleCmsSection(): void
    {
        $definition = $this->createDefinition('Apply for approval');
        $page = $this->createPage(['WorkflowDefinitionID' => $definition->ID]);
        $this->logInWithPermission(WorkflowBuilderControllerTest::PAGES_SECTION_ACCESS);

        $response = $this->callWorkflowData((string)$page->ID, SiteTree::class);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [['id' => (int)$definition->ID, 'title' => 'Apply for approval']],
            $body['availableWorkflows']
        );
    }

    public function testWorkflowDataPrefersTheDefinitionsInitialButtonText(): void
    {
        $definition = $this->createDefinition('Apply for approval', 'Send for review');
        $page = $this->createPage(['WorkflowDefinitionID' => $definition->ID]);

        $body = json_decode((string)$this->callWorkflowData((string)$page->ID, SiteTree::class)->getBody(), true);

        $this->assertSame('Send for review', $body['availableWorkflows'][0]['title']);
    }

    /**
     * Starting a workflow saves the record, so it is only offered to users who
     * may edit it.
     */
    public function testWorkflowDataOffersNoWorkflowsWhenTheUserCannotEditTheRecord(): void
    {
        $definition = $this->createDefinition('Apply for approval');
        $page = $this->createPage([
            'WorkflowDefinitionID' => $definition->ID,
            'CanEditType' => 'OnlyTheseUsers',
        ]);
        $this->logInWithPermission(WorkflowBuilderControllerTest::PAGES_SECTION_ACCESS);

        $body = json_decode((string)$this->callWorkflowData((string)$page->ID, SiteTree::class)->getBody(), true);

        $this->assertSame([], $body['availableWorkflows']);
    }

    public function testWorkflowDataForbidsUsersWithoutCmsAccess(): void
    {
        $page = $this->createPage();
        $this->logInWithPermission('NOT_A_CMS_PERMISSION');

        $this->assertSame(403, $this->callWorkflowData((string)$page->ID, SiteTree::class)->getStatusCode());
    }

    public function testWorkflowDataForbidsRecordsTheUserCannotView(): void
    {
        $page = $this->createPage(['CanViewType' => 'OnlyTheseUsers']);
        $this->logInWithPermission(WorkflowBuilderControllerTest::PAGES_SECTION_ACCESS);

        $this->assertSame(403, $this->callWorkflowData((string)$page->ID, SiteTree::class)->getStatusCode());
    }

    public function testWorkflowDataReportsMissingRecord(): void
    {
        $this->assertSame(404, $this->callWorkflowData('99999', SiteTree::class)->getStatusCode());
    }

    /**
     * Regression: a slash-escaped class name reached the ORM and raised a
     * TypeError, which the panel surfaced as "Unable to load workflow status."
     */
    public function testWorkflowDataRejectsSlashEscapedClassName(): void
    {
        $response = $this->callWorkflowData('1', 'SilverStripe\\\\CMS\\\\Model\\\\SiteTree');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Unknown ClassName', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testWorkflowDataRejectsNonDataObjectClassName(): void
    {
        $response = $this->callWorkflowData('1', WorkflowBuilderControllerTest::class);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testWorkflowDataRejectsMissingParameters(): void
    {
        $this->assertSame(400, $this->callWorkflowData('0', SiteTree::class)->getStatusCode());
        $this->assertSame(400, $this->callWorkflowData('1', '')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createPage(array $fields = []): SiteTree
    {
        $page = SiteTree::create(array_merge(['Title' => 'Workflow target'], $fields));
        $page->write();

        return $page;
    }

    private function createDefinition(string $initialActionTitle, string $buttonText = ''): WorkflowDefinition
    {
        $definition = WorkflowDefinition::create([
            'Title' => 'Two-Step',
            'InitialActionButtonText' => $buttonText,
        ]);
        $definition->write();

        AssignUsersToWorkflowAction::create([
            'Title' => $initialActionTitle,
            'WorkflowDefID' => $definition->ID,
            'Sort' => 1,
        ])->write();

        return $definition;
    }

    private function callWorkflowData(string $id, string $className): HTTPResponse
    {
        $request = new HTTPRequest('GET', 'workflow-admin/workflowData', [
            'ID' => $id,
            'ClassName' => $className,
        ]);

        return $this->controller($request)->workflowData($request);
    }

    private function controller(HTTPRequest $request): WorkflowBuilderController
    {
        $controller = WorkflowBuilderController::create();
        $controller->setRequest($request);

        return $controller;
    }
}
