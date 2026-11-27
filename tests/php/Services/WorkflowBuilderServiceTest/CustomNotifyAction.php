<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services\WorkflowBuilderServiceTest;

use SilverStripe\Dev\TestOnly;
use Symbiote\AdvancedWorkflow\Actions\NotifyUsersWorkflowAction;

/**
 * A project-specific action with data of its own.
 *
 * @property string $Channel
 */
class CustomNotifyAction extends NotifyUsersWorkflowAction implements TestOnly
{
    private static string $table_name = 'AWBuilderCustomNotifyAction';

    private static array $db = [
        'Channel' => 'Varchar',
    ];
}
