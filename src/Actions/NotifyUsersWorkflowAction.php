<?php

namespace Symbiote\AdvancedWorkflow\Actions;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\CMSPreviewable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowAction;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * A workflow action that notifies users attached to the workflow path that they have a task awaiting them.
 *
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage actions
 */
class NotifyUsersWorkflowAction extends WorkflowAction
{
    /**
     * @config
     * @var bool Should templates be constrained to just known-safe variables.
     * @deprecated 7.2.4 Will be removed without equivalent functionality to replace it in a future major release.
     */
    private static $whitelist_template_variables = false;

    private static $db = array(
        'EmailSubject'  => 'Varchar(100)',
        'EmailFrom'     => 'Varchar(50)',
        'EmailTemplate' => 'Text'
    );

    private static $icon = 'symbiote/silverstripe-advancedworkflow:images/notify.png';

    private static $table_name = 'NotifyUsersWorkflowAction';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->addFieldsToTab('Root.Main', array(
            new HeaderField('NotificationEmail', $this->fieldLabel('NotificationEmail')),
            new LiteralField('NotificationNote', '<p>' . $this->fieldLabel('NotificationNote') . '</p>'),
            new TextField('EmailSubject', $this->fieldLabel('EmailSubject')),
            new TextField('EmailFrom', $this->fieldLabel('EmailFrom')),

            new TextareaField('EmailTemplate', $this->fieldLabel('EmailTemplate')),
            new ToggleCompositeField(
                'FormattingHelpContainer',
                $this->fieldLabel('FormattingHelp'),
                new LiteralField('FormattingHelp', $this->getFormattingHelp())
            )
        ));

        $this->extend('updateNotifyUsersCMSFields', $fields);

