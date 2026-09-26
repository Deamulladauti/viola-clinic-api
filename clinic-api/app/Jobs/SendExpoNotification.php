<?php

namespace App\Jobs;

use App\Models\ExpoPushDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendExpoNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $userId, public string $notificationId) {}

    public function handle(): void
    {
        $user = \App\Models\User::find($this->userId);
        if (!$user || $user->notifications_enabled === false) return;
        $notification = $user->notifications()->where('id', $this->notificationId)->first();
        if (!$notification) return;
        $data = $notification->data;
        $devices = ExpoPushDevice::where('user_id', $user->id)->get();
        foreach ($devices->chunk(100) as $batch) {
            $payload = $batch->map(fn ($device) => [
                'to' => $device->token,
                'title' => $data['title'] ?? 'Viola Clinic',
                'body' => $data['body'] ?? 'You have a new notification.',
                'sound' => 'default',
                'data' => [
                    'notification_id' => $notification->id,
                    'appointment_id' => $data['appointment_id'] ?? null,
                    'booking_group_id' => $data['booking_group_id'] ?? null,
                ],
            ])->values()->all();
            $response = Http::timeout(15)->post('https://exp.host/--/api/v2/push/send', $payload);
            if (!$response->successful()) {
                $response->throw(); // Let Laravel retry temporary network/service failures.
            }
            $tickets = $response->json('data');
            if (isset($tickets['status'])) $tickets = [$tickets];
            foreach ($batch->values() as $index => $device) {
                $ticket = $tickets[$index] ?? null;
                if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $device->delete();
                } elseif (($ticket['status'] ?? '') === 'error') {
                    Log::warning('Expo push ticket failed', ['user_id' => $user->id, 'error' => $ticket['details']['error'] ?? $ticket['message'] ?? 'unknown']);
                }
            }
        }
    }
}
