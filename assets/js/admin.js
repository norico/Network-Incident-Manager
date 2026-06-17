/* Network Incident Manager — admin list page: inline status update */
document.addEventListener( 'DOMContentLoaded', function () {

    document.querySelectorAll( '.nim-status-select' ).forEach( function ( select ) {
        var original = select.value;

        select.addEventListener( 'change', function () {
            var id       = this.dataset.id;
            var status   = this.value;
            var feedback = this.nextElementSibling; // .nim-status-feedback span

            feedback.style.display = 'inline';
            feedback.style.color   = '#999';
            feedback.textContent   = '…';

            var body = new URLSearchParams( {
                action : 'nim_update_status',
                id     : id,
                status : status,
                _ajax_nonce: nimAdmin.nonce,
            } );

            fetch( nimAdmin.ajaxurl, {
                method  : 'POST',
                headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                body    : body.toString(),
            } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( data ) {
                if ( data.success ) {
                    original               = status;
                    feedback.style.color   = '#46b450';
                    feedback.textContent   = '✓ ' + nimAdmin.saved;
                    setTimeout( function () { feedback.style.display = 'none'; }, 2000 );
                } else {
                    select.value           = original;
                    feedback.style.color   = '#dc3232';
                    feedback.textContent   = '✗ ' + ( data.data && data.data.message ? data.data.message : nimAdmin.error );
                    setTimeout( function () { feedback.style.display = 'none'; }, 3000 );
                }
            } )
            .catch( function () {
                select.value           = original;
                feedback.style.color   = '#dc3232';
                feedback.textContent   = '✗ ' + nimAdmin.error;
                setTimeout( function () { feedback.style.display = 'none'; }, 3000 );
            } );
        } );
    } );

} );
