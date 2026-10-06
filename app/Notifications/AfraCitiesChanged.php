<?php

namespace App\Notifications;

use App\Services\AfraShippingClient;
use Illuminate\Notifications\Notification;

/** Bell notification: Afra's city list changed (see Afra settings → Villes). */
class AfraCitiesChanged extends Notification
{
    public function __construct(private readonly array $counts)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    /** Shape read by the dashboard bell: title, message, category, icon type and the page to open. */
    public function toArray($notifiable): array
    {
        $c = $this->counts;
        $parts = array_filter([
            $c['added'] ? $c['added'].' nouvelle(s) ville(s)'.($c['linked'] ? ' dont '.$c['linked'].' associée(s) automatiquement' : '') : null,
            $c['renamed'] ? $c['renamed'].' renommée(s)' : null,
            $c['price'] ? $c['price'].' prix modifié(s)' : null,
            $c['removed'] ? $c['removed'].' supprimée(s)' : null,
        ]);

        return [
            'title'    => 'Villes Afra modifiées',
            'message'  => implode(', ', $parts).'.',
            'category' => 'Afra',
            'type'     => 'delivery',
            'link'     => '/dashboard/carrier/'.AfraShippingClient::carrierId().'/afra?tab=villes',
        ];
    }
}
