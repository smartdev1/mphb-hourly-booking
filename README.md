# MPHB Hourly Booking — v1.2.0

Extension de MotoPress Hotel Booking (MPHB) pour les réservations à l'heure.

---

## Installation

1. Uploader et activer le plugin.
2. Appliquer les **2 patches obligatoires** dans MPHB (voir ci-dessous).
3. Configurer vos room types en mode horaire (admin → Accommodation Types → éditer).
4. Placer le wrapper autour du shortcode checkout MPHB.

---

## Patches obligatoires dans MPHB

### Patch 1 — room-persistence.php

Fichier : {motopress-hotel-booking}/includes/persistences/room-persistence.php

Chercher (fin de findLockedRooms()) :
    $roomIds = array_map( 'absint', $roomIds );

Ajouter juste après :
    $roomIds = apply_filters( 'mphb_found_locked_rooms', $roomIds, $atts );

### Patch 2 — step-booking.php

Voir patches/step-booking.patch pour les instructions détaillées.
3 changements dans parseBookingData() pour bypasser les validations
rate_id et booking rules pour les room types horaires.

---

## Intégration front-end

    <div data-mphb-hourly="1" data-mphb-room-type-id="42">
      [mphb_checkout]
    </div>

---

## Balises emails

    %hourly_start%    → Heure de début (ex: 14:00)
    %hourly_end%      → Heure de fin (ex: 16:30)
    %hourly_duration% → Durée lisible (ex: 2h 30min)
    %hourly_slot%     → Créneau complet (ex: 14:00 – 16:30 · 2h 30min)

---

## Métas Room Type

    _mphb_hourly_enabled      bool
    _mphb_hourly_price        float (€/h)
    _mphb_hourly_min_duration int (minutes)
    _mphb_hourly_max_duration int (minutes, 0=illimité)
    _mphb_hourly_step         int (minutes)
    _mphb_hourly_open         string HH:MM
    _mphb_hourly_close        string HH:MM

## Métas Booking

    _mphb_hourly_start    string HH:MM
    _mphb_hourly_end      string HH:MM
    _mphb_hourly_duration int (minutes)
