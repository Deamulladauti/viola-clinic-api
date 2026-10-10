<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HomeBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class HomeBannerController extends Controller
{
    // Public: GET /api/v1/home-banner
    public function show()
    {
        $banner = HomeBanner::query()->latest('id')->first();

        return response()->json([
            'data' => $banner,
        ]);
    }

    // Admin: POST /api/v1/admin/home-banner
    public function update(Request $request)
    {
        $request->validate([
            'media' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'mp4'])
                    ->max('30mb'),
            ],
        ]);

        $file = $request->file('media');

        $mediaType = $file->getMimeType() === 'video/mp4'
            ? 'video'
            : 'image';

        // Store the new file before replacing the old banner.
        $newPath = $file->store('home-banners', 'public');

        if (!$newPath) {
            return response()->json([
                'message' => 'Unable to upload banner.',
            ], 500);
        }

        try {
            $banner = HomeBanner::query()->first();

            $oldPath = $banner?->media_path;

            if ($banner) {
                $banner->update([
                    'media_type' => $mediaType,
                    'media_path' => $newPath,
                ]);
            } else {
                $banner = HomeBanner::create([
                    'media_type' => $mediaType,
                    'media_path' => $newPath,
                ]);
            }
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($newPath);
            throw $e;
        }

        // Delete the previous file only after saving the new banner.
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Home banner updated successfully.',
            'data' => $banner->fresh(),
        ]);
    }

    // Admin: DELETE /api/v1/admin/home-banner
    public function destroy()
    {
        $banner = HomeBanner::query()->first();

        if (!$banner) {
            return response()->json([
                'message' => 'No custom banner configured.',
            ]);
        }

        $path = $banner->media_path;

        $banner->delete();

        Storage::disk('public')->delete($path);

        return response()->json([
            'message' => 'Home banner removed. The default image will be used.',
        ]);
    }
}
