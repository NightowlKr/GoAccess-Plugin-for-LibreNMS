<?php

// SPDX-License-Identifier: GPL-3.0-or-later
namespace App\Plugins\GoAccess;

use App\Models\User;
use App\Plugins\GoAccess\Support\Report;
use App\Plugins\Hooks\PageHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Page extends PageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user instanceof User && $user->exists && $user->can('global-read');
    }

    public function data(array $settings = []): array
    {
        // Defense in depth: do not read a file when invoked outside the manager.
        $user = auth()->user();
        if (! $user instanceof User || ! $this->authorize($user)) {
            return ['report' => null, 'reportError' => 'You are not authorized to view this report.'];
        }

        try {
            return ['report' => Report::document($settings['report_path'] ?? Report::DEFAULT_PATH), 'reportError' => null];
        } catch (\RuntimeException $e) {
            return ['report' => null, 'reportError' => $e->getMessage()];
        }
    }
}
