<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // 1) Try the normal Spatie path
        $roles = collect();
        if (method_exists($this->resource, 'getRoleNames')) {
            $roles = $this->resource->getRoleNames(); // e.g. ["staff"]
        }

        // 2) If still empty, force-read from pivot to bypass guard/cache issues
        if ($roles->isEmpty()) {
            $roles = Role::query()
                ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $this->id)
                ->where('model_has_roles.model_type', get_class($this->resource)) // "App\Models\User"
                ->pluck('roles.name');
        }

        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'role'      => $roles->first(),
            'roles'     => $roles->values(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
