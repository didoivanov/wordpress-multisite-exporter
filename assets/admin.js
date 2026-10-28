/* global MSE */
( function () {
	'use strict';

	const root = document.querySelector( '.wrap.mse' );
	if ( ! root ) {
		return;
	}
	const $ = ( sel, ctx ) => ( ctx || root ).querySelector( sel );
	const $$ = ( sel, ctx ) => Array.from( ( ctx || root ).querySelectorAll( sel ) );

	const esc = ( s ) => String( s === undefined || s === null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const size = ( b ) => {
		b = Number( b ) || 0;
		const u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let i = 0;
		while ( b >= 1024 && i < u.length - 1 ) {
			b /= 1024;
			i++;
		}
		return ( i ? b.toFixed( 1 ) : b ) + ' ' + u[ i ];
	};
	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

	async function call( action, data, file ) {
		const body = new FormData();
		body.append( 'action', 'mse_' + action );
		body.append( 'nonce', MSE.nonce );
		Object.entries( data || {} ).forEach( ( [ k, v ] ) => {
			if ( Array.isArray( v ) ) {
				v.forEach( ( item ) => body.append( k + '[]', item ) );
			} else {
				body.append( k, v );
			}
		} );
		if ( file ) {
			body.append( 'chunk', file, 'chunk' );
		}
		const res = await fetch( MSE.ajax, { method: 'POST', body, credentials: 'same-origin' } );
		let json;
		try {
			json = await res.json();
		} catch ( e ) {
			throw new Error( 'The server returned an invalid response (HTTP ' + res.status + '). Check the PHP error log.' );
		}
		if ( ! json.success ) {
			throw new Error( ( json.data && json.data.message ) || 'Request failed.' );
		}
		return json.data;
	}

	function notify( message, type ) {
		const box = document.createElement( 'div' );
		box.className = 'notice is-dismissible notice-' + ( type || 'error' );
		box.innerHTML = '<p>' + esc( message ) + '</p>';
		$( 'nav.nav-tab-wrapper' ).before( box );
		setTimeout( () => box.remove(), 12000 );
	}

	// ------------------------------------------------------------- Tabs.
	function showTab( name ) {
		$$( '.nav-tab' ).forEach( ( t ) => t.classList.toggle( 'nav-tab-active', t.dataset.tab === name ) );
		$$( 'section.mse-tab' ).forEach( ( s ) => ( s.hidden = s.dataset.tab !== name ) );
	}
	$$( '.nav-tab' ).forEach( ( t ) => t.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		history.replaceState( null, '', '#' + t.dataset.tab );
		showTab( t.dataset.tab );
	} ) );
	showTab( location.hash === '#import' ? 'import' : 'export' );

	// ----------------------------------------------------- Site picker.
	const filter = $( '.mse-filter' );
	const updateCount = () => {
		const n = $$( '.mse-site:checked' ).length;
		$( '.mse-selected-count' ).textContent = n ? n + ' selected' : '';
		$( '.mse-export-btn' ).disabled = ! n;
	};
	filter.addEventListener( 'input', () => {
		const q = filter.value.trim().toLowerCase();
		$$( '.mse-sites tbody tr' ).forEach( ( tr ) => ( tr.hidden = q && ! tr.dataset.search.includes( q ) ) );
	} );
	$( '.mse-check-all' ).addEventListener( 'change', ( e ) => {
		$$( '.mse-sites tbody tr' ).forEach( ( tr ) => {
			if ( ! tr.hidden ) {
				$( '.mse-site', tr ).checked = e.target.checked;
			}
		} );
		updateCount();
	} );
	$( '.mse-sites' ).addEventListener( 'change', updateCount );

	$( '.mse-export-btn' ).addEventListener( 'click', async ( e ) => {
		e.target.disabled = true;
		try {
			const sites = $$( '.mse-site:checked' ).map( ( c ) => c.value );
			render( await call( 'export', { sites, media: $( '.mse-media' ).checked ? '1' : '' } ) );
			$$( '.mse-site:checked' ).forEach( ( c ) => ( c.checked = false ) );
			$( '.mse-check-all' ).checked = false;
			updateCount();
			run();
		} catch ( err ) {
			notify( err.message );
			e.target.disabled = false;
		}
	} );

	// ---------------------------------------------------------- Import.
	$( '.mse-urls-btn' ).addEventListener( 'click', async ( e ) => {
		e.target.disabled = true;
		try {
			render( await call( 'import_urls', { urls: $( '.mse-urls' ).value } ) );
			$( '.mse-urls' ).value = '';
			run();
		} catch ( err ) {
			notify( err.message );
		}
		e.target.disabled = false;
	} );

	$$( '.mse-local-btn' ).forEach( ( b ) => b.addEventListener( 'click', async () => {
		b.disabled = true;
		try {
			render( await call( 'import_local', { file: b.dataset.file } ) );
			b.closest( 'li' ).remove();
			run();
		} catch ( err ) {
			notify( err.message );
			b.disabled = false;
		}
	} ) );

	$( '.mse-upload-btn' ).addEventListener( 'click', async ( e ) => {
		const files = Array.from( $( '.mse-file' ).files );
		if ( ! files.length ) {
			notify( 'Choose one or more .tar packages first.' );
			return;
		}
		e.target.disabled = true;
		for ( const file of files ) {
			try {
				await upload( file );
			} catch ( err ) {
				notify( file.name + ': ' + err.message );
			}
		}
		$( '.mse-file' ).value = '';
		e.target.disabled = false;
	} );

	async function upload( file ) {
		const { id } = await call( 'upload_start', { name: file.name, size: file.size } );
		refresh();
		// wp_localize_script() passes numbers as strings.
		const chunkSize = parseInt( MSE.chunkSize, 10 );
		let offset = 0;
		while ( offset < file.size ) {
			const chunk = file.slice( offset, offset + chunkSize );
			let res;
			for ( let attempt = 1; ; attempt++ ) {
				try {
					res = await call( 'upload_chunk', { id, offset }, chunk );
					break;
				} catch ( err ) {
					if ( attempt >= 4 ) {
						throw err;
					}
					await sleep( 2000 * attempt );
				}
			}
			offset = Number( res.received );
			const card = $( '.mse-job[data-id="' + id + '"]' );
			if ( card ) {
				$( '.mse-bar span', card ).style.width = Math.floor( 100 * offset / file.size ) + '%';
				$( '.mse-label', card ).textContent = 'Uploading… ' + size( offset ) + ' of ' + size( file.size );
			}
		}
		await refresh();
		run();
	}

	$( '.mse-start-all' ).addEventListener( 'click', async ( e ) => {
		if ( ! confirm( 'Start every import that is waiting for review, keeping each site\'s original domain? Imports with failed checks are skipped.' ) ) {
			return;
		}
		e.target.disabled = true;
		try {
			const data = await call( 'configure', { all: 1, theme: $( '.mse-all-theme' ).value } );
			const blocked = data.configured.filter( ( c ) => c.blocked ).length;
			if ( blocked ) {
				notify( blocked + ' import(s) have failed checks and were not started. Review them individually.', 'warning' );
			}
			render( data );
			run();
		} catch ( err ) {
			notify( err.message );
		}
		e.target.disabled = false;
	} );

	// ------------------------------------------------------ Job cards.
	const STATUS = {
		uploading: 'Uploading',
		preparing: 'Preparing',
		review: 'Needs review',
		queued: 'Queued',
		running: 'Running',
		paused: 'Paused',
		done: 'Done',
		error: 'Error',
	};

	function checksHtml( checks ) {
		if ( ! checks || ! checks.length ) {
			return '';
		}
		return '<ul class="mse-checks">' + checks.map( ( c ) => '<li class="mse-check-' + esc( c.level ) + '"><strong>' + esc( c.label ) + '</strong>' +
			( c.detail ? '<div class="mse-detail">' + esc( c.detail ).replace( /\n/g, '<br>' ) + '</div>' : '' ) + '</li>' ).join( '' ) + '</ul>';
	}

	function reviewHtml( job ) {
		const src = job.result.source || {};
		const d = job.result.defaults || {};
		const hasErrors = ( job.result.preflight || [] ).some( ( c ) => c.level === 'error' );
		const files = src.files || {};
		return '<div class="mse-review">' +
			'<p class="mse-source"><strong>' + esc( src.blogname ) + '</strong> — ' + esc( src.domain ) + ' (site ' + esc( src.blog_id ) + ' on ' + esc( src.network ) + ', exported ' + esc( ( src.created || '' ).replace( 'T', ' ' ).slice( 0, 16 ) ) + ')<br>' +
			esc( src.tables ) + ' tables, ' + Number( src.rows || 0 ).toLocaleString() + ' rows, ' + Number( files.count || 0 ).toLocaleString() + ' files (' + size( files.bytes ) + '), theme ' + esc( src.theme ) + ', ' + esc( src.plugins ) + ' plugins</p>' +
			checksHtml( job.result.preflight ) +
			'<div class="mse-form">' +
			'<label>Target domain <input type="text" name="target_domain" class="regular-text" value="' + esc( d.target_domain ) + '"></label>' +
			( src.theme_missing ? '<label>Replacement theme (the original theme is not installed here) <select name="theme"><option value="">— choose a theme —</option>' +
				Object.entries( MSE.themes || {} ).map( ( [ slug, name ] ) => '<option value="' + esc( slug ) + '">' + esc( name ) + ' (' + esc( slug ) + ')</option>' ).join( '' ) +
				'</select></label>' : '' ) +
			'<fieldset><label><input type="radio" name="mode-' + job.id + '" value="new"' + ( d.mode !== 'overwrite' ? ' checked' : '' ) + '> Create new site</label> ' +
			'<label><input type="radio" name="mode-' + job.id + '" value="overwrite"' + ( d.mode === 'overwrite' ? ' checked' : '' ) + '> Overwrite existing site with this domain</label></fieldset>' +
			( ( src.old_hosts || [] ).length ? '<label><input type="checkbox" name="replace_old_hosts" checked> Also replace the site\'s earlier addresses with the target domain: <code>' + src.old_hosts.map( esc ).join( '</code>, <code>' ) + '</code></label>' : '' ) +
			'<details><summary>Advanced</summary>' +
			'<label>Extra replacements, one per line as <code>old =&gt; new</code> (applied first). Example: <code>testsite.cms1.com =&gt; testsite.com</code><textarea name="extra_pairs" rows="3" class="large-text"></textarea></label>' +
			'<label><input type="checkbox" name="skip_guids" checked> Do not change post GUIDs (recommended)</label>' +
			'</details>' +
			( hasErrors ? '<label class="mse-force"><input type="checkbox" name="force"> Import anyway, despite the errors above</label>' : '' ) +
			'<p><button type="button" class="button button-primary" data-do="configure">Check again &amp; start import</button></p>' +
			'</div></div>';
	}

	function jobHtml( job ) {
		const p = job.progress || {};
		const r = job.result || {};
		let actions = '';
		if ( [ 'preparing', 'queued', 'running' ].includes( job.status ) ) {
			actions += '<button type="button" class="button button-small" data-do="pause">Pause</button>';
		}
		if ( job.status === 'paused' ) {
			actions += '<button type="button" class="button button-small" data-do="resume">Resume</button>';
		}
		if ( job.status === 'error' ) {
			actions += '<button type="button" class="button button-small" data-do="retry">Retry from last step</button>';
		}
		if ( job.type === 'export' && job.status === 'done' ) {
			actions += '<a class="button button-small button-primary" href="' + esc( r.url ) + '" download>Download (' + size( r.size ) + ')</a>' +
				'<button type="button" class="button button-small" data-do="copy" data-url="' + esc( r.url ) + '">Copy URL</button>';
		}
		if ( job.type === 'import' && job.status === 'done' && r.target ) {
			actions += '<a class="button button-small button-primary" href="' + esc( r.target.url ) + '" target="_blank" rel="noopener">Visit site</a>' +
				'<a class="button button-small" href="' + esc( r.target.admin ) + '" target="_blank" rel="noopener">Dashboard</a>';
		}
		actions += '<button type="button" class="button button-small" data-do="log">Full log</button>';
		actions += '<button type="button" class="button button-small button-link-delete" data-do="delete">Delete</button>';

		const log = ( job.log || [] ).map( ( l ) => '<div class="mse-log-' + esc( l[ 1 ] ) + '">' + esc( new Date( l[ 0 ] * 1000 ).toLocaleTimeString() ) + ' ' + esc( l[ 2 ] ) + '</div>' ).join( '' );

		return '<div class="mse-job-head"><span class="mse-status mse-status-' + esc( job.status ) + '">' + esc( STATUS[ job.status ] || job.status ) + '</span> ' +
			'<strong>' + esc( job.title ) + '</strong><span class="mse-actions">' + actions + '</span></div>' +
			( job.status === 'review' ? reviewHtml( job ) : '' ) +
			( job.status !== 'review' ? '<div class="mse-bar"><span style="width:' + ( Number( p.percent ) || 0 ) + '%"></span></div><div class="mse-label">' + esc( p.label ) + '</div>' : '' ) +
			( job.error ? '<div class="mse-error">' + esc( job.error ) + '</div>' : '' ) +
			'<details class="mse-log"><summary>Recent log</summary>' + log + '</details>';
	}

	function render( state ) {
		let anyRunnable = false;
		[ 'export', 'import' ].forEach( ( type ) => {
			const box = $( '.mse-jobs[data-type="' + type + '"]' );
			const jobs = state.jobs.filter( ( j ) => j.type === type ).reverse();
			if ( ! jobs.length ) {
				return;
			}
			const seen = new Set();
			jobs.forEach( ( job, index ) => {
				seen.add( job.id );
				let card = $( '.mse-job[data-id="' + job.id + '"]', box );
				const sig = job.status + '|' + job.updated + '|' + ( job.progress && job.progress.percent );
				if ( card && card.dataset.sig === sig ) {
					return;
				}
				if ( card && card.dataset.status === 'review' && job.status === 'review' ) {
					// Keep what the admin typed; refresh only the checks.
					const checks = $( '.mse-checks', card );
					const fresh = document.createElement( 'div' );
					fresh.innerHTML = checksHtml( job.result.preflight );
					if ( checks && fresh.firstChild ) {
						checks.replaceWith( fresh.firstChild );
					}
					const hasErrors = ( job.result.preflight || [] ).some( ( c ) => c.level === 'error' );
					if ( hasErrors && ! $( '.mse-force', card ) ) {
						$( '[data-do="configure"]', card ).parentNode.insertAdjacentHTML( 'beforebegin', '<label class="mse-force"><input type="checkbox" name="force"> Import anyway, despite the errors above</label>' );
					}
					card.dataset.sig = sig;
					return;
				}
				const openLog = card && $( '.mse-log', card ) && $( '.mse-log', card ).open;
				if ( ! card ) {
					card = document.createElement( 'div' );
					card.className = 'mse-job';
					card.dataset.id = job.id;
				}
				card.dataset.sig = sig;
				card.dataset.status = job.status;
				card.innerHTML = jobHtml( job );
				if ( openLog ) {
					$( '.mse-log', card ).open = true;
				}
				const at = box.querySelectorAll( '.mse-job' )[ index ];
				if ( at !== card ) {
					box.insertBefore( card, at || null );
				}
			} );
			$$( '.mse-job', box ).forEach( ( c ) => ! seen.has( c.dataset.id ) && c.remove() );
			const empty = $( 'p.description', box );
			if ( empty ) {
				empty.remove();
			}
		} );
		state.jobs.forEach( ( j ) => {
			if ( [ 'preparing', 'queued', 'running' ].includes( j.status ) ) {
				anyRunnable = true;
			}
		} );
		$( '.mse-start-all-wrap' ).hidden = ! state.jobs.some( ( j ) => j.status === 'review' );
		$( '.mse-all-theme-label' ).hidden = ! state.jobs.some( ( j ) => j.status === 'review' && j.result.source && j.result.source.theme_missing );
		$( '.mse-keep-open' ).hidden = ! anyRunnable;
		return anyRunnable;
	}

	root.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '[data-do]' );
		if ( ! btn ) {
			return;
		}
		const card = btn.closest( '.mse-job' );
		const id = card.dataset.id;
		const what = btn.dataset.do;

		if ( what === 'copy' ) {
			navigator.clipboard.writeText( btn.dataset.url ).then( () => ( btn.textContent = 'Copied!' ) );
			return;
		}
		if ( what === 'log' ) {
			const data = await call( 'job_action', { id, do: 'log' } );
			const pre = document.createElement( 'pre' );
			pre.className = 'mse-full-log';
			pre.textContent = data.log.map( ( l ) => new Date( l[ 0 ] * 1000 ).toLocaleString() + ' [' + l[ 1 ] + '] ' + l[ 2 ] ).join( '\n' );
			const old = $( '.mse-full-log', card );
			if ( old ) {
				old.remove();
			} else {
				card.appendChild( pre );
			}
			return;
		}
		if ( what === 'delete' ) {
			const isExport = card.closest( '[data-type="export"]' );
			if ( ! confirm( isExport ? 'Delete this job and its package file?' : 'Delete this job and its downloaded package? An imported site is NOT deleted.' ) ) {
				return;
			}
		}

		btn.disabled = true;
		try {
			let data;
			if ( what === 'configure' ) {
				const mode = $( 'input[name="mode-' + id + '"]:checked', card );
				data = await call( 'configure', {
					id,
					target_domain: $( '[name="target_domain"]', card ).value,
					mode: mode ? mode.value : 'new',
					extra_pairs: $( '[name="extra_pairs"]', card ).value,
					replace_old_hosts: $( '[name="replace_old_hosts"]', card ) && $( '[name="replace_old_hosts"]', card ).checked ? '1' : '',
					theme: $( '[name="theme"]', card ) ? $( '[name="theme"]', card ).value : '',
					skip_guids: $( '[name="skip_guids"]', card ).checked ? '1' : '',
					force: $( '[name="force"]', card ) && $( '[name="force"]', card ).checked ? '1' : '',
				} );
				if ( data.configured[ 0 ].blocked ) {
					notify( 'The checks found errors. Fix them, or tick "Import anyway".', 'warning' );
				}
			} else {
				data = await call( 'job_action', { id, do: what } );
			}
			render( data );
			run();
		} catch ( err ) {
			notify( err.message );
		}
		btn.disabled = false;
	} );

	// ----------------------------------------------------------- Runner.
	let running = false;
	async function run() {
		if ( running ) {
			return;
		}
		running = true;
		let failures = 0;
		try {
			while ( true ) {
				let state;
				try {
					state = await call( 'tick' );
					failures = 0;
				} catch ( err ) {
					failures++;
					if ( failures >= 5 ) {
						notify( 'Stopped after repeated errors: ' + err.message + ' Reload the page to continue.' );
						break;
					}
					await sleep( 3000 * failures );
					continue;
				}
				if ( ! render( state ) ) {
					break;
				}
				if ( state.busy ) {
					await sleep( 3000 );
				}
			}
		} finally {
			running = false;
		}
	}

	async function refresh() {
		try {
			return render( await call( 'state' ) );
		} catch ( err ) {
			notify( err.message );
			return false;
		}
	}

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( running ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	refresh().then( ( runnable ) => runnable && run() );
}() );
