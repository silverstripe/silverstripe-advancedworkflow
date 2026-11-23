<?php

declare(strict_types=1);

namespace Symbiote\AdvancedWorkflow\Tests\Services\WorkflowApprovalServiceTest;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\HasManyList;

/**
 * A page that owns blocks, standing in for elemental content.
 *
 * @method HasManyList<ApprovalTestBlock> Blocks()
 */
class ApprovalTestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'AWApprovalTestPage';

    private static array $has_many = [
        'Blocks' => ApprovalTestBlock::class,
    ];

    private static array $owns = [
        'Blocks',
    ];
}
