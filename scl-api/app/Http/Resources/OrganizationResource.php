<?php

namespace App\Http\Resources;

use App\Models\CompanySettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CompanySettings $company */
        $company = $this->resource;

        return [
            'id'        => $company->id,
            'name'      => $company->name,
            'slug'      => Str::slug($company->name),
            'email'     => $company->email,
            'phone'     => $company->phone,
            'website'   => $company->website,
            'logo'      => $company->logo,
            'address'   => $company->address,
            'city'      => $company->city,
            'country'   => $company->country,
            'timezone'  => $company->timezone,
            'is_active' => $company->is_active,
        ];
    }
}
