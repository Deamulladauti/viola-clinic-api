<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminInsightsController extends Controller
{
    public function index(Request $request)
    {
        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));

        $today = Carbon::today();
        $fromDate = (clone $today)->subDays(6)->toDateString();

        $totalBookings = Appointment::count();
        $todayBookings = Appointment::whereDate('date', $today->toDateString())->count();

        $confirmed = Appointment::where('status', 'confirmed')->count();
        $completed = Appointment::where('status', 'completed')->count();
        $cancelled = Appointment::where('status', 'cancelled')->count();
        $noShow = Appointment::where('status', 'no_show')->count();

        $revenueTotal = Appointment::whereIn('status', ['confirmed', 'completed'])->sum('price');

        $revenueToday = Appointment::whereDate('date', $today->toDateString())
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('price');

        $topServices = Appointment::query()
            ->select('service_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(price) as revenue'))
            ->with('service:id,name')
            ->whereNotNull('service_id')
            ->groupBy('service_id')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->service?->name ?? 'Unknown',
                    'count' => (int) $row->count,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values();

        $rawByDay = Appointment::query()
            ->select('date', DB::raw('COUNT(*) as count'))
            ->whereDate('date', '>=', $fromDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(function ($row) {
                return $row->date instanceof Carbon
                    ? $row->date->toDateString()
                    : Carbon::parse($row->date)->toDateString();
            });

        $bookingsByDay = collect(range(0, 6))->map(function ($i) use ($today, $rawByDay) {
            $date = (clone $today)->subDays(6 - $i)->toDateString();
            $row = $rawByDay->get($date);

            return [
                'date' => $date,
                'count' => $row ? (int) $row->count : 0,
            ];
        })->values();

        return response()->json([
            'totals' => [
                'total_bookings' => (int) $totalBookings,
                'today_bookings' => (int) $todayBookings,
                'confirmed' => (int) $confirmed,
                'completed' => (int) $completed,
                'cancelled' => (int) $cancelled,
                'no_show' => (int) $noShow,
                'revenue_total' => (float) $revenueTotal,
                'revenue_today' => (float) $revenueToday,
            ],
            'top_services' => $topServices,
            'bookings_by_day' => $bookingsByDay,
        ]);
    }
}