        return $fields;
    }

    public function fieldLabels($relations = true)
    {
        return array_merge(parent::fieldLabels($relations), array(
            'NotificationEmail' => _t('NotifyUsersWorkflowAction.NOTIFICATIONEMAIL', 'Notification Email'),
            'NotificationNote'  => _t(
                'NotifyUsersWorkflowAction.NOTIFICATIONNOTE',
                'All users attached to the workflow will be sent an email when this action is run.'
            ),
            'EmailSubject'      => _t('NotifyUsersWorkflowAction.EMAILSUBJECT', 'Email subject'),
            'EmailFrom'         => _t('NotifyUsersWorkflowAction.EMAILFROM', 'Email from'),
            'EmailTemplate'     => _t('NotifyUsersWorkflowAction.EMAILTEMPLATE', 'Email template'),
            'FormattingHelp'    => _t('NotifyUsersWorkflowAction.FORMATTINGHELP', 'Formatting Help')
        ));
    }

    public function execute(WorkflowInstance $workflow)
    {
        if (!$this->EmailTemplate) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'No email template available for notify users workflow action '
                . "'{$this->Title}' in workflow '{$this->WorkflowDef()?->Title}'."
            );
            return true;
        }

        $members = $workflow->getAssignedMembers();

        if (!$members || !count($members ?? [])) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'No members to send emails to for notify users workflow action '
                . "'{$this->Title}' in workflow '{$this->WorkflowDef()?->Title}'."
            );
            return true;
        }

        $member = Security::getCurrentUser();
        $initiator = $workflow->Initiator();

        $contextFields   = $this->getContextFields($workflow->getTarget());
        $memberFields    = $this->getMemberFields($member);
        $initiatorFields = $this->getMemberFields($initiator);

        $variables = [];

        foreach ($contextFields as $field => $val) {
            $variables["\$Context.$field"] = $val;
        }
        foreach ($memberFields as $field => $val) {
            $variables["\$Member.$field"] = $val;
        }
        foreach ($initiatorFields as $field => $val) {
            $variables["\$Initiator.$field"] = $val;
        }

        $pastActions = $workflow->Actions()->sort('Created DESC');
        $variables["\$CommentHistory"] = $this->customise([
            'PastActions' => $pastActions,
            'Now' => DBDatetime::now()
        ])->renderWith('Includes/CommentHistory');

        // Let developers add their own notification variables (substituted as literal text, not evaluated)
        $customVariables = [];
        $this->extend('updateNotificationVariables', $customVariables, $workflow);
        foreach ($customVariables as $name => $value) {
            $variables[$name] = $value;
        }

        // Subject and from address are plain-text headers, so their values are substituted unescaped.
        $from = $this->replaceVariables($this->EmailFrom ?? '', $variables, false);
        $subject = $this->replaceVariables($this->EmailSubject ?? '', $variables, false);

        $emailSent = false;
        foreach ($members as $member) {
            if ($member->Email) {
                // We bind in the assignee at this point, as it changes each loop iteration
                $replacements = $variables;
                foreach ($this->getMemberFields($member) as $field => $val) {
                    $replacements["\$Assignee.$field"] = $val;
                }
                $body = $this->replaceVariables($this->EmailTemplate ?? '', $replacements);

                $email = Email::create();
                try {
                    $email->setTo($member->Email);
                } catch (RfcComplianceException) {
                    // If the email address isn't valid we should skip it rather than break
                    // the rest of the processing
                    continue;
                }
                $email->setSubject($subject);
                if ($from) {
                    $email->setFrom($from);
                }
                $email->setBody($body);
                $email->send();
                $emailSent = true;
            }
        }

        if (!$emailSent) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'No email was sent for notify users workflow action '
                . "'{$this->Title}' in workflow '{$this->WorkflowDef()?->Title}'."
            );
        }

        return true;
    }

    /**
     * Substitute the known notification variables into author-controlled content as literal text,
     * never evaluating it as a template. With $escape each value is cast to its safe HTML form,
     * matching the escaping the template engine used to apply to the email body.
     *
     * @param array<string, mixed> $variables
     */
    private function replaceVariables(string $content, array $variables, bool $escape = true): string
    {
        // Match a whole $Name or {$Name} token (including dotted names) so a known variable can never
        // replace the prefix of a longer unknown one, and inserted values are never re-scanned.
        $token = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*';
        return preg_replace_callback(
            '/\{\$(' . $token . ')\}|\$(' . $token . ')/',
            function (array $matches) use ($variables, $escape): string {
                $name = '$' . (($matches[1] ?? '') !== '' ? $matches[1] : $matches[2]);
                if (!array_key_exists($name, $variables)) {
                    return $matches[0];
                }
                $value = $variables[$name];
                if (!$escape) {
                    return (string) $value;
                }
                return $value instanceof DBField ? (string) $value->forTemplate() : Convert::raw2xml((string) $value);
            },
            $content
        ) ?? '';
    }

    /**
     * @param  DataObject $target
     * @return array
     */
    public function getContextFields(DataObject $target)
    {
        $result = array();
        if (!$target) {
            return $result;
        }

        $fields = $target->getSchema()->fieldSpecs($target);
        unset($fields['ID']);

        foreach ($fields as $field => $fieldDesc) {
            $result[$field] = $target->$field;
        }

        if ($target instanceof CMSPreviewable) {
            $result['CMSLink'] = $target->getCMSEditLink();
        } elseif ($target->hasMethod('WorkflowLink')) {
            $result['CMSLink'] = $target->WorkflowLink();
        }
        $result['AbsoluteEditLink'] = isset($result['CMSLink']) ? $result['CMSLink'] : '';

        if ($target->hasMethod('AbsoluteLink')) {
            $result['AbsoluteLink'] = $target->AbsoluteLink();
        }

        if ($target->hasMethod('LinkToPendingItems')) {
            $result['LinkToPendingItems'] = $target->LinkToPendingItems();
        }

        return $result;
    }

    /**
     * Builds an array with the member information
     * @param Member $member An optional member to use. If null, will use the current logged in member
     * @return array
     */
    public function getMemberFields(?Member $member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        $result = array();

        if ($member) {
            foreach ($member->summaryFields() as $field => $title) {
                $result[$field] = $member->$field;
            }
        }

        if ($member && !array_key_exists('Name', $result ?? [])) {
            $result['Name'] = $member->getName();
        }

        return $result;
    }


    /**
     * Returns a basic set of instructions on how email templates are populated with variables.
     *
     * @return string
     */
    public function getFormattingHelp()
    {
        $note = _t(
            'NotifyUsersWorkflowAction.FORMATTINGNOTE',
            'Notification emails can contain HTML formatting. The following special variables are replaced with their
			respective values in the email subject, email from and template/body.'
        );
        $member = _t(
            'NotifyUsersWorkflowAction.MEMBERNOTE',
            'These fields will be populated from the member that initiates the notification action. For example,
			{$Member.FirstName}.'
        );
        $initiator = _t(
            'NotifyUsersWorkflowAction.INITIATORNOTE',
            'These fields will be populated from the member that initiates the workflow request. For example,
			{$Initiator.Email}.'
        );
        $context = _t(
            'NotifyUsersWorkflowAction.CONTEXTNOTE',
            'Any summary fields from the workflow target will be available. For example, {$Context.Title}.
			Additionally, the {$Context.AbsoluteEditLink} variable will contain a link to edit the workflow target in
            the CMS (if it is a Page), and {$Context.AbsoluteLink} the frontend link. The {$Context.LinkToPendingItems}
            variable will generate a link to the CMS workflow admin, useful for allowing users to enact workflow
            transitions, directly from emails.'
        );
        $fieldName = _t('NotifyUsersWorkflowAction.FIELDNAME', 'Field name');
        $commentHistory = _t('NotifyUsersWorkflowAction.COMMENTHISTORY', 'Comment history up to this notification.');

        $memberFields = implode(', ', array_keys($this->getMemberFields() ?? []));

        return "<p>$note</p>
			<p><strong>{\$Member.($memberFields)}</strong><br>$member</p>
			<p><strong>{\$Initiator.($memberFields)}</strong><br>$initiator</p>
			<p><strong>{\$Context.($fieldName)}</strong><br>$context</p>
			<p><strong>{\$CommentHistory}</strong><br>$commentHistory</p>";
    }
}
