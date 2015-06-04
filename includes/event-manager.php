<?php

class WP_Better_HipChat_Event_Manager {

	/**
	 * @var WP_Better_HipChat_Plugin
	 */
	private $plugin;

	/**
	 * @param WP_Better_HipChat_Plugin $plugin
	 */
	public function __construct( WP_Better_HipChat_Plugin $plugin ) {
		$this->plugin = $plugin;

		$this->dispatch_events();
	}

	/**
	 * Foreach active integration setting, send notifications to
	 * HipChat's room whenever actions in events are fired.
	 */
	private function dispatch_events() {

		// Get all integration settings.
		// @todo Adds get_posts method into post type
		// that caches the results.
		$integrations = get_posts( array(
			'post_type'      => $this->plugin->post_type->name,
			'nopaging'       => true,
			'posts_per_page' => -1,
		) );

		foreach ( $integrations as $integration ) {
			$setting = get_post_meta( $integration->ID, 'hipchat_integration_setting', true );

			// Skip if inactive.
			if ( empty( $setting['active'] ) ) {
				continue;
			}
			if ( ! $setting['active'] ) {
				continue;
			}

			if ( empty( $setting['events'] ) ) {
				continue;
			}

			$events = $this->get_events($setting);

			// For each checked event calls the callback, that's,
			// hooking into event's action-name to let notifier
			// deliver notification based on current integration
			// setting.
			foreach ( $setting['events'] as $event => $is_enabled ) {
				if ( ! empty( $events[ $event ] ) && $is_enabled ) {
					$this->notifiy_via_action( $events[ $event ], $setting );
				}
			}

		}
	}

	/**
	 * Get list of events. There's filter `hipchat_get_events`
	 * to extend available events that can be notified to
	 * HipChat's room.
	 *
	 * @return array
	 */
	public function get_events($setting) {

		$notified_post_types = apply_filters( 'hipchat_event_transition_post_status_post_types',
			$setting['types']
		);

		return apply_filters( 'hipchat_get_events', array(
			'post_published'  => array(
				'action'      => 'transition_post_status',
				'description' => __( 'When a post is published', 'better-hipchat' ),
				'default'     => true,
				'colour'      => 'green',
				'message'     => function( $new_status, $old_status, $post ) use ( $notified_post_types ) {
					
					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'publish' !== $old_status && 'publish' === $new_status ) {
						return sprintf(
							'%4$s published: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%3$s</strong>',

							esc_attr( get_permalink( $post->ID ) ),
							esc_html( get_the_title( $post->ID ) ),
							wp_get_current_user()->display_name,
							ucfirst($post->post_type)
						);
					}
				},
			),

			'post_pending_review' => array(
				'action'      => 'transition_post_status',
				'description' => __( 'When a post needs review', 'better-hipchat' ),
				'default'     => false,
				'colour'      => 'yellow',
				'message'     => function( $new_status, $old_status, $post ) use ( $notified_post_types ) {

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'pending' !== $old_status && 'pending' === $new_status ) {
						$excerpt = has_excerpt( $post->ID ) ?
							apply_filters( 'get_the_excerpt', $post->post_excerpt )
							:
							wp_trim_words( strip_shortcodes( $post->post_content ), 55, '&hellip;' );

						return sprintf(
							'%4$s needs review: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%3$s</strong>',

							admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
							get_the_title( $post->ID ),
							wp_get_current_user()->display_name,
							ucfirst($post->post_type)
						);
					}
				},
			),

			'post_deleted' => array(
				'action'      => 'before_delete_post',
				'description' => __('When a post is deleted', 'better-hipchat' ),
				'default'     => false,
				'colour'      => 'red',
				'message'     => function ( $post ) {

					$post = get_post($post);

					// Skip the multiple delete actions
					if ( did_action( 'before_delete_post' ) !== 1 ) {
						return false;
					}

					return sprintf(
						'%3$s deleted: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%4$s</strong>',
						admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
						get_the_title( $post->ID ),
						ucfirst($post->post_type),
						wp_get_current_user()->display_name
					);
				},
			),

			'post_trashed' => array(
				'action'      => 'transition_post_status',
				'description' => __('When a post is moved to the trash', 'better-hipchat' ),
				'default'     => false,
				'colour'      => 'yellow',
				'message'     => function( $new_status, $old_status, $post ) use ( $notified_post_types ) {

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'trash' !== $old_status && 'trash' === $new_status ) {
						return sprintf(
							'%4$s trashed: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%3$s</strong>',
							admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
							get_the_title( $post->ID ),
							wp_get_current_user()->display_name,
							ucfirst($post->post_type)
						);
					}
				},
			),
			
