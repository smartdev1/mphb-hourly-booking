/**
 * MPHB Hourly Booking — Time Slot Picker
 *
 * Corrections des bugs bloquants :
 *   1. Variable $.p inexistante → on passe toujours $picker en paramètre explicite
 *   2. Événement datepicker MPHB non vérifié → 4 signaux de détection (A/B/C/D)
 *   3. Récupération de la date → on lit le champ hidden Y-m-d, pas le visible
 */
( function ( $ ) {
    'use strict';

    const C = window.MPHBHourly || {};

    /* ── Utilitaires ────────────────────────────────────────────────────── */

    const toMin  = hhmm => { const [h, m] = hhmm.split(':'); return +h * 60 + +m; };
    const toHHMM = min  => String( Math.floor( min / 60 ) ).padStart( 2, '0' )
                         + ':' + String( min % 60 ).padStart( 2, '0' );

    const fmtDur = min => {
        const h = Math.floor( min / 60 ), r = min % 60;
        if ( h && r ) return `${h}h ${r}min`;
        if ( h )      return h === 1 ? '1 heure' : `${h} heures`;
        return `${r} min`;
    };

    const overlap = ( a, b, c, d ) => a < d && c < b;

    /* ── État (une instance par wrapper) ────────────────────────────────── */

    function makeState() {
        return {
            open: '00:00', close: '23:59', step: 60,
            minDur: 60, maxDur: 0,
            priceH: 0, currency: '€',
            booked: [], start: '', end: '',
            loading: false, lastDate: '',
        };
    }

    /* ── Construction UI ────────────────────────────────────────────────── */

    function buildUI( $w ) {
        if ( $w.find( '.mphb-h-picker' ).length ) return;
        $w.append( `
<div class="mphb-h-picker" style="display:none">
  <div class="mphb-h-row">
    <div class="mphb-h-col">
      <label class="mphb-h-lbl">${ C.i18n.start }</label>
      <select class="mphb-h-start" name="mphb_hourly_start"><option value="">—</option></select>
    </div>
    <div class="mphb-h-col">
      <label class="mphb-h-lbl">${ C.i18n.end }</label>
      <select class="mphb-h-end" name="mphb_hourly_end" disabled><option value="">—</option></select>
    </div>
  </div>
  <div class="mphb-h-summary" style="display:none">
    <span class="mphb-h-dur"></span><span class="mphb-h-price"></span>
  </div>
  <div class="mphb-h-error" style="display:none"></div>
  <div class="mphb-h-bar"><div class="mphb-h-bar-inner"></div></div>
  <div class="mphb-h-loading" style="display:none">Chargement…</div>
</div>` );
    }

    /* ── Remplissage des selects ─────────────────────────────────────────── */

    function fillStart( $picker, S ) {
        const $sel  = $picker.find( '.mphb-h-start' );
        const openM = toMin( S.open );
        const maxM  = toMin( S.close ) - S.minDur;

        $sel.empty().append( '<option value="">—</option>' );

        for ( let m = openM; m <= maxM; m += S.step ) {
            const t  = toHHMM( m );
            const bk = S.booked.some( b => overlap( m, m + S.step, toMin( b.start ), toMin( b.end ) ) );
            $sel.append(
                $( '<option>' )
                    .val( t )
                    .text( t + ( bk ? ` (${ C.i18n.booked })` : '' ) )
                    .prop( 'disabled', bk )
            );
        }
    }

    function fillEnd( $picker, S, startM ) {
        const $sel    = $picker.find( '.mphb-h-end' );
        const closeM  = toMin( S.close );
        const firstEnd = startM + S.minDur;
        const lastEnd  = S.maxDur ? Math.min( closeM, startM + S.maxDur ) : closeM;

        $sel.empty().append( '<option value="">—</option>' ).prop( 'disabled', false );

        for ( let m = firstEnd; m <= lastEnd; m += S.step ) {
            const blocked = S.booked.some( b => overlap( startM, m, toMin( b.start ), toMin( b.end ) ) );
            $sel.append(
                $( '<option>' ).val( toHHMM( m ) ).text( toHHMM( m ) ).prop( 'disabled', blocked )
            );
            if ( blocked ) break;
        }
    }

    /* ── Timeline ───────────────────────────────────────────────────────── */

    function drawBar( $picker, S ) {
        const $bi   = $picker.find( '.mphb-h-bar-inner' ).empty();
        const openM = toMin( S.open );
        const total = toMin( S.close ) - openM;
        if ( ! total ) return;

        const pct = a     => ( ( a - openM ) / total * 100 ) + '%';
        const wd  = (a,b) => ( ( b - a )     / total * 100 ) + '%';

        S.booked.forEach( b => {
            const bS = toMin( b.start ), bE = toMin( b.end );
            $bi.append(
                $( '<div class="mphb-h-seg mphb-h-seg--bk">' )
                    .css( { left: pct( bS ), width: wd( bS, bE ) } )
                    .attr( 'title', `${ b.start } – ${ b.end }` )
            );
        } );

        if ( S.start && S.end ) {
            const sM = toMin( S.start ), eM = toMin( S.end );
            $bi.append(
                $( '<div class="mphb-h-seg mphb-h-seg--sel">' )
                    .css( { left: pct( sM ), width: wd( sM, eM ) } )
            );
        }
    }

    /* ── Résumé & erreurs ───────────────────────────────────────────────── */

    function showSummary( $picker, S ) {
        if ( ! S.start || ! S.end ) { $picker.find( '.mphb-h-summary' ).hide(); return; }
        const dur = toMin( S.end ) - toMin( S.start );
        $picker.find( '.mphb-h-dur' ).text( C.i18n.duration + ' ' + fmtDur( dur ) );
        $picker.find( '.mphb-h-price' ).text(
            C.i18n.price + ' ' + S.currency + ( S.priceH * dur / 60 ).toFixed( 2 )
        );
        $picker.find( '.mphb-h-summary' ).show();
    }

    function showErr( $picker, msg ) { $picker.find( '.mphb-h-error' ).text( msg ).show(); }
    function clearErr( $picker )     { $picker.find( '.mphb-h-error' ).hide().text( '' ); }

    /* ── AJAX ───────────────────────────────────────────────────────────── */

    function loadSlots( $w, $picker, S, rtId, date ) {
        // Anti-doublon : ne pas recharger si même date
        if ( S.loading || date === S.lastDate ) return;
        S.loading  = true;
        S.lastDate = date;

        $picker.find( '.mphb-h-loading' ).show();
        $picker.find( '.mphb-h-start, .mphb-h-end' ).prop( 'disabled', true );
        $picker.find( '.mphb-h-summary' ).hide();
        clearErr( $picker );

        $.get( C.ajax, {
            action      : 'mphb_hourly_slots',
            nonce       : C.nonce,
            room_type_id: rtId,
            date        : date,
        } )
        .done( r => {
            if ( ! r.success ) { showErr( $picker, r.data ? r.data.msg : 'Erreur.' ); return; }
            const d  = r.data;
            S.open   = d.open;     S.close    = d.close;
            S.step   = +d.step;    S.minDur   = +d.min_duration;
            S.maxDur = +d.max_duration;
            S.priceH = +d.price_per_h;  S.currency = d.currency;
            S.booked = d.booked || [];
            S.start  = '';  S.end = '';

            fillStart( $picker, S );
            $picker.find( '.mphb-h-end' ).prop( 'disabled', true ).html( '<option value="">—</option>' );
            drawBar( $picker, S );
            $picker.show();
        } )
        .fail( () => showErr( $picker, 'Erreur réseau.' ) )
        .always( () => {
            S.loading = false;
            $picker.find( '.mphb-h-loading' ).hide();
            $picker.find( '.mphb-h-start' ).prop( 'disabled', false );
        } );
    }

    /* ── Lecture de la date depuis le formulaire MPHB ───────────────────── */

    /**
     * MPHB transmet la date en format Y-m-d via :
     * - Un input hidden name="mphb_check_in_date" (dans le checkout et la recherche)
     * - Le cookie mphb_check_in_date (fallback)
     *
     * On NE lit PAS l'input visible (class mphb-datepick) car son format
     * dépend des réglages (dd/mm/yyyy, mm/dd/yyyy, etc.)
     */
    function readDate( $w ) {
        // 1. Input hidden dans le formulaire parent
        const $form = $w.closest( 'form' );
        if ( $form.length ) {
            const v = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' ).val()
                   || $form.find( 'input[name="mphb_check_in_date"]' ).val();
            if ( v && /^\d{4}-\d{2}-\d{2}$/.test( v ) ) return v;
        }
        // 2. Cookie MPHB (fallback)
        const cookie = document.cookie.split( '; ' )
            .find( r => r.startsWith( 'mphb_check_in_date=' ) );
        if ( cookie ) {
            const v = decodeURIComponent( cookie.split( '=' )[1] );
            if ( /^\d{4}-\d{2}-\d{2}$/.test( v ) ) return v;
        }
        return null;
    }

    /* ── Événements ─────────────────────────────────────────────────────── */

    function bindEvents( $w, $picker, S, rtId ) {

        // ── Sélecteur heure début
        $picker.on( 'change', '.mphb-h-start', function () {
            S.start = $( this ).val(); S.end = '';
            clearErr( $picker );
            $picker.find( '.mphb-h-summary' ).hide();
            if ( S.start ) {
                fillEnd( $picker, S, toMin( S.start ) );
            } else {
                $picker.find( '.mphb-h-end' ).prop( 'disabled', true ).html( '<option value="">—</option>' );
            }
            drawBar( $picker, S );
        } );

        // ── Sélecteur heure fin
        $picker.on( 'change', '.mphb-h-end', function () {
            S.end = $( this ).val();
            clearErr( $picker );
            if ( S.end ) {
                const dur = toMin( S.end ) - toMin( S.start );
                if ( dur < S.minDur ) {
                    showErr( $picker, C.i18n.err_min.replace( '%s', fmtDur( S.minDur ) ) );
                    S.end = ''; $( this ).val( '' ); return;
                }
                if ( S.maxDur && dur > S.maxDur ) {
                    showErr( $picker, C.i18n.err_max.replace( '%s', fmtDur( S.maxDur ) ) );
                    S.end = ''; $( this ).val( '' ); return;
                }
            }
            showSummary( $picker, S );
            drawBar( $picker, S );
        } );

        /*
         * ── Détection du changement de date du datepicker MPHB
         *
         * MPHB utilise kbwood/datepick. Quand l'utilisateur choisit une date :
         *   1. kbwood met à jour l'input visible (texte formaté)
         *   2. MPHB met à jour le cookie mphb_check_in_date
         *   3. Le hidden input name="mphb_check_in_date" est mis à jour par JS
         *
         * On utilise 4 mécanismes complémentaires pour couvrir tous les cas :
         */

        // Signal A : 'change' sur l'input visible (déclenché par kbwood)
        $w.on( 'change', 'input.mphb-datepick[name="mphb_check_in_date"], input.mphb-datepick', function () {
            // Délai pour laisser MPHB mettre à jour le hidden
            setTimeout( () => {
                const date = readDate( $w );
                if ( date ) loadSlots( $w, $picker, S, rtId, date );
            }, 100 );
        } );

        // Signal B : MutationObserver sur le hidden input (mis à jour par MPHB via JS)
        const $form = $w.closest( 'form' );
        if ( $form.length ) {
            const $hidden = $form.find( 'input[name="mphb_check_in_date"][type="hidden"]' );
            if ( $hidden.length && typeof MutationObserver !== 'undefined' ) {
                const obs = new MutationObserver( mutations => {
                    mutations.forEach( m => {
                        if ( m.attributeName === 'value' ) {
                            const date = $hidden.val();
                            if ( date && /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
                                loadSlots( $w, $picker, S, rtId, date );
                            }
                        }
                    } );
                } );
                obs.observe( $hidden[0], { attributes: true, attributeFilter: ['value'] } );
            }

            // Signal C : event 'change' / 'input' sur le hidden (certains navigateurs)
            $form.on( 'change input', 'input[name="mphb_check_in_date"]', function () {
                const date = this.value;
                if ( date && /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
                    loadSlots( $w, $picker, S, rtId, date );
                }
            } );
        }

        // Signal D : polling léger sur le cookie MPHB (fallback universel)
        // Surveille le cookie toutes les 500ms pendant 30s après focus sur un datepick
        let pollTimer = null;
        $w.on( 'focus click', 'input.mphb-datepick', function () {
            clearInterval( pollTimer );
            let ticks = 0;
            pollTimer = setInterval( () => {
                ticks++;
                const date = readDate( $w );
                if ( date && date !== S.lastDate ) {
                    loadSlots( $w, $picker, S, rtId, date );
                    clearInterval( pollTimer );
                }
                if ( ticks > 60 ) clearInterval( pollTimer ); // Stop après 30s
            }, 500 );
        } );
    }

    /* ── Init ───────────────────────────────────────────────────────────── */

    $( () => {
        $( '[data-mphb-hourly="1"]' ).each( function () {
            const $w   = $( this );
            const rtId = +$w.data( 'mphb-room-type-id' );
            if ( ! rtId ) return;

            const S       = makeState();
            buildUI( $w );
            const $picker = $w.find( '.mphb-h-picker' );
            bindEvents( $w, $picker, S, rtId );

            // Date déjà présente au chargement (retour en arrière, cookie)
            const existingDate = readDate( $w );
            if ( existingDate ) loadSlots( $w, $picker, S, rtId, existingDate );
        } );
    } );

} )( jQuery );

/* Mode recherche : voir mphb-hourly-search.js */
