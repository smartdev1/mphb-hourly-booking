from pathlib import Path

path = Path(r'c:\laragon\www\booking\wp-content\plugins\mphb-hourly-booking\includes\class-mphb-hourly-confirmation.php')
text = path.read_text(encoding='utf-8')
start = '        echo "<script>\n'
end = '</script>";'
si = text.find(start)
if si == -1:
    raise SystemExit('START marker not found')
j = text.find(end, si)
if j == -1:
    raise SystemExit('END marker not found')
j_end = j + len(end)

replacement = r'''        echo "<script>
(function(){
    function rewriteCheckoutDetails() {
        /* ── 1. Récupérer le bloc .mphb-booking-details ── */
        var section = document.getElementById('mphb-booking-details');
        if ( ! section ) return;

        /* ── 2. Masquer les lignes Arrivée / Départ de MPHB ── */
        var paras = section.querySelectorAll('p.mphb-check-in-date, p.mphb-check-out-date');
        paras.forEach(function(p){ p.style.display = 'none'; });

        /* ── 3. Injecter notre bloc horaire si pas déjà fait ── */
        if ( section.querySelector('.mphb-hourly-checkout-slot') ) return;

        /* Lire la date depuis l'input hidden */
        var dateInput = document.querySelector('input[name="mphb_check_in_date"]');
        var dateVal = dateInput ? dateInput.value : '';
        /* Formater en dd/mm/yyyy si format yyyy-mm-dd */
        var dateDisplay = dateVal;
        if ( /^\\d{4}-\\d{2}-\\d{2}$/.test(dateVal) ) {
            var p = dateVal.split('-');
            dateDisplay = p[2] + '/' + p[1] + '/' + p[0];
        }

        var html = '<div class="mphb-hourly-checkout-slot" style="margin:12px 0;padding:12px 16px;background:#f0f7ff;border-left:4px solid #060097;border-radius:4px;">'
            + '<p style="margin:4px 0"><strong>{$lbl_date}</strong> ' + dateDisplay + '</p>'
            + '<p style="margin:4px 0"><strong>{$lbl_arr}</strong> {$start_js}</p>'
            + '<p style="margin:4px 0"><strong>{$lbl_dep}</strong> {$end_js}</p>'
            + '<p style="margin:4px 0"><strong>{$lbl_dur}</strong> {$dur_js}</p>'
            + '</div>';

        /* Insérer après le titre */
        var title = section.querySelector('.mphb-booking-details-title');
        if ( title ) {
            title.insertAdjacentHTML('afterend', html);
        } else {
            section.insertAdjacentHTML('afterbegin', html);
        }

        /* ── 4. Corriger les prix €0 dans le tableau ── */
        if ( '{$price_fmt}' !== '—' ) {
            var priceHTML = '<span class="mphb-price"><span class="mphb-currency">{$currency_js}</span>{$price_js}</span>';

            /* Tableau de répartition — toutes les cellules .mphb-table-price-column */
            document.querySelectorAll('.mphb-price-breakdown .mphb-table-price-column .mphb-price').forEach(function(el){
                el.outerHTML = priceHTML;
            });

            /* Total en bas du tableau */
            document.querySelectorAll('.mphb-price-breakdown-total .mphb-table-price-column .mphb-price').forEach(function(el){
                el.outerHTML = priceHTML;
            });

            /* Prix total affiché sous le formulaire */
            var totalField = document.querySelector('.mphb-total-price-field .mphb-price');
            if ( totalField ) totalField.outerHTML = priceHTML;

            /* Libellé "par nuit" → "pour ce créneau" */
            document.querySelectorAll('.mphb-price-period').forEach(function(el){
                el.textContent = '{$lbl_period}';
            });

            /* Libellé "X nuits" → durée */
            document.querySelectorAll('.mphb-price-breakdown-nights td:last-child').forEach(function(el){
                el.textContent = '{$dur_js}';
            });
            document.querySelectorAll('.mphb-price-breakdown-nights td:first-child').forEach(function(el){
                el.textContent = '{$lbl_slot}';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        rewriteCheckoutDetails();
        /* Ré-appliquer après les mises à jour AJAX de MPHB (calcul de prix) */
        setTimeout(rewriteCheckoutDetails, 500);
        setTimeout(rewriteCheckoutDetails, 1500);
    });
})();
</script>";'''

text = text[:si] + replacement + text[j_end:]
path.write_text(text, encoding='utf-8')
print('Replaced script block')
