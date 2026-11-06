<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Controllers;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\SapphireTest;
use Symbiote\AdvancedWorkflow\Controllers\WorkflowBuilderController;

class WorkflowBuilderControllerTest extends SapphireTest
{
    protected $usesDatabase = true;

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
        $page = SiteTree::create(['Title' => 'No workflow']);
        $page->write();

        $response = $this->callWorkflowData((string)$page->ID, SiteTree::class);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($body['workflow']);
        $this->assertSame([], $body['availableWorkflows']);
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
        $response = $this->callWorkflowData('1', self::class);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testWorkflowDataRejectsMissingParameters(): void
    {
        $this->assertSame(400, $this->callWorkflowData('0', SiteTree::class)->getStatusCode());
        $this->assertSame(400, $this->callWorkflowData('1', '')->getStatusCode());
    }

    public function testStartWorkflowRejectsUnknownClassName(): void
    {
        $response = $this->callStartWorkflow('1', 'SilverStripe\\\\CMS\\\\Model\\\\SiteTree');

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStartWorkflowReportsMissingRecord(): void
    {
        $response = $this->callStartWorkflow('99999', SiteTree::class);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function callWorkflowData(string $id, string $className): HTTPResponse
    {
        $request = new HTTPRequest('GET', 'workflow-admin/workflowData', [
            'ID' => $id,
            'ClassName' => $className,
        ]);

        return $this->controller($request)->workflowData($request);
    }

    private function callStartWorkflow(string $recordId, string $className): HTTPResponse
    {
        $body = json_encode(['RecordID' => $recordId, 'ClassName' => $className, 'DefinitionID' => 0]);
        $request = new HTTPRequest('POST', 'workflow-admin/startWorkflow', [], [], (string)$body);

        return $this->controller($request)->startWorkflow($request);
    }

    private function controller(HTTPRequest $request): WorkflowBuilderController
    {
        $controller = new WorkflowBuilderController();
        $controller->setRequest($request);

        return $controller;
    }
}
