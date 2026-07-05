<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClientNote;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;

class ClientNoteController extends Controller
{
    public function index(Request $request, User $client)
    {
        $notes = ClientNote::query()
            ->with([
                'author:id,name,email',
                'staff:id,name,email,phone',
                'appointment:id,reference_code,date,starts_at,service_id,status',
                'appointment.service:id,name',
            ])
            ->where('client_id', $client->id)
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->paginate((int) $request->get('per_page', 50));

        return response()->json([
            'data' => $notes->getCollection()->map(fn ($note) => $this->formatNote($note)),
            'meta' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
            ],
        ]);
    }

    public function store(Request $request, User $client)
    {
        $validated = $request->validate([
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'type' => ['nullable', 'in:general,treatment,preference,warning'],
            'note' => ['required', 'string', 'max:5000'],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $appointmentId = $validated['appointment_id'] ?? null;

        if ($appointmentId) {
            $appointment = Appointment::query()->findOrFail($appointmentId);

            if ((int) $appointment->user_id !== (int) $client->id) {
                return response()->json([
                    'message' => 'This appointment does not belong to this client.',
                ], 422);
            }
        }

        $user = $request->user();

        $staff = Staff::query()
            ->where('user_id', $user?->id)
            ->first();

        $note = ClientNote::query()->create([
            'client_id' => $client->id,
            'appointment_id' => $appointmentId,
            'author_id' => $user?->id,
            'staff_id' => $staff?->id,
            'author_role' => $staff ? 'staff' : 'admin',
            'type' => $validated['type'] ?? ($appointmentId ? 'treatment' : 'general'),
            'note' => $validated['note'],
            'pinned' => (bool) ($validated['pinned'] ?? false),
        ]);

        $note->load([
            'author:id,name,email',
            'staff:id,name,email,phone',
            'appointment:id,reference_code,date,starts_at,service_id,status',
            'appointment.service:id,name',
        ]);

        return response()->json([
            'data' => $this->formatNote($note),
        ], 201);
    }

    public function storeForAppointment(Request $request, Appointment $appointment)
    {
        if (!$appointment->user_id) {
            return response()->json([
                'message' => 'This appointment is not connected to a client.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'in:general,treatment,preference,warning'],
            'note' => ['required', 'string', 'max:5000'],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $staff = Staff::query()
            ->where('user_id', $user?->id)
            ->first();

        $note = ClientNote::query()->create([
            'client_id' => $appointment->user_id,
            'appointment_id' => $appointment->id,
            'author_id' => $user?->id,
            'staff_id' => $staff?->id,
            'author_role' => $staff ? 'staff' : 'admin',
            'type' => $validated['type'] ?? 'treatment',
            'note' => $validated['note'],
            'pinned' => (bool) ($validated['pinned'] ?? false),
        ]);

        $note->load([
            'author:id,name,email',
            'staff:id,name,email,phone',
            'appointment:id,reference_code,date,starts_at,service_id,status',
            'appointment.service:id,name',
        ]);

        return response()->json([
            'data' => $this->formatNote($note),
        ], 201);
    }

    public function destroy(User $client, ClientNote $note)
    {
        if ((int) $note->client_id !== (int) $client->id) {
            return response()->json([
                'message' => 'Note does not belong to this client.',
            ], 404);
        }

        $note->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Note deleted.',
        ]);
    }

    private function formatNote(ClientNote $note): array
    {
        return [
            'id' => $note->id,
            'client_id' => $note->client_id,
            'appointment_id' => $note->appointment_id,
            'author_role' => $note->author_role,
            'type' => $note->type,
            'note' => $note->note,
            'pinned' => $note->pinned,
            'created_at' => optional($note->created_at)->toISOString(),
            'updated_at' => optional($note->updated_at)->toISOString(),

            'author' => $note->author ? [
                'id' => $note->author->id,
                'name' => $note->author->name,
                'email' => $note->author->email,
            ] : null,

            'staff' => $note->staff ? [
                'id' => $note->staff->id,
                'name' => $note->staff->name,
                'email' => $note->staff->email,
                'phone' => $note->staff->phone,
            ] : null,

            'appointment' => $note->appointment ? [
                'id' => $note->appointment->id,
                'reference_code' => $note->appointment->reference_code,
                'date' => $note->appointment->date,
                'starts_at' => $note->appointment->starts_at,
                'status' => $note->appointment->status,
                'service' => $note->appointment->service ? [
                    'id' => $note->appointment->service->id,
                    'name' => $note->appointment->service->name,
                ] : null,
            ] : null,
        ];
    }
}