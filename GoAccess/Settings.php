<?php

// SPDX-License-Identifier: GPL-3.0-or-later
namespace App\Plugins\GoAccess;

use App\Models\User;
use App\Plugins\GoAccess\Support\Report;
use App\Plugins\Hooks\SettingsHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Settings extends SettingsHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user instanceof User && $user->exists && $user->can('plugin.admin');
    }

    public function data(array $settings = []): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $this->authorize($user)) {
            return ['reportPath' => '', 'pathError' => 'Administrator access is required.'];
        }

        $path = $settings['report_path'] ?? Report::DEFAULT_PATH;
        $error = null;
        try {
            Report::resolve($path);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return ['reportPath' => is_string($path) ? $path : '', 'pathError' => $error];
    }
}
