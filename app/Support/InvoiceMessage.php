<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Messages préparés dans l'application de l'utilisateur : c'est lui qui appuie sur Envoyer.
// Dans WhatsApp, *texte* s'affiche en gras. Pas d'émoji : ils arrivent cassés à l'ouverture du message. Les SMS restent courts et sans accents :
// un accent peut réduire un SMS à 70 caractères et doubler son coût.
class InvoiceMessage
{
    private const RULE = '━━━━━━━━━━━━━━━━━━';

    public static function whatsapp(Invoice $invoice): string
    {
        $customer = $invoice->customer;
        $months = $invoice->unpaidMonths();

        $details = [
            __('N° de facture : :v1', ['v1' => $invoice->number()]),
            __('Client : :v1', ['v1' => $customer->numberLabel()]),
            __('Abonnement mensuel : :v1', ['v1' => money($invoice->subscriptionAmount())]),
        ];

        if ((float) $invoice->reconnection_fee > 0) {
            $details[] = __('Frais de réabonnement : :v1', ['v1' => money($invoice->reconnection_fee)]);
        }

        return self::card(
            __('Facture câble TV — :v1', ['v1' => $invoice->label()]),
            [self::greeting($customer)],
            $details,
            self::totalLines($months, $invoice->isOverdue()
                ? __('Échéance dépassée depuis le *:v1*. Merci de régler dès que possible.', ['v1' => $invoice->due_date->format('d/m/Y')])
                : __('À régler avant le *:v1*.', ['v1' => $invoice->due_date->format('d/m/Y')])),
            __('Merci de votre fidélité.'),
            self::monthLines($months),
        );
    }

    public static function sms(Invoice $invoice): string
    {
        $customer = $invoice->customer;
        $total = $customer->unpaidTotal();

        $text = self::prefix().__('Bonjour :v1, abonnement TV :v2, client :v3.', [
            'v1' => $customer->smsName(),
            'v2' => $invoice->label(),
            'v3' => $customer->number,
        ]);

        $text .= $total > 0
            ? ' '.__('Total a payer : :v1 avant le :v2.', ['v1' => money($total), 'v2' => $invoice->due_date->format('d/m/Y')])
            : ' '.__('Ce mois est regle. Merci.');

        return self::finishSms($text);
    }

    // Le rappel envoyé quelques jours avant l'échéance.
    public static function reminder(Invoice $invoice): string
    {
        $customer = $invoice->customer;
        $months = $invoice->unpaidMonths();

        return self::card(
            __('Rappel d\'abonnement'),
            [self::greeting($customer), self::dueSentence($invoice)],
            [__('Client : :v1', ['v1' => $customer->numberLabel()]), __('Abonnement mensuel : :v1', ['v1' => money($customer->effectiveFee())])],
            self::totalLines($months, __('Payez à temps pour garder votre signal sans interruption.')),
            self::alreadyPaidNote(),
            self::monthLines($months),
        );
    }

    public static function reminderSms(Invoice $invoice): string
    {
        $text = self::prefix().__('Rappel pour :v1, client :v2 : :v3 a payer avant le :v4.', [
            'v1' => $invoice->customer->smsName(),
            'v2' => $invoice->customer->number,
            'v3' => money($invoice->customer->unpaidTotal()),
            'v4' => $invoice->due_date->format('d/m/Y'),
        ]);

        return self::finishSms($text);
    }

    // La confirmation après un paiement, avec les mois qu'il règle.
    public static function payment(Payment $payment): string
    {
        $customer = $payment->customer;
        $remaining = $customer->unpaidTotal();

        $details = [
            __('Date : :v1', ['v1' => $payment->paid_at->format('d/m/Y')]),
            __('Client : :v1', ['v1' => $customer->numberLabel()]),
        ];

        if ($payment->method) {
            $details[] = __('Mode : :v1', ['v1' => $payment->methodLabel()]);
        }

        if ($payment->reference) {
            $details[] = __('Référence : :v1', ['v1' => $payment->reference]);
        }

        $details[] = __('Mois réglés : :v1', ['v1' => $payment->coveredLabel()]);
        if ($paidThrough = $customer->paidThrough()) {
            $details[] = __('Abonnement payé jusqu\'au :v1', ['v1' => '*'.$paidThrough->format('d/m/Y').'*']);
        }

        return self::card(
            __('Confirmation de paiement'),
            [self::greeting($customer), __('Nous confirmons avoir reçu votre paiement de *:v1*.', ['v1' => money($payment->amount)])],
            $details,
            [$remaining > 0
                ? '*'.__('Reste à payer : :v1', ['v1' => money($remaining)]).'*'
                : '*'.__('Votre abonnement est à jour.').'*'],
            __('Merci de votre fidélité.'),
        );
    }

