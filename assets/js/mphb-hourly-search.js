
/* ── Mode Formulaire de Recherche (page single room type) ───────────────
 *
 * Fonctionne sur [data-mphb-hourly-search="1"].
 * Ce div est injecté APRÈS le formulaire natif par PHP.
 * Le JS le déplace DANS le formulaire, avant le bouton submit.
 *
 * Flux :
 *   1. DOMContentLoaded → déplacer le mount point dans le formulaire
 *   2. Attendre qu'une date soit choisie dans le datepicker MPHB
 *   3. Charger les créneaux disponibles via AJAX
 *   4. Afficher sélects début/fin + résumé prix
 *   5. Écrire les valeurs dans les inputs hidden du formulaire
 *   6. Valider avant soumission
 */

( function ( $ ) {
    'use strict';

    $( () => {
        $( '[data-mphb-hourly-search="1"]' ).each( function () {
            const $mount = $( this );
            const rtId   = +$mount.data( 'mphb-room-type-id' );
            if ( ! rtId ) return;

            const formId = $mount.data( 'form-id' ) || ( 'booking-form-' + rtId );
            const $form  = $( '#' + formId );
            if ( ! $form.length ) {
                // Fallback : chercher le formulaire de réservation le plus proche
                console.warn( '[MPHB Hourly] Formulaire introuvable : #' + formId );
                return;
            }

            // ── 1. Déplacer le mount point dans le formulaire ──────────
            const $submitWrapper = $form.find( '.mphb-reserve-btn-wrapper' );
            if ( $submitWrapper.length ) {
                $submitWrapper.before( $mount );
            } else {
                // Fallback : juste avant le dernier enfant du formulaire
                $form.append( $mount );
            }
            $mount.show();

            // ── 2. Construire l'UI du sélecteur ───────────────────────
            const $picker = $mount.find( '.mphb-h-picker-search' );
            buildUI( $picker );

            // ── 3. État interne ────────────────────────────────────────
            const S = {
                open: '00:00', close: '23:59', step: 60,
                minDur: 60, maxDur: 0, priceH: 0,
                booked: [], start: '', end: '',
                loading: false, lastDate: '',
            };

            // Pré-remplir si valeurs précédentes
            const prevStart = $mount.data( 'prev-start' ) || '';
            const prevEnd   = $mount.data( 'prev-end' )   || '';

            // ── 4. Détecter la date choisie ────────────────────────────
            bindDateDetection( $form, $mount, $picker, S, rtId, prevStart, prevEnd );

            // ── 5. Valider avant soumission ────────────────────────────
            $form.on( 'submit.mphb_hourly', function ( e ) {
                if ( ! S.start || ! S.end ) {
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

    // ── Construire l'UI du picker ──────────────────────────────────────
    function buildUI( $picker ) {
        const C = window.MPHBHourly || { i18n: {} };
        $picker.html( `
<div class="mphb-hourly-picker-wrap" style="margin:12px 0 8px;padding:12px;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;">
  <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#333">
    ⏰ ${C.i18n.start || 'Heure'} / ${C.i18n.end || 'Fin'}
  </p>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <div>
      <label style="display:block;font-size:12px;margin-bottom:3px">${C.i18n.start || 'Heure de début'}</label>
      <select class="mphb-h-start" style="height:38px;padding:6px 8px;min-width:110px"><option value="">—</option></select>
    </div>
    <div>
      <label style="display:block;font-size:12px;margin-bottom:3px">${C.i18n.end || 'Heure de fin'}</label>
      <select class="mphb-h-end" style="height:38px;padding:6px 8px;min-width:110px" disabled><option value="">—</option></select>
    </div>
  </div>
  <div class="mphb-h-summary" style="display:none;margin-top:8px;font-size:12px;color:#555">
    <span class="mphb-h-dur"></span>
    <span class="mphb-h-price" style="margin-left:8px;font-weight:600"></span>
  </div>
  <div class="mphb-h-error" style="display:none;color:#c00;margin-top:6px;font-size:12px"></div>
  <div class="mphb-h-loading" style="display:none;color:#666;font-size:12px;margin-top:6px">Chargement…</div>
</div>` );
    }

    // ── Détecter la date dans le formulaire de recherche MPHB ─────────
    function bindDateDetection( $form, $mount, $picker, S, rtId, prevStart, prevEnd ) {

        function readDate() {
            // Input hidden name=mphb_check_in_date (format yyyy-mm-dd)
            const hiddenVal = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' ).val();
            if ( hiddenVal && /^\d{4}-\d{2}-\d{2}$/.test( hiddenVal ) ) return hiddenVal;

            // Input visible (datepick) — la valeur est en format local (dd/mm/yyyy)
            // On la convertit via MPHB qui stocke aussi en cookie/hidden
            const visibleVal = $form.find( 'input.mphb-datepick[name="mphb_check_in_date"]' ).val();
            // Tenter conversion dd/mm/yyyy → yyyy-mm-dd
            if ( visibleVal && /^\d{2}\/\d{2}\/\d{4}$/.test( visibleVal ) ) {
                const p = visibleVal.split( '/' );
                return p[2] + '-' + p[1] + '-' + p[0];
            }
            // Cookie MPHB
            const cookie = document.cookie.split( '; ' )
                .find( r => r.startsWith( 'mphb_check_in_date=' ) );
            if ( cookie ) {
                const cv = decodeURIComponent( cookie.split( '=' )[1] );
                if ( /^\d{4}-\d{2}-\d{2}$/.test( cv ) ) return cv;
            }
            return null;
        }

        function tryLoad() {
            const date = readDate();
            if ( date && date !== S.lastDate ) {
                loadSlots( $mount, $picker, S, rtId, date, prevStart, prevEnd );
            }
        }

        // Signal A : changement sur l'input visible du datepicker
        $form.on( 'change.mphb_hourly', 'input.mphb-datepick', () => setTimeout( tryLoad, 200 ) );

        // Signal B : MutationObserver sur l'input hidden check_in_date
        const $hiddenCI = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' );
        if ( $hiddenCI.length && typeof MutationObserver !== 'undefined' ) {
            const obs = new MutationObserver( tryLoad );
            obs.observe( $hiddenCI[0], { attributes: true, attributeFilter: ['value'] } );
        }

        // Signal C : event input/change sur tout input check_in_date
        $form.on( 'change.mphb_hourly input.mphb_hourly', 'input[name="mphb_check_in_date"]', tryLoad );

        // Signal D : polling après focus sur le datepicker (le plus fiable)
        let poll = null;
        $form.on( 'focus.mphb_hourly click.mphb_hourly', 'input.mphb-datepick', () => {
            clearInterval( poll );
            let ticks = 0;
            poll = setInterval( () => {
                ticks++;
                tryLoad();
                if ( S.lastDate || ticks > 120 ) clearInterval( poll );
            }, 400 );
        } );

        // Tentative initiale (date pré-remplie ou cookie)
        setTimeout( tryLoad, 300 );
    }

    // ── Charger les créneaux via AJAX ──────────────────────────────────
    function loadSlots( $mount, $picker, S, rtId, date, prevStart, prevEnd ) {
        if ( S.loading ) return;
        S.loading  = true;
        S.lastDate = date;

        const C = window.MPHBHourly || { ajax: '', nonce: '', i18n: {} };
        const toMin  = hhmm => { const [h, m] = hhmm.split(':'); return +h * 60 + +m; };
        const toHHMM = min  => String( ~~( min / 60 ) ).padStart( 2, '0' ) + ':' + String( min % 60 ).padStart( 2, '0' );
        const overlaps = ( a, b, c, d ) => a < d && c < b;

        $picker.find( '.mphb-h-loading' ).show();
        $picker.find( '.mphb-h-start, .mphb-h-end' ).prop( 'disabled', true );
        $picker.find( '.mphb-h-summary, .mphb-h-error' ).hide();

        $.get( C.ajax, {
            action: 'mphb_hourly_slots',
            nonce:  C.nonce,
            room_type_id: rtId,
            date: date,
        } )
        .done( r => {
            if ( ! r.success ) {
                $picker.find( '.mphb-h-error' ).text( ( r.data && r.data.msg ) || 'Erreur.' ).show();
                return;
            }
            const d = r.data;
            S.open   = d.open;  S.close = d.close;
            S.step   = +d.step; S.minDur = +d.min_duration; S.maxDur = +d.max_duration;
            S.priceH = +d.price_per_h; S.booked = d.booked || [];
            S.start  = ''; S.end = '';

            // Remplir le select "début"
            const $selS = $picker.find( '.mphb-h-start' ).empty().append( '<option value="">—</option>' );
            const openM = toMin( S.open );
            const maxM  = toMin( S.close ) - S.minDur;
            for ( let m = openM; m <= maxM; m += S.step ) {
                const t  = toHHMM( m );
                const bk = S.booked.some( b => overlaps( m, m + S.step, toMin( b.start ), toMin( b.end ) ) );
                $selS.append( $( '<option>' ).val( t ).text( t + ( bk ? ' (' + ( C.i18n.booked || 'Réservé' ) + ')' : '' ) ).prop( 'disabled', bk ) );
            }
            $picker.find( '.mphb-h-end' ).prop( 'disabled', true ).html( '<option value="">—</option>' );

            // Lier les événements de sélection
            bindPickerEvents( $mount, $picker, S, toMin, toHHMM, overlaps, C );

            // Pré-sélectionner si valeurs précédentes
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

    // ── Événements des sélects début/fin ──────────────────────────────
    function bindPickerEvents( $mount, $picker, S, toMin, toHHMM, overlaps, C ) {
        // Éviter les doublons
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

            // Remplir fin
            const startM  = toMin( S.start );
            const closeM  = toMin( S.close );
            const firstEnd = startM + S.minDur;
            const lastEnd  = S.maxDur ? Math.min( closeM, startM + S.maxDur ) : closeM;
            const $selE = $picker.find( '.mphb-h-end' )
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

            const dur = toMin( S.end ) - toMin( S.start );
            const h = ~~( dur / 60 ), r = dur % 60;
            const durStr = h && r ? h + 'h ' + r + 'min' : ( h ? ( h === 1 ? '1h' : h + 'h' ) : r + 'min' );
            $picker.find( '.mphb-h-dur' ).text( ( C.i18n.duration || 'Durée :' ) + ' ' + durStr );
            $picker.find( '.mphb-h-price' ).text(
                ( C.i18n.price || 'Prix :' ) + ' ' + ( C.currency || '€' ) + ( S.priceH * dur / 60 ).toFixed( 2 )
            );
            $picker.find( '.mphb-h-summary' ).show();
            $picker.find( '.mphb-h-error' ).hide();

            // Synchroniser check_out_date = check_in_date (même jour)
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
