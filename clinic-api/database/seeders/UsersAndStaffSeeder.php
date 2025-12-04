<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Staff;
use App\Models\StaffSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UsersAndStaffSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1) Admin
            $admin = User::updateOrCreate(
                ['email' => 'admin@clinic.test'],
                [
                    'name'               => 'Clinic Admin',
                    'password'           => 'password', // will be hashed by casts()
                    'phone'              => '070000000',
                    'is_active'          => true,
                    'address_line1'      => 'Admin Street 1',
                    'city'               => 'Skopje',
                    'country_code'       => 'MK',
                    'preferred_language' => 'en',
                    'marketing_opt_in'   => false,
                    'notification_prefs' => [
                        'email_appointments' => true,
                        'email_marketing'    => false,
                    ],
                ]
            );
            $admin->syncRoles(['admin']);

            // 2) Staff users (3)
            $staffDefinitions = [
                [
                    'name'  => 'Laser Specialist 1',
                    'email' => 'staff1@clinic.test',
                    'phone' => '070000001',
                ],
                [
                    'name'  => 'Facial Specialist',
                    'email' => 'staff2@clinic.test',
                    'phone' => '070000002',
                ],
                [
                    'name'  => 'Laser Specialist 2',
                    'email' => 'staff3@clinic.test',
                    'phone' => '070000003',
                ],
            ];

            $staffModels = [];

            foreach ($staffDefinitions as $i => $data) {
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name'               => $data['name'],
                        'password'           => 'password', // cast hashes it
                        'phone'              => $data['phone'],
                        'is_active'          => true,
                        'address_line1'      => 'Staff Street ' . ($i + 1),
                        'city'               => 'Skopje',
                        'country_code'       => 'MK',
                        'preferred_language' => 'en',
                        'marketing_opt_in'   => false,
                        'notification_prefs' => [
                            'email_appointments' => true,
                            'email_marketing'    => false,
                        ],
                    ]
                );

                $user->syncRoles(['staff']);

                // Link to staff table
                $staff = Staff::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'name'      => $data['name'],
                        'email'     => $data['email'],
                        'phone'     => $data['phone'],
                        'is_active' => true,
                    ]
                );

                $staffModels[] = $staff;
            }

            // 3) Staff schedules (simple Mon–Fri 09:00–17:00 for all staff)
            foreach ($staffModels as $staff) {
                // clean previous schedules so seeder is idempotent
                StaffSchedule::where('staff_id', $staff->id)->delete();

                // 1=Mon … 5=Fri
                foreach ([1, 2, 3, 4, 5] as $weekday) {
                    StaffSchedule::create([
                        'staff_id'   => $staff->id,
                        'weekday'    => $weekday,
                        'start_time' => '09:00:00',
                        'end_time'   => '17:00:00',
                        'is_active'  => true,
                    ]);
                }
            }

            // 4) Client users (5)
            $clientDefinitions = [
                ['name' => 'Client One',   'email' => 'client1@clinic.test', 'phone' => '070000101'],
                ['name' => 'Client Two',   'email' => 'client2@clinic.test', 'phone' => '070000102'],
                ['name' => 'Client Three', 'email' => 'client3@clinic.test', 'phone' => '070000103'],
                ['name' => 'Client Four',  'email' => 'client4@clinic.test', 'phone' => '070000104'],
                ['name' => 'Client Five',  'email' => 'client5@clinic.test', 'phone' => '070000105'],
            ];

            foreach ($clientDefinitions as $i => $data) {
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name'               => $data['name'],
                        'password'           => 'password', // cast hashes it
                        'phone'              => $data['phone'],
                        'is_active'          => true,
                        'address_line1'      => 'Client Street ' . ($i + 1),
                        'city'               => 'Skopje',
                        'country_code'       => 'MK',
                        'preferred_language' => 'en',
                        'marketing_opt_in'   => true,
                        'notification_prefs' => [
                            'email_appointments' => true,
                            'email_marketing'    => true,
                        ],
                    ]
                );

                $user->syncRoles(['client']);
            }
        });
    }
}
