<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\Tag;

class ServiceTagSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            // service_slug => [tag_slugs...]
            'full-body-6-sessions'          => ['laser', 'package', 'premium'],
            'full-body-1-session'           => ['laser', 'single-session'],
            'skin-analysis'                 => ['facial'],
            'c2o2-facial'                   => ['facial', 'premium'],
            'solarium-50-minutes-package'   => ['solarium', 'package'],
            'solarium-100-minutes-package'  => ['solarium', 'package', 'premium'],
        ];

        $tagsBySlug = Tag::pluck('id', 'slug');

        foreach ($map as $serviceSlug => $tagSlugs) {
            $service = Service::where('slug', $serviceSlug)->first();
            if (!$service) continue;

            $tagIds = [];
            foreach ($tagSlugs as $slug) {
                if (isset($tagsBySlug[$slug])) {
                    $tagIds[] = $tagsBySlug[$slug];
                }
            }

            if (!empty($tagIds)) {
                $service->tags()->syncWithoutDetaching($tagIds);
            }
        }
    }
}