    public static function paymentSms(Payment $payment): string
    {
        $customer = $payment->customer;
        $remaining = $customer->unpaidTotal();

        // Tenir dans un seul SMS : la date « payé jusqu'au » dit déjà quels mois sont réglés.
        $paidThrough = $customer->paidThrough();
        $text = self::prefix().__('Recu :v1 de :v2 le :v3.', [
            'v1' => money($payment->amount),
            'v2' => $customer->smsName(),
            'v3' => $payment->paid_at->format('d/m/Y'),
        ]);
        $text .= ' '.($paidThrough
            ? __('Paye jusqu\'au :v1.', ['v1' => $paidThrough->format('d/m/Y')])
            : __('Mois : :v1.', ['v1' => self::coveredRange($payment)]));
        $text .= ' '.($remaining > 0 ? __('Reste a payer : :v1.', ['v1' => money($remaining)]) : __('Abonnement a jour.'));

        return self::finishSms($text);
    }

    // La relance d'un client en retard : tous ses mois échus, et le risque de coupure.
    public static function lateNotice(Customer $customer, Collection $invoices): string
    {
        return self::card(
            __('Abonnement en retard'),
            [self::greeting($customer), __('L\'échéance de paiement de votre abonnement est dépassée :')],
            $invoices->map(fn (Invoice $invoice) => $invoice->label().' : '.money($invoice->balance()))->all(),
            [
                '*'.__('Total à payer : :v1', ['v1' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance()))]).'*',
                __('Sans paiement, votre signal sera coupé.'),
            ],
            self::alreadyPaidNote(),
        );
    }

    public static function lateNoticeSms(Customer $customer, Collection $invoices): string
    {
        $text = self::prefix().__('Abonnement en retard, :v1, client :v2 : :v3 a payer. Sans paiement, le signal sera coupe.', [
            'v1' => $customer->smsName(),
            'v2' => $customer->number,
            'v3' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance())),
        ]);

        return self::finishSms($text);
    }

    // Le préavis de coupure : le client a atteint le nombre de mois en retard réglé dans les paramètres.
    public static function cutoffWarning(Customer $customer, Collection $invoices): string
    {
        return self::card(
            __('Préavis de coupure'),
            [self::greeting($customer), __('Votre abonnement compte :v1 mois impayés :', ['v1' => $invoices->count()])],
            [__('Client : :v1', ['v1' => $customer->numberLabel()]), ...$invoices->map(fn (Invoice $invoice) => $invoice->label().' : '.money($invoice->balance()))->all()],
            [
                '*'.__('Total à payer : :v1', ['v1' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance()))]).'*',
                '*'.__('Sans paiement rapide, votre signal sera coupé.').'*',
            ],
            self::alreadyPaidNote(),
        );
    }

    public static function cutoffWarningSms(Customer $customer, Collection $invoices): string
    {
        $text = self::prefix().__('Preavis de coupure, :v1, client :v2 : :v3 mois impayes, :v4 a payer. Sans paiement, signal coupe.', [
            'v1' => $customer->smsName(),
            'v2' => $customer->number,
            'v3' => $invoices->count(),
            'v4' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance())),
        ]);

        return self::finishSms($text);
    }

    public static function cutoffWarningLink(Customer $customer, Collection $invoices, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($customer->phone, self::cutoffWarningSms($customer, $invoices))
            : self::whatsappUrl($customer->phone, self::cutoffWarning($customer, $invoices));
    }

    // L'avis de coupure : la date, ce qui reste dû et ce qu'il faut payer pour retrouver le signal.
    public static function cutoff(Customer $customer, CustomerEvent $event): string
    {
        $due = $customer->unpaidTotal();
        $fee = (float) $customer->organisation->reconnection_fee;
        $details = [
            __('Client : :v1', ['v1' => $customer->numberLabel()]),
            __('Date de coupure : :v1', ['v1' => '*'.$event->happened_on->format('d/m/Y').'*']),
        ];

        if ($event->note) {
            $details[] = __('Motif : :v1', ['v1' => $event->note]);
        }

        $highlight = [];
        if ($due > 0) {
            $highlight[] = '*'.__('Montant dû : :v1', ['v1' => money($due)]).'*';
        }
        if ($fee > 0) {
            $highlight[] = __('Frais de réabonnement : :v1', ['v1' => money($fee)]);
        }
        $highlight[] = __('Le signal sera rétabli après règlement.');

        return self::card(
            __('Avis de coupure'),
            [self::greeting($customer), __('Votre signal de télévision a été coupé pour défaut de paiement.')],
            $details,
            $highlight,
            __('Pour toute question, contactez-nous.'),
        );
    }

    public static function cutoffSms(Customer $customer, CustomerEvent $event): string
    {
        $due = $customer->unpaidTotal();
        $text = self::prefix().__('Signal coupe le :v1, :v2, client :v3.', [
            'v1' => $event->happened_on->format('d/m/Y'),
            'v2' => $customer->smsName(),
            'v3' => $customer->number,
        ]);

        if ($due > 0) {
            $text .= ' '.__('Montant du : :v1.', ['v1' => money($due)]);
        }

        return self::finishSms($text.' '.__('Retabli apres paiement.'));
    }

    // La confirmation du réabonnement : le signal revient.
    public static function reconnection(Customer $customer, CustomerEvent $event): string
    {
        $details = [
            __('Client : :v1', ['v1' => $customer->numberLabel()]),
            __('Date : :v1', ['v1' => $event->happened_on->format('d/m/Y')]),
            __('Abonnement mensuel : :v1', ['v1' => money($customer->effectiveFee())]),
        ];

        if ((float) $event->amount > 0) {
            $details[] = __('Frais de réabonnement : :v1', ['v1' => money($event->amount)]);
        }

        $due = $customer->unpaidTotal();

        return self::card(
            __('Réabonnement confirmé'),
            [self::greeting($customer), __('Votre signal de télévision est rétabli. Bon visionnage !')],
            $details,
            [$due > 0 ? '*'.__('Reste à payer : :v1', ['v1' => money($due)]).'*' : '*'.__('Votre abonnement est à jour.').'*'],
            __('Merci de votre fidélité.'),
        );
    }

    public static function reconnectionSms(Customer $customer, CustomerEvent $event): string
    {
        $due = $customer->unpaidTotal();
        $text = self::prefix().__('Signal retabli le :v1, :v2, client :v3.', [
            'v1' => $event->happened_on->format('d/m/Y'),
            'v2' => $customer->smsName(),
            'v3' => $customer->number,
        ]);

        return self::finishSms($text.' '.($due > 0 ? __('Reste a payer : :v1.', ['v1' => money($due)]) : __('Abonnement a jour.')));
    }

    public static function eventLink(Customer $customer, CustomerEvent $event, string $channel): string
    {
        $suspended = $event->type === CustomerEvent::SUSPENDED;

        return $channel === 'sms'
            ? self::smsUrl($customer->phone, $suspended ? self::cutoffSms($customer, $event) : self::reconnectionSms($customer, $event))
            : self::whatsappUrl($customer->phone, $suspended ? self::cutoff($customer, $event) : self::reconnection($customer, $event));
    }

    public static function invoiceLink(Invoice $invoice, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($invoice->customer->phone, self::sms($invoice))
            : self::whatsappUrl($invoice->customer->phone, self::whatsapp($invoice));
    }

    public static function reminderLink(Invoice $invoice, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($invoice->customer->phone, self::reminderSms($invoice))
            : self::whatsappUrl($invoice->customer->phone, self::reminder($invoice));
    }

    public static function paymentLink(Payment $payment, string $channel): string
    {
        $phone = $payment->customer->phone;

        return $channel === 'sms'
            ? self::smsUrl($phone, self::paymentSms($payment))
            : self::whatsappUrl($phone, self::payment($payment));
    }

    public static function lateNoticeLink(Customer $customer, Collection $invoices, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($customer->phone, self::lateNoticeSms($customer, $invoices))
            : self::whatsappUrl($customer->phone, self::lateNotice($customer, $invoices));
    }

    // Depuis le navigateur, on redirige directement vers WhatsApp ou les SMS. Depuis l'application installée,
    // Android bloque l'ouverture d'une autre application après une redirection : on affiche une page avec
    // un bouton à toucher, qui ouvre le message.
    public static function open(string $url): Response
    {
        if (request()->input('via') !== 'app') {
            return redirect()->away($url);
        }

        return response()->view('messages.open', [
            'url' => $url,
            'launch' => self::launchUrl($url, (string) request()->userAgent()),
            'channel' => str_starts_with($url, 'sms:') ? 'sms' : 'whatsapp',
            'back' => url()->previous(),
        ]);
    }

    // Sur Android, l'adresse « intent » ouvre WhatsApp ou WhatsApp Business (au choix s'il y a les deux),
    // et revient au lien wa.me si aucun n'est installé.
    public static function launchUrl(string $url, string $userAgent): string
    {
        if (! str_contains($userAgent, 'Android') || ! preg_match('#^https://wa\.me/(\d+)\?text=(.*)$#s', $url, $parts)) {
            return $url;
        }

        return 'intent://send/?phone='.$parts[1].'&text='.$parts[2]
            .'#Intent;scheme=whatsapp;S.browser_fallback_url='.rawurlencode($url).';end';
    }

    private static function dueSentence(Invoice $invoice): string
    {
        $days = (int) today()->diffInDays($invoice->due_date);

        return match (true) {
            $days <= 0 => __('Votre abonnement arrive à échéance *aujourd\'hui*.'),
            $days === 1 => __('Votre abonnement arrive à échéance *demain, le :v1*.', ['v1' => $invoice->due_date->format('d/m/Y')]),
            default => __('Votre abonnement arrive à échéance dans :v1 jours, le *:v2*.', ['v1' => $days, 'v2' => $invoice->due_date->format('d/m/Y')]),
        };
    }

    private static function whatsappUrl(string $phone, string $message): string
    {
        return 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($message);
    }

    // « ?& » : la forme acceptée à la fois par Android et par iPhone.
    private static function smsUrl(string $phone, string $message): string
    {
        return 'sms:'.$phone.'?&body='.rawurlencode($message);
    }

    // Une ligne par mois dû, avec ce qui reste à payer sur ce mois.
    private static function monthLines(Collection $months): array
    {
        return $months->map(fn (Invoice $month) => '• '.$month->label().' : '.money($month->balance())
            .($month->isOverdue() ? ' ('.__('en retard').')' : ''))->values()->all();
    }

    private static function totalLines(Collection $months, ?string $closing = null): array
    {
        $overdue = $months->filter(fn (Invoice $month) => $month->isOverdue());
        $lines = ['*'.__('Total à payer : :v1', ['v1' => money($months->sum(fn (Invoice $month) => $month->balance()))]).'*'];

        if ($overdue->isNotEmpty() && $overdue->count() < $months->count()) {
            $lines[] = __('Dont en retard : :v1', ['v1' => money($overdue->sum(fn (Invoice $month) => $month->balance()))]);
        }

        return $closing ? [...$lines, $closing] : $lines;
    }

    // Dans un SMS, plusieurs mois tiennent en une plage : les paiements règlent des mois qui se suivent.
    private static function coveredRange(Payment $payment): string
    {
        $months = $payment->allocations->sortBy(fn ($share) => $share->invoice->period)->values();

        return $months->count() > 2
            ? __(':v1 à :v2', ['v1' => $months->first()->invoice->label(), 'v2' => $months->last()->invoice->label()])
            : $payment->coveredLabel();
    }

    // Un client qui a déjà payé appelle le distributeur pour mettre son compte au clair.
    private static function alreadyPaidNote(): string
    {
        $phone = auth()->user()->organisation->phone;

        return $phone
            ? __('Si vous avez déjà payé, contactez-nous au :v1 pour mettre votre compte à jour.', ['v1' => $phone])
            : __('Si vous avez déjà payé, contactez-nous pour mettre votre compte à jour.');
    }

    private static function greeting(Customer $customer): string
    {
        return __('Bonjour :v1 *:v2*,', ['v1' => $customer->title(), 'v2' => $customer->name]);
    }

    /**
     * La mise en forme commune des messages WhatsApp : un titre, le détail, puis le montant à
     * retenir encadré. Seul le texte passe dans un message pré-rempli : le filet est un caractère
     * ordinaire, sans émoji, car ceux-ci arrivent cassés depuis un ordinateur.
     */
    private static function card(string $title, array $intro, array $details, array $highlight, string $note, array $months = []): string
    {
        $organisation = auth()->user()->organisation;

        return implode("\n", array_filter([
            '*'.mb_strtoupper($title).'*',
            self::RULE,
            '',
            ...$intro,
            '',
            ...array_map(fn (string $line) => '• '.$line, $details),
            ...($months ? ['', '*'.__('Reste à payer par mois :').'*', ...$months] : []),
            '',
            self::RULE,
            ...$highlight,
            self::RULE,
            '',
            '_'.$note.'_',
            '',
            '*'.$organisation->name.'*',
            $organisation->phone ? __('Tél. : :v1', ['v1' => $organisation->phone]) : null,
        ], fn ($line) => $line !== null));
    }

    private static function prefix(): string
    {
        return auth()->user()->organisation->name.' - ';
    }

    private static function finishSms(string $text): string
    {
        $phone = auth()->user()->organisation->phone;

        return Str::ascii($phone ? $text.' '.__('Tél. : :v1', ['v1' => $phone]) : $text);
    }
}
