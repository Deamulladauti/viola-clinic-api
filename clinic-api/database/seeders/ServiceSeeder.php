<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;
use App\Models\ServiceCategory;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesBySlug = ServiceCategory::pluck('id', 'slug');

        $services = [

            // ───── LASER HAIR REMOVAL ─────
            [
                'category_slug' => 'laser-hair-removal',
                'name'          => 'Full Body – 6 Sessions',
                'slug'          => 'full-body-6-sessions',
                'duration'      => 60,
                'price'         => 450.00,
                'is_bookable'   => true,
                'is_package'    => true,
                'total_sessions'=> 6,
                'total_minutes' => null,

                'short_en' => 'A full-body laser hair removal package consisting of six sessions for long-lasting results.',
                'short_sq' => 'Paketë lazer për gjithë trupin me gjashtë seanca për rezultate afatgjata.',
                'short_mk' => 'Пакет ласерско отстранување влакна на цело тело со шест сесии за долготрајни резултати.',

                'desc_en' => 'This six-session full body laser hair removal program is designed to achieve smooth, long-lasting hair reduction across the entire body. Ideal for clients seeking a complete transformation with professional-grade laser technology.',
                'desc_sq' => 'Ky program me gjashtë seanca lazer për gjithë trupin është krijuar për të arritur reduktim afatgjatë të qimes në të gjithë trupin. Ideal për klientët që kërkojnë një transformim të plotë me teknologji profesionale lazer.',
                'desc_mk' => 'Овој шестсесиjски пакет за ласерско отстранување влакна на цело тело овозможува долготрајно намалување на влакната. Идеален за клиенти кои бараат целосна промена со професионална ласерска технологија.',

                'prep_en' => 'Shave 24 hours before the session. Avoid sun exposure, tanning beds, and self-tan for 7 days. Do not apply lotions or deodorants before treatment.',
                'prep_sq' => 'Rruani zonat 24 orë para seancës. Shmangni diellin, solarin dhe kremin e nxirjes për 7 ditë. Mos aplikoni kremra apo deodorant para trajtimit.',
                'prep_mk' => 'Избричете 24 часа пред третманот. Избегнувајте сонце, солариум и самопотемнувач 7 дена. Не нанесувајте лосиони или дезодоранс пред третманот.',
            ],

            [
                'category_slug' => 'laser-hair-removal',
                'name'          => 'Full Body – 1 Session',
                'slug'          => 'full-body-1-session',
                'duration'      => 60,
                'price'         => 100.00,
                'is_bookable'   => true,
                'is_package'    => false,
                'total_sessions'=> 1,
                'total_minutes' => null,

                'short_en' => 'A single full-body laser hair removal session for smooth and quick results.',
                'short_sq' => 'Një seancë e vetme lazer për gjithë trupin për rezultate të shpejta dhe të lëmuara.',
                'short_mk' => 'Една сесија ласерско отстранување влакна на цело тело за брзи и мазни резултати.',

                'desc_en' => 'A one-time full body laser session suitable for clients trying laser treatment or maintaining previous results. Covers all major body areas in one visit.',
                'desc_sq' => 'Një seancë e vetme lazer për gjithë trupin, e përshtatshme për klientët që duan të provojnë lazerin ose të mirëmbajnë rezultatet e mëparshme. Përfshin të gjitha zonat kryesore të trupit.',
                'desc_mk' => 'Единечна сесија за цело тело, идеална за клиенти кои сакаат да го пробаат ласерот или да ги одржат претходните резултати. Опфаќа сите главни региони на телото.',

                'prep_en' => 'Shave 24 hours before the appointment. Avoid sun exposure for at least 5 days. Keep skin clean before treatment.',
                'prep_sq' => 'Rruani zonat 24 orë para vizitës. Shmangni diellin për të paktën 5 ditë. Mbani lëkurën të pastër para trajtimit.',
                'prep_mk' => 'Избричете 24 часа претходно. Избегнувајте сонце најмалку 5 дена. Одржувајте ја кожата чиста пред третманот.',
            ],

            // ───── FACIAL TREATMENTS ─────
            [
                'category_slug' => 'facial-treatments',
                'name'          => 'Skin Analysis',
                'slug'          => 'skin-analysis',
                'duration'      => 20,
                'price'         => 40.00,
                'is_bookable'   => true,
                'is_package'    => false,
                'total_sessions'=> 1,
                'total_minutes' => null,

                'short_en' => 'A personalized skin assessment to determine skin type and optimal treatment plan.',
                'short_sq' => 'Vlerësim personal i lëkurës për të përcaktuar tipin dhe planin optimal të trajtimit.',
                'short_mk' => 'Персонализирана анализа на кожата за утврдување на типот и идеалниот третман.',

                'desc_en' => 'A detailed skin examination using professional tools to evaluate hydration, texture, pores, and sensitivity. Recommended before any advanced facial treatment.',
                'desc_sq' => 'Një ekzaminim i detajuar i lëkurës duke përdorur mjete profesionale për të vlerësuar hidratimin, strukturën, poret dhe ndjeshmërinë. Rekomandohet para çdo trajtimi të avancuar.',
                'desc_mk' => 'Детален преглед на кожата со професионални алатки за процена на хидратацијата, текстурата, порите и чувствителноста. Препорачливо пред секој напреден третман.',

                'prep_en' => 'Arrive with a clean face. Avoid makeup and heavy creams before the session.',
                'prep_sq' => 'Ejani me fytyrë të pastër. Shmangni makeup-in dhe kremrat e rëndë para seancës.',
                'prep_mk' => 'Доjдете со чисто лице. Избегнувајте шминка и тешки креми пред сесијата.',
            ],

            [
                'category_slug' => 'facial-treatments',
                'name'          => 'C2O2 Facial',
                'slug'          => 'c2o2-facial',
                'duration'      => 70,
                'price'         => 90.00,
                'is_bookable'   => true,
                'is_package'    => false,
                'total_sessions'=> 1,
                'total_minutes' => null,

                'short_en' => 'An advanced oxygen-boost facial that revitalizes, brightens, and deeply nourishes the skin.',
                'short_sq' => 'Trajtim i avancuar me oksigjen që rigjallëron, ndriçon dhe ushqen thellë lëkurën.',
                'short_mk' => 'Напреден кислороден третман што ревитализира, осветлува и длабински ја храни кожата.',

                'desc_en' => 'This oxygen-infused treatment increases cellular energy, improves elasticity, and enhances skin radiance. Ideal for dull, tired, and dehydrated skin.',
                'desc_sq' => 'Ky trajtim me oksigjen rrit energjinë qelizore, përmirëson elasticitetin dhe rrit shkëlqimin e lëkurës. Ideal për lëkurë të lodhur dhe të dehidratuar.',
                'desc_mk' => 'Третман со кислород што ја зголемува енергијата на клетките, ја подобрува еластичноста и го зголемува сјајот на кожата. Идеален за уморна и дехидрирана кожа.',

                'prep_en' => 'Avoid heavy makeup 12 hours before treatment. Stay hydrated and avoid peeling products for 48 hours.',
                'prep_sq' => 'Shmangni makeup-in e rëndë 12 orë para trajtimit. Qëndroni të hidratuar dhe shmangni produktet eksfoliuese për 48 orë.',
                'prep_mk' => 'Избегнувајте тешка шминка 12 часа претходно. Хидрирајте се и избегнувајте пилинг производи 48 часа.',
            ],

            // ───── SOLARIUM ─────
            [
                'category_slug' => 'solarium',
                'name'          => 'Solarium – 50 Minutes Package',
                'slug'          => 'solarium-50-minutes-package',
                'duration'      => 50,
                'price'         => 100.00,
                'is_bookable'   => false,
                'is_package'    => true,
                'total_sessions'=> null,
                'total_minutes' => 50,

                'short_en' => 'A 50-minute solarium package ideal for maintaining a natural tan.',
                'short_sq' => 'Paketë solariumi me 50 minuta, ideale për të ruajtur një ngjyrë natyrale.',
                'short_mk' => 'Пакет солариум од 50 минути, идеален за одржување природен тен.',

                'desc_en' => 'This package includes a total of 50 solarium minutes that can be used flexibly across multiple sessions to maintain or enhance your tan.',
                'desc_sq' => 'Kjo paketë përfshin 50 minuta solarium që mund të përdoren në disa seanca për të ruajtur ose rritur nxirjen.',
                'desc_mk' => 'Овој пакет содржи 50 минути солариум што може да се користат во повеќе посети за одржување или подобрување на тенот.',

                'prep_en' => 'Use SPF on sensitive areas before tanning. Stay hydrated and avoid tanning two days in a row.',
                'prep_sq' => 'Përdorni SPF në zonat e ndjeshme para seancës. Qëndroni të hidratuar dhe shmangni solarin dy ditë rresht.',
                'prep_mk' => 'Користете SPF на чувствителни области пред сончање. Хидрирајте се и избегнувајте солариум два дена по ред.',
            ],

            [
                'category_slug' => 'solarium',
                'name'          => 'Solarium – 100 Minutes Package',
                'slug'          => 'solarium-100-minutes-package',
                'duration'      => 100,
                'price'         => 200.00,
                'is_bookable'   => false,
                'is_package'    => true,
                'total_sessions'=> null,
                'total_minutes' => 100,

                'short_en' => 'A 100-minute solarium package for deeper, longer-lasting tanning.',
                'short_sq' => 'Paketë solariumi me 100 minuta për një nxirje më të thellë dhe afatgjatë.',
                'short_mk' => 'Пакет солариум од 100 минути за подлабок и подолготраен тен.',

                'desc_en' => 'This extended tanning package provides 100 minutes of solarium time, ideal for clients who want a stronger summer glow. Minutes can be split across visits.',
                'desc_sq' => 'Kjo paketë e zgjeruar ofron 100 minuta solarium, ideale për klientët që duan një nxirje më të fortë. Minutat mund të ndahen në vizita të ndryshme.',
                'desc_mk' => 'Овој проширен пакет обезбедува 100 минути солариум, идеален за клиенти кои сакаат поинтензивен летен тен. Може да се користи во повеќе посети.',
                'prep_en' => 'Avoid tanning oils before use. Hydrate well and apply after-sun lotion after each session.',
                'prep_sq' => 'Shmangni vajrat e nxirjes para përdorimit. Hidratojuni mirë dhe përdorni krem pas-diellit pas çdo seance.',
                'prep_mk' => 'Избегнувајте масла за сончање пред користење. Хидрирајте се и нанесувајте крем после сончање по секоја сесија.',
            ],
        ];

        foreach ($services as $data) {
            $categoryId = $categoriesBySlug[$data['category_slug']] ?? null;
            if (!$categoryId) {
                continue; // or throw, but skipping is safer in seeder
            }

            Service::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'service_category_id' => $categoryId,
                    'name'                => $data['name'],
                    'short_description'   => $data['short_en'],
                    'description'         => $data['desc_en'],
                    'duration_minutes'    => $data['duration'],
                    'price'               => $data['price'],
                    'is_active'           => true,
                    'is_bookable'         => $data['is_bookable'],
                    'is_package'          => $data['is_package'],
                    'total_sessions'      => $data['total_sessions'],
                    'total_minutes'       => $data['total_minutes'],

                    'name_i18n' => [
                        'en' => $data['name'],
                        'sq' => $data['name'],
                        'mk' => $data['name'],
                    ],
                    'short_description_i18n' => [
                        'en' => $data['short_en'],
                        'sq' => $data['short_sq'],
                        'mk' => $data['short_mk'],
                    ],
                    'description_i18n' => [
                        'en' => $data['desc_en'],
                        'sq' => $data['desc_sq'],
                        'mk' => $data['desc_mk'],
                    ],
                    'prep_instructions' => [
                        'en' => $data['prep_en'],
                        'sq' => $data['prep_sq'],
                        'mk' => $data['prep_mk'],
                    ],
                ]
            );
        }
    }
}
