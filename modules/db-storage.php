<?php

/**
 * DB Storage module for Contact Form 7.
 *
 * Adds a "Save entries to database" toggle in the form-editor sidebar.
 * When enabled, every successful submission is written to the database
 * table so entries can be reviewed in the WordPress admin.
 *
 * Table: {$wpdb->prefix}wpcf7_entries
 *   id             BIGINT UNSIGNED  – auto-increment primary key
 *   form_id        BIGINT UNSIGNED  – the contact form post ID
 *   form_title     VARCHAR(255)     – form title at time of submission
 *   posted_data    LONGTEXT         – JSON-encoded submitted field values
 *   meta_data      LONGTEXT         – JSON-encoded submission meta (IP, URL …)
 *   submitted_at   DATETIME         – UTC timestamp
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

/* ------------------------------------------------------------------
 * 1. Database table installation
 * ------------------------------------------------------------------ */

/**
 * Creates the wpcf7_entries table. Uses dbDelta so it is safe to call
 * multiple times (idempotent).
 */
function wpcf7_db_storage_create_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wpcf7_entries';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		form_id bigint(20) unsigned NOT NULL DEFAULT 0,
		form_title varchar(255) NOT NULL DEFAULT '',
		posted_data longtext NOT NULL,
		meta_data longtext NOT NULL,
		submitted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY form_id (form_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'wpcf7_db_storage_version', '1.0' );
}

// Runs when the plugin is activated (wpcf7_install is called from
// 'activate_{plugin_basename}').
add_action( 'wpcf7_install', 'wpcf7_db_storage_create_table', 20, 0 );

// Also run once on admin_init the first time, so the feature works
// without a de/re-activation cycle if the plugin was already active.
add_action(
	'admin_init',
	static function () {
		if ( ! get_option( 'wpcf7_db_storage_version' ) ) {
			wpcf7_db_storage_create_table();
		}
	},
	5, 0
);


/* ------------------------------------------------------------------
 * 2. Saving entries on successful submission
 * ------------------------------------------------------------------ */

add_action( 'wpcf7_mail_sent', 'wpcf7_db_storage_save_entry', 10, 1 );

/**
 * Inserts a submission row into wpcf7_entries after a successful mail send,
 * but only if the form has db_storage switched on.
 *
 * @param WPCF7_ContactForm $contact_form The contact form that was submitted.
 */
