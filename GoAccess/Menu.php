<?php

// SPDX-License-Identifier: GPL-3.0-or-later
namespace App\Plugins\GoAccess;

use App\Models\User;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(User $user): bool
    {
        return $user->exists && $user->can('global-read');
    }
}
