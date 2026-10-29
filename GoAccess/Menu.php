<?php

// SPDX-License-Identifier: GPL-3.0-or-later
namespace App\Plugins\GoAccess;

use App\Models\User;
use App\Plugins\Hooks\MenuEntryHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Menu extends MenuEntryHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user instanceof User && $user->exists && $user->can('global-read');
    }
}
