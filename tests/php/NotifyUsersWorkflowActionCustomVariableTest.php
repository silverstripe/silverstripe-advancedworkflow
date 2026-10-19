<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;

class NotifyUsersWorkflowActionCustomVariableTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        NotifyUsersWorkflowAction::class => [NotifyCustomVariableTestExtension::class],
    ];

    protected function tearDown(): void
    {
        NotifyCustomVariableTestExtension::$value = '';
        NotifyCustomVariableTestExtension::$prefixValue = '';
        parent::tearDown();
    }

    public function testCustomVariableIsSubstituted(): void
    {
        $this->sendWithCustomValue('Subject $DevVar', 'Body {$DevVar}', 'dev-provided-value');
        $email = static::findEmail('recipient@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('Body dev-provided-value', $email['Content']);
        $this->assertStringContainsString('Subject dev-provided-value', $email['Subject']);
    }

    public function testCustomVariableValueIsNotEvaluatedAsTemplate(): void
    {
        $victim = new Member();
        $victim->Email = 'victim@example.com';
        $victim->Password = 'awesome-bicycle-chicken-delivery';
        $victim->write();
        $payload = '$List(\'SilverStripe\\Security\\Member\')'
            . '.filter(\'Email\', \'victim@example.com\').first()'
            . '.changePassword(\'injected-horse-battery-staple-xyz\')';
        $this->sendWithCustomValue('Subject', 'Body $DevVar', $payload);
        $email = static::findEmail('recipient@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('changePassword(', $email['Content']);
        $victim = Member::get()->byID($victim->ID);
        $result = (new MemberAuthenticator())->checkPassword($victim, 'awesome-bicycle-chicken-delivery');
        $this->assertTrue($result->isValid());
    }

    public function testCustomVariablePlainStringIsEscaped(): void
    {
        $this->sendWithCustomValue('Subject', 'Body $DevVar', '<b>bold</b>');
        $email = static::findEmail('recipient@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('Body &lt;b&gt;bold&lt;/b&gt;', $email['Content']);
        $this->assertStringNotContainsString('Body <b>bold</b>', $email['Content']);
    }

    public function testLongerVariableNameIsNotCorruptedByShorterPrefix(): void
    {
        NotifyCustomVariableTestExtension::$prefixValue = 'SHORT';
        $this->sendWithCustomValue('Subject', 'Body $DevVar', 'LONG');
        $email = static::findEmail('recipient@example.com', 'from@example.com');
        $this->assertNotNull($email);
        $this->assertStringContainsString('Body LONG', $email['Content']);
        $this->assertStringNotContainsString('SHORTVar', $email['Content']);
    }

    private function sendWithCustomValue(string $subject, string $template, string $value): void
    {
        NotifyCustomVariableTestExtension::$value = $value;
        $this->logInWithPermission();
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
        $action->EmailSubject = $subject;
        $action->EmailFrom = 'from@example.com';
        $action->EmailTemplate = $template;
        $action->write();
        $this->clearEmails();
        $action->execute($instance);
    }
}
