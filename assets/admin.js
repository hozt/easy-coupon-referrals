/* WC Referral Program — Admin JS */
jQuery( function ( $ ) {

    var modal, isNew, editReferrerId;
    var couponCodes = []; // Array of codes currently in the modal tag input

    /* ── Init modal ──────────────────────────────────── */

    modal = $( '#wrp-modal' ).dialog( {
        autoOpen : false,
        modal    : true,
        width    : 580,
        resizable: false,
        draggable: true,
        buttons  : {
            'Save Referrer': saveReferrer,
            'Cancel'       : function () { modal.dialog( 'close' ); }
        },
        close: function () {
            resetForm();
        }
    } );

    /* ── Add new ─────────────────────────────────────── */

    $( document ).on( 'click', '.wrp-btn-add', function () {
        isNew          = true;
        editReferrerId = null;
        resetForm();
        // Pre-fill from global defaults
        if ( WRP.default_commission_rate ) {
            $( '#wrp-commission-rate' ).val( WRP.default_commission_rate );
        }
        if ( WRP.default_coupon_type ) {
            $( '#wrp-coupon-discount-type' ).val( WRP.default_coupon_type ).trigger( 'change' );
        }
        if ( WRP.default_coupon_amount ) {
            $( '#wrp-coupon-amount' ).val( WRP.default_coupon_amount );
        }
        modal.dialog( 'option', 'title', 'Add Referrer' );
        modal.dialog( 'open' );
    } );

    /* ── Edit ────────────────────────────────────────── */

    $( document ).on( 'click', '.wrp-btn-edit', function () {
        var id = $( this ).data( 'id' );
        isNew          = false;
        editReferrerId = id;

        $.post( WRP.ajax_url, { action: 'wrp_get_referrer', nonce: WRP.nonce, referrer_id: id }, function ( res ) {
            if ( ! res.success ) { alert( res.data ); return; }
            var r = res.data;

            $( '#wrp-referrer-id' ).val( r.id );
            $( '#wrp-user-search' ).val( r.user_label );
            $( '#wrp-user-id' ).val( r.user_id );
            $( '#wrp-commission-rate' ).val( r.commission_rate );
            $( '#wrp-notes' ).val( r.notes );

            // Restore coupon tags
            couponCodes = r.coupon_codes || [];
            renderTags();

            modal.dialog( 'option', 'title', 'Edit Referrer — ' + ( r.user_label || '' ) );
            modal.dialog( 'open' );
        } );
    } );

    /* ── Delete ──────────────────────────────────────── */

    $( document ).on( 'click', '.wrp-btn-delete', function () {
        var id   = $( this ).data( 'id' );
        var $row = $( this ).closest( 'tr' );
        var name = $row.find( 'td:first strong' ).text();

        if ( ! confirm( 'Delete referrer "' + name + '"? This will also delete all their commission records. This cannot be undone.' ) ) return;

        $.post( WRP.ajax_url, { action: 'wrp_delete_referrer', nonce: WRP.nonce, referrer_id: id }, function ( res ) {
            if ( res.success ) {
                $row.fadeOut( 300, function () {
                    $( this ).remove();
                    checkEmpty();
                } );
            }
        } );
    } );

    /* ── Copy link ───────────────────────────────────── */

    $( document ).on( 'click', '.wrp-btn-copy-link', function () {
        copyToClipboard( $( this ).data( 'url' ), $( this ), '\uD83D\uDD17', '\u2713' );
    } );

    /* ── Save referrer ───────────────────────────────── */

    function saveReferrer() {
        var $err  = $( '#wrp-form-error' );
        var userId = $( '#wrp-user-id' ).val();
        var rate  = $( '#wrp-commission-rate' ).val();

        $err.text( '' );

        if ( ! userId ) { $err.text( 'Please select a user.' ); return; }
        if ( rate === '' || isNaN( parseFloat( rate ) ) ) { $err.text( 'Please enter a commission rate (0–100).' ); return; }

        var data = {
            action                : 'wrp_save_referrer',
            nonce                 : WRP.nonce,
            referrer_id           : isNew ? '' : editReferrerId,
            user_id               : userId,
            commission_rate       : rate,
            notes                 : $( '#wrp-notes' ).val().trim(),
            coupon_codes_json     : JSON.stringify( couponCodes ),
            coupon_discount_type  : $( '#wrp-coupon-discount-type' ).val(),
            coupon_amount         : $( '#wrp-coupon-amount' ).val(),
        };

        var $saveBtn = $( '.ui-dialog-buttonpane .ui-button' ).first();
        $saveBtn.prop( 'disabled', true ).text( 'Saving…' );

        $.post( WRP.ajax_url, data, function ( res ) {
            $saveBtn.prop( 'disabled', false ).text( 'Save Referrer' );

            if ( ! res.success ) {
                $err.text( res.data );
                return;
            }

            modal.dialog( 'close' );

            var $tbody = $( '#wrp-referrers-table tbody' );
            var $table = $( '#wrp-referrers-table' );

            if ( res.data.is_new ) {
                if ( $table.length === 0 ) {
                    location.reload();
                    return;
                }
                $( '#wrp-empty-placeholder' ).hide();
                $( '.wrp-empty-state' ).hide();
                $tbody.append( res.data.html );
            } else {
                $( '.wrp-referrer-row[data-id="' + editReferrerId + '"]' ).replaceWith( res.data.html );
            }

            showNotice( 'Referrer saved successfully.', 'success' );
        } );
    }

    /* ── User search autocomplete ────────────────────── */

    var userTimer;
    $( document ).on( 'input', '#wrp-user-search', function () {
        var val = $( this ).val().trim();
        $( '#wrp-user-id' ).val( '' );
        clearTimeout( userTimer );
        if ( val.length < 2 ) { $( '#wrp-user-suggestions' ).empty().hide(); return; }
        userTimer = setTimeout( function () {
            $.post( WRP.ajax_url, { action: 'wrp_search_users', nonce: WRP.nonce, search: val }, function ( res ) {
                renderSuggestions( '#wrp-user-suggestions', res.data || [], function ( item ) {
                    $( '#wrp-user-search' ).val( item.label );
                    $( '#wrp-user-id' ).val( item.id );
                    $( '#wrp-user-suggestions' ).empty().hide();
                } );
            } );
        }, 250 );
    } );

    /* ── Coupon tag search ───────────────────────────── */

    function commitCouponInput() {
        var val = $( '#wrp-coupon-search' ).val().trim();
        if ( ! val ) return;
        addCouponCode( val );
        $( '#wrp-coupon-search' ).val( '' );
        $( '#wrp-coupon-suggestions' ).empty().hide();
    }

    // Enter key in coupon field commits the typed value directly
    $( document ).on( 'keydown', '#wrp-coupon-search', function ( e ) {
        if ( e.key === 'Enter' || e.keyCode === 13 ) {
            e.preventDefault();
            commitCouponInput();
        }
    } );

    // Add button
    $( document ).on( 'click', '.wrp-btn-add-coupon', function () {
        commitCouponInput();
        $( '#wrp-coupon-search' ).focus();
    } );

    var couponTimer;
    $( document ).on( 'input', '#wrp-coupon-search', function () {
        var val = $( this ).val().trim();
        clearTimeout( couponTimer );
        if ( val.length < 1 ) { $( '#wrp-coupon-suggestions' ).empty().hide(); return; }
        couponTimer = setTimeout( function () {
            $.post( WRP.ajax_url, { action: 'wrp_search_coupons', nonce: WRP.nonce, search: val }, function ( res ) {
                renderSuggestions( '#wrp-coupon-suggestions', res.data || [], function ( item ) {
                    addCouponCode( item.code );
                    $( '#wrp-coupon-search' ).val( '' );
                    $( '#wrp-coupon-suggestions' ).empty().hide();
                } );
            } );
        }, 250 );
    } );

    function addCouponCode( code ) {
        code = code.toLowerCase().trim();
        if ( ! code || couponCodes.indexOf( code ) !== -1 ) return;
        couponCodes.push( code );
        renderTags();
    }

    function removeCouponCode( code ) {
        couponCodes = couponCodes.filter( function ( c ) { return c !== code; } );
        renderTags();
    }

    function renderTags() {
        var $wrap = $( '#wrp-coupon-tags' );
        $wrap.empty();
        couponCodes.forEach( function ( code ) {
            $( '<span class="wrp-tag">' )
                .text( code.toUpperCase() )
                .append(
                    $( '<button type="button" class="wrp-tag-remove" title="Remove">&times;</button>' )
                        .on( 'click', function () { removeCouponCode( code ); } )
                )
                .appendTo( $wrap );
        } );
    }

    /* ── Coupon discount type toggle ────────────────── */

    $( document ).on( 'change', '#wrp-coupon-discount-type', function () {
        var val = $( this ).val();
        $( '#wrp-coupon-amount-wrap' ).toggle( val !== 'none' );
        // Swap currency symbol label for percent vs fixed
        $( '#wrp-coupon-amount-wrap .wrp-currency-symbol' ).text( val === 'percent' ? '%' : WRP.currency_symbol );
    } );

    // Init on page load in case modal was already open
    (function () {
        var val = $( '#wrp-coupon-discount-type' ).val();
        if ( val ) {
            $( '#wrp-coupon-amount-wrap' ).toggle( val !== 'none' );
            $( '#wrp-coupon-amount-wrap .wrp-currency-symbol' ).text( val === 'percent' ? '%' : WRP.currency_symbol );
        }
    })();

    /* ── Suggestion renderer (shared) ───────────────── */

    function renderSuggestions( selector, items, onSelect ) {
        var $box = $( selector );
        $box.empty();
        if ( ! items.length ) { $box.hide(); return; }
        items.forEach( function ( item ) {
            var label = item.label;
            $( '<div class="wrp-suggestion-item">' )
                .text( label )
                .on( 'click', function () { onSelect( item ); } )
                .appendTo( $box );
        } );
        $box.show();
    }

    // Close suggestions on outside click
    $( document ).on( 'click', function ( e ) {
        if ( ! $( e.target ).closest( '#wrp-user-search, #wrp-user-suggestions' ).length ) {
            $( '#wrp-user-suggestions' ).empty().hide();
        }
        if ( ! $( e.target ).closest( '#wrp-coupon-search, #wrp-coupon-suggestions' ).length ) {
            $( '#wrp-coupon-suggestions' ).empty().hide();
        }
    } );

    /* ── Mark single commission paid ─────────────────── */

    $( document ).on( 'click', '.wrp-btn-mark-paid', function () {
        var $btn = $( this );
        var id   = $btn.data( 'id' );
        var $row = $btn.closest( 'tr' );

        $btn.prop( 'disabled', true ).text( 'Saving…' );

        $.post( WRP.ajax_url, { action: 'wrp_mark_paid', nonce: WRP.nonce, commission_id: id }, function ( res ) {
            if ( ! res.success ) {
                alert( res.data );
                $btn.prop( 'disabled', false ).text( 'Mark Paid' );
                return;
            }
            if ( res.data.html ) {
                $row.replaceWith( res.data.html );
            }
            showNotice( 'Commission marked as paid.', 'success' );
        } );
    } );

    /* ── Check all ───────────────────────────────────── */

    $( document ).on( 'change', '#wrp-check-all', function () {
        $( '.wrp-commission-check' ).prop( 'checked', $( this ).is( ':checked' ) );
    } );

    /* ── Bulk mark paid ──────────────────────────────── */

    $( document ).on( 'click', '.wrp-btn-bulk-paid', function () {
        var ids = [];
        $( '.wrp-commission-check:checked' ).each( function () {
            ids.push( $( this ).val() );
        } );

        if ( ! ids.length ) {
            alert( 'Please select at least one commission to mark as paid.' );
            return;
        }

        if ( ! confirm( 'Mark ' + ids.length + ' commission(s) as paid?' ) ) return;

        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Saving…' );

        $.post( WRP.ajax_url, { action: 'wrp_bulk_mark_paid', nonce: WRP.nonce, commission_ids: ids }, function ( res ) {
            $btn.prop( 'disabled', false ).text( 'Mark Selected as Paid' );
            if ( res.success ) {
                showNotice( res.data.count + ' commission(s) marked as paid. Refreshing…', 'success' );
                setTimeout( function () { location.reload(); }, 1200 );
            } else {
                alert( res.data );
            }
        } );
    } );

    /* ── Helpers ─────────────────────────────────────── */

    function resetForm() {
        $( '#wrp-referrer-form' )[0].reset();
        $( '#wrp-referrer-id' ).val( '' );
        $( '#wrp-user-id' ).val( '' );
        $( '#wrp-user-search' ).val( '' );
        $( '#wrp-form-error' ).text( '' );
        $( '#wrp-user-suggestions, #wrp-coupon-suggestions' ).empty().hide();
        $( '#wrp-coupon-discount-type' ).val( 'percent' ).trigger( 'change' );
        $( '#wrp-coupon-amount' ).val( '0' );
        couponCodes = [];
        renderTags();
    }

    function checkEmpty() {
        if ( $( '#wrp-referrers-table tbody .wrp-referrer-row' ).length === 0 ) {
            $( '#wrp-empty-placeholder' ).show();
        }
    }

    function showNotice( msg, type ) {
        var cls = type === 'success' ? 'notice-success' : 'notice-info';
        var $n  = $( '<div class="notice ' + cls + ' is-dismissible"><p>' + msg + '</p></div>' );
        $( '.wrp-wrap h1' ).after( $n );
        setTimeout( function () { $n.fadeOut( 400, function () { $( this ).remove(); } ); }, 3000 );
    }

    function copyToClipboard( text, $btn, defaultLabel, copiedLabel ) {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then( function () {
                $btn.text( copiedLabel );
                setTimeout( function () { $btn.text( defaultLabel ); }, 2000 );
            } );
        } else {
            var $tmp = $( '<input>' ).val( text ).appendTo( 'body' ).select();
            document.execCommand( 'copy' );
            $tmp.remove();
            $btn.text( copiedLabel );
            setTimeout( function () { $btn.text( defaultLabel ); }, 2000 );
        }
    }

} );
