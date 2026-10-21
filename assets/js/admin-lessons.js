/**
 * Lessons repeater — add / remove / reorder rows.
 *
 * Vanilla jQuery (already loaded by WP admin).
 * No build step needed.
 */
( function ( $ ) {
	'use strict';

	var $panel   = $( '#csb_video_options' );
	var $list    = $panel.find( '#csb-lessons-list' );
	var template = $( '#csb-lesson-template' ).html();
	var counter  = $list.find( '.csb-lesson-row' ).length;

	// ── Add row ──────────────────────────────────────────────────────────────
	$panel.on( 'click', '.csb-add-lesson', function () {
		var html = template.replace( /__INDEX__/g, counter );
		$list.append( html );
		counter++;
		renumber();
	} );

	// ── Remove row ───────────────────────────────────────────────────────────
	$panel.on( 'click', '.csb-remove-lesson', function () {
		$( this ).closest( '.csb-lesson-row' ).remove();
		renumber();
	} );

	// ── Renumber order inputs after add/remove ────────────────────────────────
	function renumber() {
		$list.find( '.csb-lesson-row' ).each( function ( idx ) {
			$( this ).find( '.csb-lesson-order' ).val( idx );
		} );
	}

	// ── Make sortable if jQuery UI is available ────────────────────────────────
	if ( $.fn.sortable ) {
		$list.sortable( {
			handle: '.csb-lesson-title',
			stop:   renumber,
		} );
	}

} )( jQuery );
