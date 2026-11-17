<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\TariffGroup;
use App\Services\BillingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Compte de démonstration pour essayer l'application en local :
 * php artisan db:seed --class=DemoSeeder
 *
 * Connexion : code DEMO-001, e-mail demo@cable-tv.local, mot de passe demo-cable-tv-1
 * Ne pas lancer en production : ce mot de passe est public.
 */
class DemoSeeder extends Seeder
{
    public function run(BillingService $billing): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Compte de démonstration refusé en production.');

            return;
        }

        if (Organisation::where('code', 'DEMO-001')->exists()) {
            $this->command?->info('Le compte de démonstration existe déjà.');

            return;
        }

        $organisation = Organisation::create([
            'name' => 'Câble Vision Démo', 'code' => 'DEMO-001', 'phone' => '+237677000000', 'phone_country' => 'CM',
            'address' => 'Akwa, Douala', 'currency' => 'XAF',
        ]);
        $organisation->forceFill([
            'trial_ends_at' => now()->addDays(30)->toDateString(),
            'default_due_day' => 5, 'cutoff_after_months' => 2, 'reconnection_fee' => 2000, 'reminder_days' => 3,
        ])->save();

        Auth::setUser($organisation->users()->create([
            'name' => 'Gérant Démo', 'email' => 'demo@cable-tv.local', 'password' => 'demo-cable-tv-1',
        ]));

        $groups = [
            'essentiel' => TariffGroup::create(['name' => 'Bouquet Essentiel', 'monthly_fee' => 3000, 'description' => 'Chaînes nationales, 1 téléviseur']),
            'confort' => TariffGroup::create(['name' => 'Bouquet Confort', 'monthly_fee' => 5000, 'description' => 'Chaînes nationales et internationales']),
            'premium' => TariffGroup::create(['name' => 'Bouquet Premium', 'monthly_fee' => 8000, 'description' => 'Tout le bouquet, sport et cinéma']),
        ];

        // [nom, sexe, téléphone, quartier, repère, latitude, longitude, groupe, tarif personnel, téléviseurs, début (mois avant), mois payés]
        $customers = [
            ['Jean-Pierre Mbarga', 'M', '699112233', 'Akwa', 'Derrière la pharmacie du Centre', 4.0489, 9.7020, 'confort', null, 1, 6, 7],
            ['Aïcha Bello', 'F', '677223344', 'Akwa', 'Rue Joss, immeuble bleu', 4.0461, 9.6987, 'premium', null, 2, 5, 8],
            ['Paul Nkoulou', 'M', '655334455', 'Bonapriso', 'Face école publique', 4.0215, 9.6940, 'premium', null, 3, 6, 4],
            ['Marie Ngo Bassong', 'F', '699445566', 'Bonapriso', 'Portail vert', 4.0190, 9.6973, 'confort', null, 1, 4, 3],
            ['Samuel Eto Mvondo', 'M', '677556677', 'Bépanda', 'Carrefour Tonnerre', 4.0702, 9.7212, 'essentiel', null, 1, 6, 3],
            ['Odile Tchoumi', 'F', '691667788', 'Bépanda', 'Près du marché', 4.0681, 9.7240, 'essentiel', 2500, 1, 5, 6],
            ['Hervé Kamga', 'M', '650778899', 'Makepe', 'Missoké, maison jaune', 4.0860, 9.7465, 'confort', null, 2, 3, 4],
            ['Christelle Ewane', 'F', '699889900', 'Makepe', 'Station Total', 4.0835, 9.7502, 'premium', null, 1, 6, 4],
            ['Ibrahim Moussa', 'M', '677990011', 'New Bell', 'Rue des Ministres', 4.0390, 9.7140, 'essentiel', null, 1, 6, 7],
            ['Florence Abena', 'F', '655001122', 'Deïdo', 'Boulangerie Saker', 4.0598, 9.7095, 'confort', null, 1, 2, 3],
            ['Thierry Fotso', 'M', '699101010', 'Bonamoussadi', 'Carrefour Maçon', 4.0921, 9.7381, null, 6000, 2, 6, 9],
            ['Grâce Manga', 'F', '677202020', 'Bonamoussadi', 'Derrière Santa Lucia', 4.0957, 9.7412, 'confort', null, 1, 5, 4],
            ['Alain Ndjock', 'M', '655303030', 'Logbaba', 'Lycée de Logbaba', 4.0425, 9.7820, 'essentiel', null, 1, 4, 5],
            ['Sandrine Ebongue', 'F', '699404040', 'Logbaba', 'Chapelle', 4.0450, 9.7855, 'essentiel', null, 1, 3, 0],
            ['Patrick Nana', 'M', '677505050', 'Deïdo', 'Rond-point Deïdo', 4.0625, 9.7060, 'premium', 7000, 2, 6, 7],
            ['Esther Kotto', 'F', '6555', 'Akwa', 'Numéro à corriger', 4.0502, 9.7041, 'confort', null, 1, 2, 1],
        ];

        $created = [];
        foreach ($customers as [$name, $gender, $phone, $area, $landmark, $lat, $lng, $group, $fee, $tvs, $since, $paidMonths]) {
            $customer = $billing->subscribe([
                'name' => $name, 'gender' => $gender, 'phone' => \App\Support\PhoneNumber::forStorage($phone, 'CM'),
                'neighbourhood' => $area, 'address' => 'Douala', 'landmark' => $landmark, 'latitude' => $lat, 'longitude' => $lng,
                'tariff_group_id' => $group ? $groups[$group]->id : null, 'monthly_fee' => $fee, 'tv_count' => $tvs,
                'start_date' => now()->subMonthsNoOverflow($since)->startOfMonth()->toDateString(), 'due_day' => 5,
            ]);

            // Les mois payés en deux fois : un premier versement, puis le reste plus tard (avance comprise).
            if ($paidMonths > 0) {
                $total = $paidMonths * $customer->effectiveFee();
                $first = min($total, 2 * $customer->effectiveFee());
                $billing->recordPayment($customer, $first, now()->subMonthsNoOverflow(max(0, $since - 1))->startOfMonth()->addDays(3)->min(today())->toDateString(), 'cash', null);
                if ($total > $first) {
                    $billing->recordPayment($customer, $total - $first, today()->subDays(count($created) % 9)->toDateString(), $paidMonths % 2 ? 'orange_money' : 'mtn_momo', 'TX'.str_pad((string) (count($created) + 1), 6, '0', STR_PAD_LEFT));
                }
            }

            $created[$name] = $customer;
        }

        // Une cliente coupée pour impayés, un client réabonné, un client résilié.
        $billing->suspend($created['Christelle Ewane'], today()->subDays(4)->toDateString(), '3 mois impayés');
        $billing->suspend($created['Samuel Eto Mvondo'], today()->subDays(40)->toDateString(), '2 mois impayés');
        $billing->recordPayment($created['Samuel Eto Mvondo']->fresh(), 3000, today()->subDays(3)->toDateString(), 'mtn_momo', 'MP-REAB');
        $billing->reconnect($created['Samuel Eto Mvondo']->fresh(), today()->subDays(2)->toDateString(), 2000, 'Payé au guichet');
        $billing->terminate($created['Florence Abena'], today()->subDays(10)->toDateString(), 'Déménagement à Yaoundé');

        $this->command?->info('Compte de démonstration créé : code DEMO-001.');
    }
}
