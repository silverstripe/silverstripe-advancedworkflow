<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Controllers;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;
use Symbiote\AdvancedWorkflow\Actions\AssignUsersToWorkflowAction;
use Symbiote\AdvancedWorkflow\Actions\SimpleApprovalWorkflowAction;
use Symbiote\AdvancedWorkflow\Controllers\WorkflowBuilderController;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowActionInstance;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;

/**
 * Requests go through the full HTTP stack so AdminController's access check
 * and the security token check run as they do in the CMS.
 */
class WorkflowBuilderControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private const PAGES_SECTION_ACCESS = 'CMS_ACCESS_CMSMain';

    private const REPORTS_SECTION_ACCESS = 'CMS_ACCESS_ReportAdmin';

    private const REVIEWER_COMMENT = 'Please fix the second paragraph';

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

        $response = $this->getWorkflowData((string)$page->ID, SiteTree::class);
        $body = $this->decode($response);

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

        $response = $this->getWorkflowData((string)$page->ID, SiteTree::class);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [['id' => (int)$definition->ID, 'title' => 'Apply for approval']],
            $this->decode($response)['availableWorkflows']
        );
    }

    public function testWorkflowDataPrefersTheDefinitionsInitialButtonText(): void
    {
        $definition = $this->createDefinition('Apply for approval', 'Send for review');
        $page = $this->createPage(['WorkflowDefinitionID' => $definition->ID]);

        $body = $this->decode($this->getWorkflowData((string)$page->ID, SiteTree::class));

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

        $body = $this->decode($this->getWorkflowData((string)$page->ID, SiteTree::class));

        $this->assertSame([], $body['availableWorkflows']);
    }

    public function testWorkflowDataForbidsUsersWithoutCmsAccess(): void
    {
        $page = $this->createPage();
        $this->logInWithPermission('NOT_A_CMS_PERMISSION');

        $response = $this->getWorkflowData((string)$page->ID, SiteTree::class);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('availableWorkflows', (string)$response->getBody());
    }

    public function testWorkflowDataForbidsRecordsTheUserCannotView(): void
    {
        $page = $this->createPage(['CanViewType' => 'OnlyTheseUsers']);
        $this->logInWithPermission(WorkflowBuilderControllerTest::PAGES_SECTION_ACCESS);

        $this->assertSame(403, $this->getWorkflowData((string)$page->ID, SiteTree::class)->getStatusCode());
    }

    public function testWorkflowDataShowsHistoryToUsersWhoCanViewTheWorkflow(): void
    {
        $page = $this->createPageWithCommentedWorkflow();

        $body = $this->decode($this->getWorkflowData((string)$page->ID, SiteTree::class));

        $this->assertTrue($body['canViewDetails']);
        $this->assertSame(WorkflowBuilderControllerTest::REVIEWER_COMMENT, $body['latestComment']['comment']);
    }

    /**
     * Viewing a published page does not grant access to its workflow's
     * history, reviewer comments or assignees.
     */
    public function testWorkflowDataHidesHistoryFromUsersWhoCannotViewTheWorkflow(): void
    {
        $page = $this->createPageWithCommentedWorkflow();
        $this->logInWithPermission(WorkflowBuilderControllerTest::REPORTS_SECTION_ACCESS);

        $response = $this->getWorkflowData((string)$page->ID, SiteTree::class);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['canViewDetails']);
        $this->assertSame([], $body['history']);
        $this->assertNull($body['latestComment']);
        $this->assertNull($body['assignedTo']);
        $this->assertStringNotContainsString(
            WorkflowBuilderControllerTest::REVIEWER_COMMENT,
            (string)$response->getBody()
        );
    }

    public function testWorkflowDataReportsMissingRecord(): void
    {
        $this->assertSame(404, $this->getWorkflowData('99999', SiteTree::class)->getStatusCode());
    }

    /**
     * Regression: a slash-escaped class name reached the ORM and raised a
     * TypeError, which the panel surfaced as "Unable to load workflow status."
     */
    public function testWorkflowDataRejectsSlashEscapedClassName(): void
    {
        $response = $this->getWorkflowData('1', 'SilverStripe\\\\CMS\\\\Model\\\\SiteTree');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Unknown ClassName', $this->decode($response)['error']);
    }

    public function testWorkflowDataRejectsNonDataObjectClassName(): void
    {
        $this->assertSame(400, $this->getWorkflowData('1', WorkflowBuilderControllerTest::class)->getStatusCode());
    }

    public function testWorkflowDataRejectsMissingParameters(): void
    {
        $this->assertSame(400, $this->getWorkflowData('0', SiteTree::class)->getStatusCode());
        $this->assertSame(400, $this->getWorkflowData('1', '')->getStatusCode());
    }

    public function testSaveWorkflowRejectsRequestsWithoutSecurityToken(): void
    {
        SecurityToken::enable();

        $response = $this->postSave(['title' => 'Unsafe', 'steps' => []], []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, WorkflowDefinition::get()->filter('Title', 'Unsafe')->count());
    }

    public function testSaveWorkflowAcceptsRequestsWithSecurityToken(): void
    {
        SecurityToken::enable();
        $token = SecurityToken::inst();

        $response = $this->postSave(
            ['title' => 'Safe', 'steps' => []],
            ['X-' . ucwords(strtolower($token->getName())) => $token->getValue()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, WorkflowDefinition::get()->filter('Title', 'Safe')->count());
    }

    public function testSaveWorkflowRequiresPost(): void
    {
        $this->assertSame(405, $this->get($this->endpoint('saveWorkflow'))->getStatusCode());
    }

    public function testSaveWorkflowForbidsUsersWhoCannotCreateWorkflows(): void
    {
        $this->logInWithPermission(WorkflowBuilderControllerTest::PAGES_SECTION_ACCESS);

        $this->assertSame(403, $this->postSave(['title' => 'Nope', 'steps' => []], [])->getStatusCode());
    }

    /**
     * The client reads endpoint URLs from the admin client config. They are
     * absolute, so they work when the CMS is installed in a subdirectory.
     */
    public function testClientConfigEndpointsIncludeTheBaseUrl(): void
    {
        $endpoints = WorkflowBuilderController::singleton()->getClientConfig()['endpoints'];

        $this->assertSame(
            ['workflowMeta', 'searchUsers', 'workflowData', 'loadDefinition', 'saveWorkflow'],
            array_keys($endpoints)
        );
        $this->assertStringStartsWith(Director::absoluteBaseURL(), $endpoints['workflowData']);
        $this->assertStringEndsWith('workflow-ui/workflowData', $endpoints['workflowData']);
    }

    public function testEndpointsAreInTheCombinedCmsClientConfig(): void
    {
        // Other admin sections read the current request while building their config.
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);

        $config = json_decode(LeftAndMain::singleton()->getCombinedClientConfig(), true);
        $sections = $config['sections'];
        $section = array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['name'] === WorkflowBuilderController::class
        ));

        $this->assertCount(1, $section);
        $this->assertArrayHasKey('workflowData', $section[0]['endpoints']);
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

    /**
     * A published page whose active workflow has a reviewer comment.
     */
    private function createPageWithCommentedWorkflow(): SiteTree
    {
        $definition = WorkflowDefinition::create(['Title' => 'Review']);
        $definition->write();
        $review = SimpleApprovalWorkflowAction::create(['Title' => 'Review', 'WorkflowDefID' => $definition->ID]);
        $review->write();

        $page = $this->createPage(['WorkflowDefinitionID' => $definition->ID]);
        $page->publishSingle();

        $instance = WorkflowInstance::create([
            'Title' => 'Review',
            'WorkflowStatus' => 'Paused',
            'TargetClass' => SiteTree::class,
            'TargetID' => $page->ID,
            'DefinitionID' => $definition->ID,
        ]);
        $instance->write();

        $actionInstance = WorkflowActionInstance::create([
            'WorkflowID' => $instance->ID,
            'BaseActionID' => $review->ID,
            'Finished' => 1,
            'Comment' => WorkflowBuilderControllerTest::REVIEWER_COMMENT,
        ]);
        $actionInstance->write();

        return $page;
    }

    private function endpoint(string $action): string
    {
        return Controller::join_links('admin', 'workflow-ui', $action);
    }

    private function getWorkflowData(string $id, string $className): HTTPResponse
    {
        return $this->get($this->endpoint('workflowData') . '?' . http_build_query([
            'ID' => $id,
            'ClassName' => $className,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function postSave(array $payload, array $headers): HTTPResponse
    {
        return $this->mainSession->sendRequest(
            'POST',
            $this->endpoint('saveWorkflow'),
            [],
            $headers,
            null,
            (string)json_encode($payload)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HTTPResponse $response): array
    {
        return (array)json_decode((string)$response->getBody(), true);
    }
}
