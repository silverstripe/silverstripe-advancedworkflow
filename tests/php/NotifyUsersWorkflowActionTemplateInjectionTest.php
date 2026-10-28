<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;

class NotifyUsersWorkflowActionTemplateInjectionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testNotifyTemplateDoesNotExecuteTemplateMethodCalls(): void
    {
        $this->logInWithPermission();
        $victim = new Member();
        $victim->Email = 'victim@example.com';
        $victim->Password = 'awesome-bicycle-chicken-delivery';
        $victim->write();
        $recipient = new Member();
        $recipient->Email = 'recipient@example.com';
        $recipient->write();
        $page = new SiteTree();
        $page->Title = 'Notify target';
        $page->write();
        $instance = new WorkflowInstance();
        $instance->TargetClass = SiteTree::class;
        $instance->TargetID = $page->ID;
        $instance->write();
        $instance->Users()->add($recipient);
        $action = new NotifyUsersWorkflowAction();
        $action->EmailSubject = 'Subject';
        $action->EmailFrom = 'from@example.com';
        $action->EmailTemplate = '$List(\'SilverStripe\\Security\\Member\')'
            . '.filter(\'Email\', \'victim@example.com\').first()'
            . '.changePassword(\'injected-horse-battery-staple-xyz\')';
        $action->write();
        $this->clearEmails();
        $action->execute($instance);
        $email = static::findEmail('recipient@example.com', 'from@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('changePassword(', $email['Content']);
        $victim = Member::get()->byID($victim->ID);
        $result = (new MemberAuthenticator())->checkPassword($victim, 'awesome-bicycle-chicken-delivery');
        $this->assertTrue($result->isValid());
    }

    public function testBuiltInVariableValueIsEscaped(): void
    {
        $this->logInWithPermission();
        $recipient = new Member();
        $recipient->Email = 'recipient@example.com';
        $recipient->write();
        $page = new SiteTree();
        $page->Title = '<b>Bold</b>';
        $page->write();
        $instance = new WorkflowInstance();
        $instance->TargetClass = SiteTree::class;
        $instance->TargetID = $page->ID;
        $instance->write();
        $instance->Users()->add($recipient);
        $action = new NotifyUsersWorkflowAction();
        $action->EmailSubject = 'Subject';
        $action->EmailFrom = 'from@example.com';
        $action->EmailTemplate = 'Body $Context.Title';
        $action->write();
        $this->clearEmails();
        $action->execute($instance);
        $email = static::findEmail('recipient@example.com', 'from@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('Body &lt;b&gt;Bold&lt;/b&gt;', $email['Content']);
        $this->assertStringNotContainsString('Body <b>Bold</b>', $email['Content']);
    }
}
