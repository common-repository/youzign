( function ( $ ) {

	$( window ).on( 'load', function () {

		// key stuff : get parent, original object
		var original = window.parent.document;

		// for debug : trace every event
		var originalTrigger = wp.media.view.MediaFrame.Post.prototype.trigger,
			originalId = window.parent.wp.media.model.settings.post.id;

		wp.media.view.MediaFrame.Post.prototype.trigger = function () {
			originalTrigger.apply( this, Array.prototype.slice.call( arguments ) );
			// console.log( 'Event Triggered:', arguments );
		}

		// ui tweaks : get parent, show toolbar, hide secondary toolbar
		$( '.media-modal-content .media-frame', original ).removeClass( 'hide-toolbar' );
		$( '.media-toolbar-primary', original ).html( '<a class="button media-button button-primary button-large media-button-insert" href="#" disabled="disabled">' + youzignArgs.text_insert + '</a><a class="button media-button button-secondary button-large media-button-import" href="#" disabled="disabled">' + youzignArgs.text_import + '</a>' );
		$( '.media-toolbar-secondary', original ).hide();

		// get vars
		var insertButton = $( '.media-toolbar .media-button-insert', original );
		var importButton = $( '.media-toolbar .media-button-import', original );
		var selectedIds = [ ];

		// toggle selected on attachment item
		$( document ).on( 'click', '.youzign .attachment', function ( event ) {

			if ( $( this ).hasClass( 'selected' ) ) {
				$( this ).removeClass( 'selected details' );
			} else {
				$( this ).addClass( 'selected details' );
			}

			// add items to array
			var found = $.inArray( $( this ).data( 'id' ), selectedIds );

			if ( found >= 0 ) {
				// element was found, remove it
				selectedIds.splice( found, 1 );
			} else {
				// element was not found, add it
				selectedIds.push( $( this ).data( 'id' ) );
			}

			// disable, enable insert button
			if ( $.isEmptyObject( selectedIds ) === false ) {
				$( insertButton ).removeAttr( 'disabled' );
				$( importButton ).removeAttr( 'disabled' );
			} else {
				$( insertButton ).attr( 'disabled', true );
				$( importButton ).attr( 'disabled', true );
			}

		} );

		// insert into post
		$( insertButton ).on( 'click', function ( event ) {
			event.preventDefault();

			if ( $( this ).attr( 'disabled' ) != 'disabled' ) {
				if ( $.isEmptyObject( selectedIds ) === false ) {
					var output = '';

					$.each( selectedIds, function ( index, id ) {

						var item = $( '.media-frame.youzign' ).find( 'li[data-id="' + id + '"]' ),
							url = $( item ).data( 'image-url' ),
							width = $( item ).data( 'image-width' ),
							height = $( item ).data( 'image-height' ),
							title = $( item ).find( '.filename' ).text().trim();

						output += '<img src="' + url + '" alt="' + title + '" width="' + width + '" height="' + height + '" class="youzign-image" />';
					} );

					// send to editor
					original.defaultView.send_to_editor( output );

					// close frame
					original.defaultView.wp.media.frame.close();

					// reset frame
					$.each( selectedIds, function ( index, id ) {
						$( '.media-frame.youzign' ).find( 'li[data-id="' + id + '"]' ).removeClass( 'selected details' );
					} );
					$( insertButton ).attr( 'disabled', true );
					selectedIds = [ ];

					return false;
				}
			}
		} );

		// import to media library
		$( importButton ).on( 'click', function ( event ) {
			event.preventDefault();

			var selectedUrls = [ ],
				selectedTitles = [ ];

			if ( $( this ).attr( 'disabled' ) != 'disabled' ) {
				if ( $.isEmptyObject( selectedIds ) === false ) {

					$.each( selectedIds, function ( index, id ) {

						var item = $( '.media-frame.youzign' ).find( 'li[data-id="' + id + '"]' ),
							url = $( item ).data( 'image-url' ),
							width = $( item ).data( 'image-width' ),
							height = $( item ).data( 'image-height' ),
							title = $( item ).find( '.filename' ).text().trim();

						selectedUrls.push( url );
						selectedTitles.push( title );
					} );

					var request = {
						action: 'yz-import-to-library',
						yx_nonce: youzignArgs.nonce,
						post_id: originalId,
						urls: selectedUrls,
						titles: selectedTitles,
						youzign_ids: selectedIds
					};

					$.ajax( {
						url: youzignArgs.ajax_url,
						type: 'post',
						async: false,
						cache: false,
						data: request,
						beforeSend: function () {
							$( insertButton ).attr( 'disabled', true );
							$( importButton ).attr( 'disabled', true );

							$.each( selectedIds, function ( index, id ) {
								var item = $( '.media-frame.youzign' ).find( 'li[data-id="' + id + '"]' );
								$( item ).addClass( 'loading' ).find( '.attachment-preview' ).append( '<span class="spinner is-active"></span>' );
							} );
						},
						success: function ( data ) {
							var response = $.parseJSON( data );
							var youzign_ids = [ ];
							var attachment_ids = [ ];

							$( insertButton ).removeAttr( 'disabled' );
							$( importButton ).removeAttr( 'disabled' );

							// get uploaded youzign and attachment ids
							if ( $.isEmptyObject( response.ids ) == false ) {
								$.each( response.ids, function ( youzign_id, attachment_id ) {
									youzign_ids.push( parseInt( youzign_id ) );
									attachment_ids.push( parseInt( attachment_id ) );
								} );
							}

							$.each( selectedIds, function ( index, id ) {
								var item = $( '.media-frame.youzign' ).find( 'li[data-id="' + id + '"]' );

								// remove loading
								$( item ).removeClass( 'loading' );
								$( item ).find( '.spinner' ).remove();

								var found = $.inArray( id, youzign_ids );

								if ( found >= 0 ) {
									$( item ).addClass( 'in-library' );
								}
							} );

						},
					} );

					return false;
				}
			}
		} );

	} );

} )( jQuery );