<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Staff;

class ServiceStaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff1 = Staff::where('email', 'staff1@clinic.test')->first();
        $staff2 = Staff::where('email', 'staff2@clinic.test')->first();
        $staff3 = Staff::where('email', 'staff3@clinic.test')->first();

        if (!$staff1 || !$staff2 || !$staff3) {
            return; // ensure UsersAndStaffSeeder ran first
        }

        $bySlug = fn(string $slug) => Service::where('slug', $slug)->first();

        $laser6  = $bySlug('full-body-6-sessions');
        $laser1  = $bySlug('full-body-1-session');
        $skin    = $bySlug('skin-analysis');
        $c2o2    = $bySlug('c2o2-facial');
        $sol50   = $bySlug('solarium-50-minutes-package');
        $sol100  = $bySlug('solarium-100-minutes-package');

        // Laser → staff1 + staff3
        foreach ([$laser6, $laser1] as $service) {
            if ($service) {
                $service->staff()->syncWithoutDetaching([$staff1->id, $staff3->id]);
            }
        }

        // Facials → staff2
        foreach ([$skin, $c2o2] as $service) {
            if ($service) {
                $service->staff()->syncWithoutDetaching([$staff2->id]);
            }
        }

        // Solarium → staff3 (random choice, non-bookable anyway)
        foreach ([$sol50, $sol100] as $service) {
            if ($service) {
                $service->staff()->syncWithoutDetaching([$staff3->id]);
            }
        }
    }
}
