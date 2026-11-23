<?php

namespace App\Services;

use App\Models\CompanySettings;

class ReceiptSettingsService
{
    /** @var list<string> */
    public const FIELDS = [
        'company_name',
        'tagline',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'website',
        'gstin',
        'pan',
        'footer_note',
        'logo',
    ];

    /** @return array<string, mixed> */
    public function forBranch(int $branchId): array
    {
        $settings = CompanySettings::current()->settings ?? [];
        $entry = $this->branchEntry($settings['receipt_branches'] ?? [], $branchId);

        if ($entry !== null) {
            return $this->onlyFields($entry);
        }

        return $this->onlyFields(is_array($settings['receipt'] ?? null) ? $settings['receipt'] : []);
    }

    /** @param  array<string, mixed>  $data
     *  @return array<string, mixed>
     */
    public function update(int $branchId, array $data): array
    {
        $company = CompanySettings::current();
        $settings = $company->settings ?? [];
        $branches = is_array($settings['receipt_branches'] ?? null) ? $settings['receipt_branches'] : [];
        $clean = $this->onlyFields($data);
        $branches[(string) $branchId] = $clean;
        $settings['receipt_branches'] = $branches;
        $company->update(['settings' => $settings]);

        return $clean;
    }

    /** @param  array<mixed>  $branches
     *  @return array<string, mixed>|null
     */
    private function branchEntry(array $branches, int $branchId): ?array
    {
        $entry = $branches[(string) $branchId] ?? $branches[$branchId] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /** @param  array<string, mixed>  $data
     *  @return array<string, mixed>
     */
    private function onlyFields(array $data): array
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = $data[$field];
            }
        }

        return $clean;
    }
}
