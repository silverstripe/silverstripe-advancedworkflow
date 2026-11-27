<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services\WorkflowApprovalServiceTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * @property string $Content
 * @method ApprovalTestPage Page()
 */
class ApprovalTestBlock extends DataObject implements TestOnly
{
    private static string $table_name = 'AWApprovalTestBlock';

    private static array $db = [
        'Content' => 'Text',
    ];

    private static array $has_one = [
        'Page' => ApprovalTestPage::class,
    ];

    private static array $extensions = [
        Versioned::class,
    ];
}
