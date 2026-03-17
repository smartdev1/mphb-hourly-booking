/* ── MPHB Hourly Search — Toggle horaire / journalier ──────────────────
 *
 * Fonctionne sur [data-mphb-hourly-search="1"].
 * Ajoute un toggle pill en haut du formulaire MPHB pour basculer entre :
 *   - Mode journalier  : formulaire MPHB standard (2 dates)
 *   - Mode horaire     : 1 seule date + picker de créneaux horaires
 *
 * Le choix est mémorisé via cookie "mphb_booking_mode" (7 jours).
 */

( function ( $ ) {
    'use strict';

    /* ── Cookie helpers ────────────────────────────────────────────── */

    const COOKIE_NAME = 'mphb_booking_mode';
    const COOKIE_DAYS = 7;

    function getCookie( name ) {
        const match = document.cookie.split( '; ' ).find( r => r.startsWith( name + '=' ) );
        return match ? decodeURIComponent( match.split( '=' )[1] ) : null;
    }

    function setCookie( name, value, days ) {
        const expires = new Date( Date.now() + days * 864e5 ).toUTCString();
        document.cookie = name + '=' + encodeURIComponent( value )
            + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    /* ── Init principale ───────────────────────────────────────────── */

    $( () => {
        $( '[data-mphb-hourly-search="1"]' ).each( function () {
            const $mount = $( this );
            const rtId   = +$mount.data( 'mphb-room-type-id' );
            if ( ! rtId ) return;

            const formId = $mount.data( 'form-id' ) || ( 'booking-form-' + rtId );
            const $form  = $( '#' + formId );
            if ( ! $form.length ) {
                console.warn( '[MPHB Hourly] Formulaire introuvable : #' + formId );
                return;
            }

            // ── 1. Déplacer le mount point en tête du formulaire ───────
            // On insère le toggle juste avant le premier champ (date d'arrivée)
            const $firstField = $form.find( '.mphb-check-in-date-wrapper' );
            if ( $firstField.length ) {
                $firstField.before( $mount );
            } else {
                $form.prepend( $mount );
            }
            $mount.show();

            // ── 2. Construire le toggle pill ───────────────────────────
            buildToggle( $mount );

            // ── 3. Construire le picker horaire ────────────────────────
            const $picker = $mount.find( '.mphb-h-picker-search' );
            buildPickerUI( $picker );

            // ── 4. État interne ────────────────────────────────────────
            const S = {
                open: '00:00', close: '23:59', step: 60,
                minDur: 60, maxDur: 0, priceH: 0,
                booked: [], start: '', end: '',
                loading: false, lastDate: '',
            };

            const prevStart = $mount.data( 'prev-start' ) || '';
            const prevEnd   = $mount.data( 'prev-end' )   || '';

            // ── 5. Déterminer le mode initial (cookie ou défaut) ───────
            // Si des valeurs horaires sont pré-remplies (retour de page), forcer horaire
            const savedMode = prevStart ? 'hourly' : ( getCookie( COOKIE_NAME ) || 'daily' );

            // ── 6. Appliquer le mode initial ───────────────────────────
            applyMode( savedMode, $form, $mount, $picker, S, rtId, prevStart, prevEnd );

            // ── 7. Écouter les clics sur le toggle ─────────────────────
            $mount.on( 'click.mphb_toggle', '.mphb-mode-btn', function () {
                const newMode = $( this ).data( 'mode' );
                if ( $( this ).hasClass( 'is-active' ) ) return;
                setCookie( COOKIE_NAME, newMode, COOKIE_DAYS );
                applyMode( newMode, $form, $mount, $picker, S, rtId, '', '' );
            } );

            // ── 8. Validation avant soumission ─────────────────────────
            $form.on( 'submit.mphb_hourly', function ( e ) {
                const mode = $mount.find( '.mphb-mode-btn.is-active' ).data( 'mode' );
                if ( mode === 'hourly' && ( ! S.start || ! S.end ) ) {
                    e.preventDefault();
                    $picker.find( '.mphb-h-error' )
                        .text( ( window.MPHBHourly && MPHBHourly.i18n.slot_required )
                            || 'Veuillez sélectionner un créneau horaire.' )
                        .show();
                    $mount[0].scrollIntoView( { behavior: 'smooth', block: 'center' } );
                }
            } );
        } );
    } );

    /* ── Appliquer un mode (hourly | daily) ────────────────────────── */

    function applyMode( mode, $form, $mount, $picker, S, rtId, prevStart, prevEnd ) {
        const $btnHourly = $mount.find( '.mphb-mode-btn[data-mode="hourly"]' );
        const $btnDaily  = $mount.find( '.mphb-mode-btn[data-mode="daily"]' );
        const $checkOut  = $form.find( '.mphb-check-out-date-wrapper' );
        const $pickerWrap = $mount.find( '.mphb-h-picker-search' );

        if ( mode === 'hourly' ) {
            $btnHourly.addClass( 'is-active' );
            $btnDaily.removeClass( 'is-active' );

            // Masquer le champ "date de départ" et désactiver required
            // (sinon le navigateur bloque la soumission sur un champ invisible)
            $checkOut.hide();
            $checkOut.find( 'input[required]' )
                .prop( 'required', false )
                .attr( 'data-mphb-was-required', '1' );

            // Vider les inputs horaires cachés si on repart de zéro
            if ( ! prevStart ) {
                $mount.find( 'input.mphb-hourly-start-value' ).val( '' );
                $mount.find( 'input.mphb-hourly-end-value' ).val( '' );
                S.start = ''; S.end = ''; S.lastDate = '';
            }

            // Afficher le picker
            $pickerWrap.show();

            // Lancer la détection de date si pas déjà fait
            bindDateDetection( $form, $mount, $picker, S, rtId, prevStart, prevEnd );

        } else {
            // Mode journalier
            $btnDaily.addClass( 'is-active' );
            $btnHourly.removeClass( 'is-active' );

            // Ré-afficher le champ "date de départ" et restaurer required
            $checkOut.show();
            $checkOut.find( 'input[data-mphb-was-required]' )
                .prop( 'required', true )
                .removeAttr( 'data-mphb-was-required' );

            // Masquer le picker et vider les valeurs horaires
            $pickerWrap.hide();
            $mount.find( 'input.mphb-hourly-start-value' ).val( '' );
            $mount.find( 'input.mphb-hourly-end-value' ).val( '' );
            S.start = ''; S.end = '';

            // Débinder la détection de date pour éviter les rechargements inutiles
            $form.off( 'change.mphb_hourly_date input.mphb_hourly_date focus.mphb_hourly_date click.mphb_hourly_date' );
        }
    }

    /* ── Construire le toggle pill ─────────────────────────────────── */

    function buildToggle( $mount ) {
        const C = window.MPHBHourly || { i18n: {} };
        const labelDaily  = C.i18n.mode_daily  || 'À la journée';
        const labelHourly = C.i18n.mode_hourly || 'À l\'heure';

        $mount.prepend(
            '<div class="mphb-mode-toggle">'
          + '<button type="button" class="mphb-mode-btn" data-mode="daily">'  + labelDaily  + '</button>'
          + '<button type="button" class="mphb-mode-btn" data-mode="hourly">' + labelHourly + '</button>'
          + '</div>'
        );
    }

    /* ── Construire l'UI du picker horaire ─────────────────────────── */

    function buildPickerUI( $picker ) {
        const C = window.MPHBHourly || { i18n: {} };
        $picker.html(
            '<div class="mphb-hourly-picker-wrap">'
          + '<div class="mphb-h-fields">'
          + '<div class="mphb-h-field">'
          + '<label>' + ( C.i18n.start || 'Heure de début' ) + '</label>'
          + '<select class="mphb-h-start"><option value="">—</option></select>'
          + '</div>'
          + '<div class="mphb-h-field">'
          + '<label>' + ( C.i18n.end || 'Heure de fin' ) + '</label>'
          + '<select class="mphb-h-end" disabled><option value="">—</option></select>'
          + '</div>'
          + '</div>'
          + '<div class="mphb-h-summary" style="display:none">'
          + '<span class="mphb-h-dur"></span>'
          + '<span class="mphb-h-price"></span>'
          + '</div>'
          + '<div class="mphb-h-error" style="display:none"></div>'
          + '<div class="mphb-h-loading" style="display:none">Chargement…</div>'
          + '</div>'
        );
    }

    /* ── Détection de la date sélectionnée dans le datepicker MPHB ── */

    // Guard pour éviter de binder plusieurs fois sur le même formulaire
    const _boundForms = new WeakSet();

    function bindDateDetection( $form, $mount, $picker, S, rtId, prevStart, prevEnd ) {

        function readDate() {
            const hiddenVal = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' ).val();
            if ( hiddenVal && /^\d{4}-\d{2}-\d{2}$/.test( hiddenVal ) ) return hiddenVal;

            const visibleVal = $form.find( 'input.mphb-datepick[name="mphb_check_in_date"]' ).val();
            if ( visibleVal && /^\d{2}\/\d{2}\/\d{4}$/.test( visibleVal ) ) {
                const p = visibleVal.split( '/' );
                return p[2] + '-' + p[1] + '-' + p[0];
            }
            const cookie = document.cookie.split( '; ' )
                .find( r => r.startsWith( 'mphb_check_in_date=' ) );
            if ( cookie ) {
                const cv = decodeURIComponent( cookie.split( '=' )[1] );
                if ( /^\d{4}-\d{2}-\d{2}$/.test( cv ) ) return cv;
            }
            return null;
        }

        function tryLoad() {
            // Ne rien faire si on est repassé en mode journalier
            if ( $mount.find( '.mphb-mode-btn[data-mode="hourly"]' ).hasClass( 'is-active' ) === false ) return;
            const date = readDate();
            if ( date && date !== S.lastDate ) {
                loadSlots( $mount, $picker, S, rtId, date, prevStart, prevEnd );
            }
        }

        // Ne binder qu'une seule fois par formulaire
        if ( _boundForms.has( $form[0] ) ) {
            // Formulaire déjà bindé : juste relancer une tentative de chargement
            setTimeout( tryLoad, 300 );
            return;
        }
        _boundForms.add( $form[0] );

        // Signal A
        $form.on( 'change.mphb_hourly_date', 'input.mphb-datepick', () => setTimeout( tryLoad, 200 ) );

        // Signal B : MutationObserver sur l'input hidden
        const $hiddenCI = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' );
        if ( $hiddenCI.length && typeof MutationObserver !== 'undefined' ) {
            const obs = new MutationObserver( tryLoad );
            obs.observe( $hiddenCI[0], { attributes: true, attributeFilter: ['value'] } );
        }

        // Signal C
        $form.on( 'change.mphb_hourly_date input.mphb_hourly_date', 'input[name="mphb_check_in_date"]', tryLoad );

        // Signal D : polling après focus
        let poll = null;
        $form.on( 'focus.mphb_hourly_date click.mphb_hourly_date', 'input.mphb-datepick', () => {
            clearInterval( poll );
            let ticks = 0;
            poll = setInterval( () => {
                ticks++;
                tryLoad();
                if ( S.lastDate || ticks > 120 ) clearInterval( poll );
            }, 400 );
        } );

        // Tentative initiale
        setTimeout( tryLoad, 300 );
    }

    /* ── Charger les créneaux via AJAX ─────────────────────────────── */

    function loadSlots( $mount, $picker, S, rtId, date, prevStart, prevEnd ) {
        if ( S.loading ) return;
        S.loading  = true;
        S.lastDate = date;

        const C        = window.MPHBHourly || { ajax: '', nonce: '', i18n: {} };
        const toMin    = hhmm => { const [h, m] = hhmm.split(':'); return +h * 60 + +m; };
        const toHHMM   = min  => String( ~~( min / 60 ) ).padStart( 2, '0' ) + ':' + String( min % 60 ).padStart( 2, '0' );
        const overlaps = ( a, b, c, d ) => a < d && c < b;

        $picker.find( '.mphb-h-loading' ).show();
        $picker.find( '.mphb-h-start, .mphb-h-end' ).prop( 'disabled', true );
        $picker.find( '.mphb-h-summary, .mphb-h-error' ).hide();

        $.get( C.ajax, {
            action       : 'mphb_hourly_slots',
            nonce        : C.nonce,
            room_type_id : rtId,
            date         : date,
        } )
        .done( r => {
            if ( ! r.success ) {
                $picker.find( '.mphb-h-error' ).text( ( r.data && r.data.msg ) || 'Erreur.' ).show();
                return;
            }
            const d  = r.data;
            S.open   = d.open;  S.close  = d.close;
            S.step   = +d.step; S.minDur = +d.min_duration; S.maxDur = +d.max_duration;
            S.priceH = +d.price_per_h; S.booked = d.booked || [];
            S.start  = ''; S.end = '';

            const $selS = $picker.find( '.mphb-h-start' ).empty().append( '<option value="">—</option>' );
            const openM = toMin( S.open );
            const maxM  = toMin( S.close ) - S.minDur;
            for ( let m = openM; m <= maxM; m += S.step ) {
                const t  = toHHMM( m );
                const bk = S.booked.some( b => overlaps( m, m + S.step, toMin( b.start ), toMin( b.end ) ) );
                $selS.append( $( '<option>' ).val( t ).text( t + ( bk ? ' (' + ( C.i18n.booked || 'Réservé' ) + ')' : '' ) ).prop( 'disabled', bk ) );
            }
            $picker.find( '.mphb-h-end' ).prop( 'disabled', true ).html( '<option value="">—</option>' );

            bindPickerEvents( $mount, $picker, S, toMin, toHHMM, overlaps, C );

            if ( prevStart ) {
                $picker.find( '.mphb-h-start' ).val( prevStart ).trigger( 'change' );
                if ( prevEnd ) setTimeout( () => $picker.find( '.mphb-h-end' ).val( prevEnd ).trigger( 'change' ), 50 );
            }
        } )
        .fail( () => $picker.find( '.mphb-h-error' ).text( 'Erreur réseau.' ).show() )
        .always( () => {
            S.loading = false;
            $picker.find( '.mphb-h-loading' ).hide();
            $picker.find( '.mphb-h-start' ).prop( 'disabled', false );
        } );
    }

    /* ── Événements des sélects début / fin ────────────────────────── */

    function bindPickerEvents( $mount, $picker, S, toMin, toHHMM, overlaps, C ) {
        $picker.off( 'change.mphb_h_pick' );

        $picker.on( 'change.mphb_h_pick', '.mphb-h-start', function () {
            S.start = $( this ).val();
            S.end   = '';
            $picker.find( '.mphb-h-summary, .mphb-h-error' ).hide();
            $mount.find( 'input.mphb-hourly-end-value' ).val( '' );

            if ( ! S.start ) {
                $picker.find( '.mphb-h-end' ).prop( 'disabled', true ).html( '<option value="">—</option>' );
                $mount.find( 'input.mphb-hourly-start-value' ).val( '' );
                return;
            }

            $mount.find( 'input.mphb-hourly-start-value' ).val( S.start );

            const startM   = toMin( S.start );
            const closeM   = toMin( S.close );
            const firstEnd = startM + S.minDur;
            const lastEnd  = S.maxDur ? Math.min( closeM, startM + S.maxDur ) : closeM;
            const $selE    = $picker.find( '.mphb-h-end' )
                .prop( 'disabled', false )
                .html( '<option value="">—</option>' );

            for ( let m = firstEnd; m <= lastEnd; m += S.step ) {
                const blocked = S.booked.some( b => overlaps( startM, m, toMin( b.start ), toMin( b.end ) ) );
                $selE.append( $( '<option>' ).val( toHHMM( m ) ).text( toHHMM( m ) ).prop( 'disabled', blocked ) );
                if ( blocked ) break;
            }
        } );

        $picker.on( 'change.mphb_h_pick', '.mphb-h-end', function () {
            S.end = $( this ).val();
            $mount.find( 'input.mphb-hourly-end-value' ).val( S.end );

            if ( ! S.start || ! S.end ) { $picker.find( '.mphb-h-summary' ).hide(); return; }

            const dur    = toMin( S.end ) - toMin( S.start );
            const h      = ~~( dur / 60 ), r = dur % 60;
            const durStr = h && r ? h + 'h ' + r + 'min' : ( h ? ( h === 1 ? '1h' : h + 'h' ) : r + 'min' );
            $picker.find( '.mphb-h-dur' ).text( ( C.i18n.duration || 'Durée :' ) + ' ' + durStr );
            $picker.find( '.mphb-h-price' ).text(
                ( C.i18n.price || 'Prix :' ) + ' ' + ( C.currency || '€' ) + ( S.priceH * dur / 60 ).toFixed( 2 )
            );
            $picker.find( '.mphb-h-summary' ).show();
            $picker.find( '.mphb-h-error' ).hide();

            // Synchroniser check_out_date = check_in_date (même jour pour mode horaire)
            const $ciHidden = $( '#' + $mount.data( 'form-id' ) ).find( 'input[name="mphb_check_in_date"][type="hidden"]' );
            const ciVal = $ciHidden.val();
            if ( ciVal ) {
                let $coHidden = $( '#' + $mount.data( 'form-id' ) ).find( 'input[name="mphb_check_out_date"][type="hidden"]' );
                if ( ! $coHidden.length ) {
                    $coHidden = $( '<input type="hidden" name="mphb_check_out_date">' )
                        .appendTo( '#' + $mount.data( 'form-id' ) );
                }
                $coHidden.val( ciVal );
            }
        } );
    }

} )( jQuery );