function wpcf7_db_storage_save_entry( WPCF7_ContactForm $contact_form ) {
	if ( ! $contact_form->is_true( 'db_storage' ) ) {
		return;
	}

	$submission = WPCF7_Submission::get_instance();

	if ( ! $submission ) {
		return;
	}

	// Strip internal CF7 fields (those that start with an underscore).
	$posted_data = array_filter(
		(array) $submission->get_posted_data(),
		static function ( $key ) {
			return ! str_starts_with( $key, '_' );
		},
		ARRAY_FILTER_USE_KEY
	);

	$meta_data = array(
		'remote_ip'         => $submission->get_meta( 'remote_ip' ),
		'user_agent'        => $submission->get_meta( 'user_agent' ),
		'url'               => $submission->get_meta( 'url' ),
		'container_post_id' => $submission->get_meta( 'container_post_id' ),
		'current_user_id'   => $submission->get_meta( 'current_user_id' ),
	);

	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'wpcf7_entries',
		array(
			'form_id'      => (int) $contact_form->id(),
			'form_title'   => $contact_form->title(),
			'posted_data'  => wp_json_encode( $posted_data ),
			'meta_data'    => wp_json_encode( $meta_data ),
			'submitted_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);
}


/* ------------------------------------------------------------------
 * 3. "Save to Database" toggle in the form-editor sidebar
 * ------------------------------------------------------------------ */

add_action( 'wpcf7_admin_misc_pub_section', 'wpcf7_db_storage_pub_section', 10, 1 );

/**
 * Renders the "Save entries to database" checkbox inside the Status metabox.
 *
 * @param int $post_id Contact form post ID.
 */
function wpcf7_db_storage_pub_section( $post_id ) {
	$contact_form = WPCF7_ContactForm::get_instance( $post_id );

	if ( ! $contact_form ) {
		return;
	}

	$enabled = $contact_form->is_true( 'db_storage' );

	?>
	<div class="misc-pub-section wpcf7-db-storage-toggle">
		<span class="dashicons dashicons-database" aria-hidden="true"></span>
		<label for="wpcf7-db-storage-enabled">
			<input
				type="checkbox"
				id="wpcf7-db-storage-enabled"
				name="wpcf7-db-storage-enabled"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Save entries to database', 'contact-form-7' ); ?>
		</label>
	</div>
	<?php
}


/* ------------------------------------------------------------------
 * 4. Persist the toggle when the form is saved
 * ------------------------------------------------------------------ */

add_action( 'wpcf7_save_contact_form', 'wpcf7_db_storage_save_setting', 10, 3 );

/**
 * Injects or removes the "db_storage: on" line in additional_settings
 * based on the checkbox value sent with the save request.
 *
 * This hook fires before $contact_form->save(), so mutating the
 * contact form's properties here is sufficient — no extra save call
 * is needed.
 *
 * @param WPCF7_ContactForm $contact_form The contact form being saved.
 * @param array             $args         Raw data passed to wpcf7_save_contact_form().
 * @param string            $context      Save context ('save' | 'copy' | …).
 */
function wpcf7_db_storage_save_setting(
	WPCF7_ContactForm $contact_form,
	array $args,
	string $context
) {
	if ( 'save' !== $context ) {
		return;
	}

	$enabled = ! empty( $args['wpcf7-db-storage-enabled'] );

	$additional_settings = (string) $contact_form->prop( 'additional_settings' );

	// Remove any existing db_storage directive so we can rewrite it cleanly.
	$lines = array_filter(
		explode( "\n", $additional_settings ),
		static function ( $line ) {
			return ! preg_match( '/^db_storage\s*:/i', trim( $line ) );
		}
	);

	if ( $enabled ) {
		$lines[] = 'db_storage: on';
	}

	$additional_settings = trim( implode( "\n", $lines ) );

	// Update the in-memory property — the main save flow will persist it.
	$contact_form->set_properties( array(
		'additional_settings' => $additional_settings,
	) );
}


/* ------------------------------------------------------------------
 * 5. Admin menu: "Form Entries" submenu and list page
 * ------------------------------------------------------------------ */

add_action( 'wpcf7_admin_menu', 'wpcf7_db_storage_admin_menu', 10, 0 );

/**
 * Registers the "Form Entries" submenu item under the Contact menu.
 */
function wpcf7_db_storage_admin_menu() {
	add_submenu_page(
		'wpcf7',
		__( 'Form Entries', 'contact-form-7' ),
		__( 'Form Entries', 'contact-form-7' ),
		'wpcf7_read_contact_forms',
		'wpcf7-entries',
		'wpcf7_db_storage_entries_page'
	);
}


/**
 * Renders the "Form Entries" admin page.
 */
function wpcf7_db_storage_entries_page() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wpcf7_entries';

	// Determine the selected form filter.
	$selected_form_id = absint( wpcf7_superglobal_request( 'form_id' ) );

	// Handle delete action.
	$action = wpcf7_current_action();

	if ( 'delete' === $action && ! empty( $_POST['entry_ids'] ) ) {
		check_admin_referer( 'wpcf7-delete-entries' );

		$ids = array_map( 'absint', (array) $_POST['entry_ids'] );

		if ( $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table_name}` WHERE id IN ({$placeholders})",
					$ids
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpcf7-entries',
					'form_id' => $selected_form_id ?: '',
					'deleted' => count( $ids ),
				),
				menu_page_url( 'wpcf7', false )
			)
		);
		exit();
	}

	// Fetch available forms that have at least one entry.
	$forms_with_entries = $wpdb->get_results(
		"SELECT DISTINCT form_id, form_title FROM `{$table_name}` ORDER BY form_title ASC"
	);

	// Pagination.
	$per_page    = 20;
	$current_page = max( 1, absint( wpcf7_superglobal_request( 'paged' ) ) );

	$where  = '';
	$params = array();

	if ( $selected_form_id ) {
		$where    = 'WHERE form_id = %d';
		$params[] = $selected_form_id;
	}

	$total_items = (int) $wpdb->get_var(
		$params
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table_name}` {$where}", ...$params )
			: "SELECT COUNT(*) FROM `{$table_name}`"
	);

	$total_pages = ceil( $total_items / $per_page );
	$offset      = ( $current_page - 1 ) * $per_page;

	if ( $params ) {
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` {$where} ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, $offset ) )
			)
		);
	} else {
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
	}

	$base_url = add_query_arg(
		array(
			'page'    => 'wpcf7-entries',
			'form_id' => $selected_form_id ?: '',
		),
		menu_page_url( 'wpcf7', false )
	);

	?>
	<div class="wrap" id="wpcf7-entries-page">

		<h1 class="wp-heading-inline">
			<?php esc_html_e( 'Form Entries', 'contact-form-7' ); ?>
		</h1>

		<hr class="wp-header-end" />

		<?php
		// Deleted notice.
		if ( $deleted = absint( wpcf7_superglobal_request( 'deleted' ) ) ) {
			wp_admin_notice(
				sprintf(
					/* translators: %d: number of deleted entries */
					_n( '%d entry deleted.', '%d entries deleted.', $deleted, 'contact-form-7' ),
					$deleted
				),
				array( 'type' => 'success' )
			);
		}
		?>

		<?php if ( $forms_with_entries ) : ?>
		<div class="tablenav top">
			<div class="alignleft actions">
				<form method="get">
					<input type="hidden" name="page" value="wpcf7-entries" />
					<label class="screen-reader-text" for="wpcf7-entries-form-filter">
						<?php esc_html_e( 'Filter by form', 'contact-form-7' ); ?>
					</label>
					<select id="wpcf7-entries-form-filter" name="form_id">
						<option value=""><?php esc_html_e( '— All Forms —', 'contact-form-7' ); ?></option>
						<?php foreach ( $forms_with_entries as $form ) : ?>
							<option value="<?php echo esc_attr( $form->form_id ); ?>"
								<?php selected( $selected_form_id, $form->form_id ); ?>>
								<?php echo esc_html( $form->form_title ); ?>
								(ID: <?php echo esc_html( $form->form_id ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'contact-form-7' ), 'secondary', '', false ); ?>
				</form>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! $entries ) : ?>
			<p><?php esc_html_e( 'No entries found.', 'contact-form-7' ); ?></p>
		<?php else : ?>

		<form method="post" id="wpcf7-entries-form">
			<?php wp_nonce_field( 'wpcf7-delete-entries' ); ?>
			<input type="hidden" name="action" value="delete" />
			<?php if ( $selected_form_id ) : ?>
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $selected_form_id ); ?>" />
			<?php endif; ?>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="bulk-action-selector-top">
						<?php esc_html_e( 'Select bulk action', 'contact-form-7' ); ?>
					</label>
					<select id="bulk-action-selector-top" name="bulk_action_top">
						<option value=""><?php esc_html_e( 'Bulk actions', 'contact-form-7' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'contact-form-7' ); ?></option>
					</select>
					<input
						type="button"
						id="wpcf7-bulk-delete-top"
						class="button action"
						value="<?php esc_attr_e( 'Apply', 'contact-form-7' ); ?>"
					/>
				</div>
				<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of items */
								_n( '%d item', '%d items', $total_items, 'contact-form-7' ),
								$total_items
							)
						);
						?>
					</span>
					<?php if ( $current_page > 1 ) : ?>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>">
						<span aria-hidden="true">&lsaquo;</span>
					</a>
					<?php endif; ?>
					<span class="paging-input">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: current page, 2: total pages */
								__( '%1$d of %2$d', 'contact-form-7' ),
								$current_page,
								$total_pages
							)
						);
						?>
					</span>
					<?php if ( $current_page < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>">
						<span aria-hidden="true">&rsaquo;</span>
					</a>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="wpcf7-entries-select-all" />
						</td>
						<th class="manage-column"><?php esc_html_e( 'ID', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Form', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Submitted Fields', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'IP Address', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Submitted', 'contact-form-7' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) :
						$posted = json_decode( $entry->posted_data, true ) ?: array();
						$meta   = json_decode( $entry->meta_data, true ) ?: array();
						?>
					<tr>
						<th scope="row" class="check-column">
							<input
								type="checkbox"
								name="entry_ids[]"
								value="<?php echo esc_attr( $entry->id ); ?>"
							/>
						</th>
						<td><?php echo esc_html( $entry->id ); ?></td>
						<td>
							<?php echo esc_html( $entry->form_title ); ?>
							<br />
							<a href="<?php echo esc_url(
								add_query_arg(
									array(
										'page'    => 'wpcf7-entries',
										'form_id' => $entry->form_id,
									),
									menu_page_url( 'wpcf7', false )
								)
							); ?>">
								<?php
								echo esc_html(
									/* translators: %d: form ID */
									sprintf( __( 'ID: %d', 'contact-form-7' ), $entry->form_id )
								);
								?>
							</a>
						</td>
						<td>
							<dl class="wpcf7-entry-fields">
								<?php foreach ( $posted as $field_name => $value ) :
									$display = is_array( $value )
										? implode( ', ', $value )
										: (string) $value;
									?>
									<dt><?php echo esc_html( $field_name ); ?></dt>
									<dd><?php echo esc_html( $display ); ?></dd>
								<?php endforeach; ?>
							</dl>
						</td>
						<td><?php echo esc_html( $meta['remote_ip'] ?? '' ); ?></td>
						<td>
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $entry->submitted_at )
								)
							);
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" />
						</td>
						<th class="manage-column"><?php esc_html_e( 'ID', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Form', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Submitted Fields', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'IP Address', 'contact-form-7' ); ?></th>
						<th class="manage-column"><?php esc_html_e( 'Submitted', 'contact-form-7' ); ?></th>
					</tr>
				</tfoot>
			</table>

			<div class="tablenav bottom">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="bulk-action-selector-bottom">
						<?php esc_html_e( 'Select bulk action', 'contact-form-7' ); ?>
					</label>
					<select id="bulk-action-selector-bottom" name="bulk_action_bottom">
						<option value=""><?php esc_html_e( 'Bulk actions', 'contact-form-7' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'contact-form-7' ); ?></option>
					</select>
					<input
						type="button"
						id="wpcf7-bulk-delete-bottom"
						class="button action"
						value="<?php esc_attr_e( 'Apply', 'contact-form-7' ); ?>"
					/>
				</div>
			</div>
		</form>

		<?php endif; ?>

	</div>

	<script>
	(function() {
		// Select-all checkbox.
		var selectAll = document.getElementById('wpcf7-entries-select-all');
		if (selectAll) {
			selectAll.addEventListener('change', function() {
				document.querySelectorAll('input[name="entry_ids[]"]').forEach(function(cb) {
					cb.checked = selectAll.checked;
				});
			});
		}

		// Bulk-delete buttons submit the form after setting the action.
		['wpcf7-bulk-delete-top', 'wpcf7-bulk-delete-bottom'].forEach(function(id) {
			var btn = document.getElementById(id);
			if (!btn) return;
			btn.addEventListener('click', function() {
				var checked = document.querySelectorAll('input[name="entry_ids[]"]:checked');
				if (!checked.length) {
					return;
				}
				if (confirm('<?php echo esc_js( __( 'Delete the selected entries? This cannot be undone.', 'contact-form-7' ) ); ?>')) {
					document.getElementById('wpcf7-entries-form').submit();
				}
			});
		});
	})();
	</script>
	<?php
}
