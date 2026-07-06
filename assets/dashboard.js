/* WC Referral Program — Frontend Dashboard JS */
jQuery( function ( $ ) {

    /* ── Copy referral link ──────────────────────────── */

    $( document ).on( 'click', '.wrp-copy-btn', function () {
        var url  = $( this ).data( 'url' );
        var $btn = $( this );
        var orig = $btn.text();

        navigator.clipboard.writeText( url ).then( function () {
            $btn.text( 'Copied!' );
            setTimeout( function () { $btn.text( orig ); }, 2000 );
        } );
    } );

} );