			'post_recovered' => array(
				'action'      => 'post_updated',
				'description' => __('When a is moved out of the trash', 'better-hipchat'),
				'default'     => false,
				'colour'      => 'yellow',
				'message'     => function( $post, $post_after, $post_before ) use ( $notified_post_types ) {
					$post = get_post( $post );

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'trash' === $post_before->post_status && 'trash' !== $post_after->post_status ) {

						return sprintf(
							'%4$s recovered from trash: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%3$s</strong>',
							admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
							get_the_title( $post->ID ),
							wp_get_current_user()->display_name,
							ucfirst($post->post_type)
						);

					}
				},
			),

			'post_updated' => array(
				'action'      => 'post_updated',
				'description' => __('When a post has been edited', 'better-hipchat'),
				'default'     => false,
				'colour'      => 'green',
				'message'     => function( $post, $post_after, $post_before ) use ( $notified_post_types ) {
					$post = get_post( $post );

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					$post_difference = 0;
					similar_text( $post_before->post_content, $post_after->post_content, $post_difference );

					if ( 'trash' === $post_after->post_status ) {
						return false;
					}

					if ( 'trash' !== $post_before->post_status || 'trash' === $post_after->post_status ) {

						return sprintf(
							'%5$s edited: <a href="%1$s"><strong>%2$s</strong></a> by <strong>%3$s</strong>
							<br>
							Text Body Similarity: %4$s%%
							',
							admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
							get_the_title( $post->ID ),
							wp_get_current_user()->display_name,
							floor($post_difference),
							ucfirst($post->post_type)
						);

					}
				},
			),

			'plugin_activated' => array(
				'action'      => 'activated_plugin',
				'description' => __('When a plugin is activated', 'better-hipchat'),
				'default'     => false,
				'colour'      => 'red',
				'message'     => function( $plugin, $network_activation ) {
					return sprintf(
						'Plugin activated by <strong>%1$s</strong>:
						<br>
						<pre>%2$s</pre>
						',
						wp_get_current_user()->display_name,
						$plugin
					);
				},
			),

			'plugin_deactivated' => array(
				'action'      => 'deactivated_plugin',
				'description' => __('When a plugin is deactivated', 'better-hipchat'),
				'default'     => false,
				'colour'      => 'red',
				'message'     => function( $plugin, $network_activation ) {
					return sprintf(
						'Plugin deactivated by <strong>%1$s</strong>:
						<br>
						<pre>%2$s</pre>
						',
						wp_get_current_user()->display_name,
						$plugin
					);
				},
			),

		) );
	}

	/**
	 * Register action's callback.
	 *
	 * @param array $event    A single event of events returned from `$this->get_events`
	 * @param array $setting  Integration setting that's saved as post meta
	 */
	public function notifiy_via_action( array $event, array $setting ) {
		$notifier = $this->plugin->notifier;

		$callback = function() use( $event, $setting, $notifier ) {
			$message = '';
			if ( is_string( $event['message'] ) ) {
				$message = $event['message'];
			} else if ( is_callable( $event['message'] ) ) {
				$message = call_user_func_array( $event['message'], func_get_args() );
			}

			if ( ! empty( $message ) ) {
				$setting['message'] = $message;

				$notifier->notify( new WP_Better_HipChat_Event_Payload( $setting, $event['colour'] ) );
			}
		};
		add_action( $event['action'], $callback, null, 5 );
	}
}
