<?php

namespace Symbiote\AdvancedWorkflow\Tests;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

class NotifyCustomVariableTestExtension extends Extension implements TestOnly
{
    public static string $value = '';

    public static string $prefixValue = '';

    public function updateNotificationVariables(array &$customVariables, $workflow): void
    {
        // $Dev is a prefix of $DevVar, and is declared first to prove a shorter name cannot corrupt a longer one.
        $customVariables['$Dev'] = static::$prefixValue;
        $customVariables['$DevVar'] = static::$value;
    }
}
