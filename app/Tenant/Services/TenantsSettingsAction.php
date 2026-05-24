<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;

class TenantsSettingsAction extends GlobalHelpers
{
    protected $actionsMethod = [
        'sacco-member-free-input-code',
        'sacco-member-code-str-pad',
        'sacco-members-Require-approval-before-members-becomes-active',
        'sacco-member-code-auto-generate',
        'sacco-member-code-prefix',
        'sacco-member-code-segment-length',
        'system-max-code',
        'system-default-code',
    ];
}
