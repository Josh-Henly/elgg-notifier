<?php
/**
 * Notifier
 *
 * @package Notifier
 */

elgg_register_event_handler('init', 'system', 'notifier_init');

function notifier_init () {
	notifier_set_view_listener();

	// Add hidden popup module to topbar
	elgg_extend_view('page/elements/topbar', 'notifier/popup');

	// Register the notifier's JavaScript
	$notifier_js = elgg_get_simplecache_url('js', 'notifier/notifier');
	elgg_register_simplecache_view('js/notifier/notifier');
	elgg_register_js('elgg.notifier', $notifier_js);
	elgg_load_js('elgg.notifier');

	// Must always have lightbox loaded because views needing it come via AJAX
	elgg_load_js('lightbox');
	elgg_load_css('lightbox');

	elgg_register_page_handler('notifier', 'notifier_page_handler');

	// Add css
	elgg_extend_view('css/elgg', 'notifier/css');

	elgg_register_notification_method('notifier');
	elgg_register_plugin_hook_handler('send', 'notification:notifier', 'notifier_notification_send');

	// Notifications for likes
	elgg_register_notification_event('annotation', 'likes', array('create'));
	elgg_register_plugin_hook_handler('prepare', 'notification:create:annotation:likes', 'notifier_prepare_likes_notification');
	// Notifications about new friends
	elgg_register_notification_event('relationship', 'friend', array('create'));
	elgg_register_plugin_hook_handler('prepare', 'notification:create:relationship:friend', 'notifier_prepare_friend_notification');
	elgg_register_plugin_hook_handler('route', 'friendsof', 'notifier_read_friends_notification');

	// Hook handler for cron that removes old messages
	elgg_register_plugin_hook_handler('cron', 'daily', 'notifier_cron');
	elgg_register_plugin_hook_handler('register', 'menu:topbar', 'notifier_topbar_menu_setup');
	//elgg_register_plugin_hook_handler('action', 'friends/add', 'notifier_friend_notifications');

	//elgg_register_event_handler('create', 'annotation', 'notifier_annotation_notifications');
	elgg_register_event_handler('create', 'user', 'notifier_enable_for_new_user');
	elgg_register_event_handler('join', 'group', 'notifier_enable_for_new_group_member');

	$action_path = elgg_get_plugins_path() . 'notifier/actions/notifier/';
	elgg_register_action('notifier/dismiss', $action_path . 'dismiss.php');
	elgg_register_action('notifier/clear', $action_path . 'clear.php');
	elgg_register_action('notifier/delete', $action_path . 'delete.php');
}

/**
 * Add notifier icon to topbar menu
 *
 * The menu item opens a popup module defined in view notifier/popup
 */
function notifier_topbar_menu_setup ($hook, $type, $return, $params) {
	if (elgg_is_logged_in()) {
		// Get amount of unread notifications
		$count = (int)notifier_count_unread();

		$text = '<span class="elgg-icon elgg-icon-attention"></span>';
		$tooltip = elgg_echo("notifier:unreadcount", array($count));

		if ($count > 0) {
			if ($count > 99) {
				// Don't allow the counter to grow endlessly
				$count = '99+';
			}
			$text .= "<span id=\"notifier-new\">$count</span>";
		}

		$item = ElggMenuItem::factory(array(
			'name' => 'notifier',
			'href' => '#notifier-popup',
			'text' => $text,
			'priority' => 600,
			'title' => $tooltip,
			'rel' => 'popup',
			'id' => 'notifier-popup-link'
		));

		$return[] = $item;
	}

	return $return;
}

/**
 * Dispatches notifier pages
 *
 * URLs take the form of
 *  All notifications:          notifier/all
 *  Subjects of a notification: notifier/subjects/<notification guid>
 *
 * @param  array $page
 * @return bool
 */
function notifier_page_handler ($page) {
	gatekeeper();

	if (empty($page[0])) {
		$page[0] = 'all';
	}

	$path = elgg_get_plugins_path() . 'notifier/pages/notifier/';

	switch ($page[0]) {
		case 'popup':
			include_once($path . 'popup.php');
			break;
		case 'subjects':
			set_input('guid', $page[1]);
			include_once($path . 'subjects.php');
			break;
		case 'all':
		default:
			include_once($path . 'list.php');
			break;
	}

	return true;
}

/**
 * Create a notification
 *
 * @param string $hook   Hook name
 * @param string $type   Hook type
 * @param bool   $result Has the notification been sent
 * @param array  $params Hook parameters
 */
function notifier_notification_send($hook, $type, $result, $params) {
	$notification = $params['notification'];
	$event = $params['event'];

	if (!$event) {
		// Plugin is calling notify_user() so stop here and let
		// the NotificationService handle the notification later.
		return false;
	}

	$ia = elgg_set_ignore_access(true);

	$object = $event->getObject();
	$string = "river:create:{$object->getType()}:{$object->getSubtype()}";
	$recipient = $notification->getRecipient();
	$actor = $event->getActor();
	switch ($object->getType()) {
		case 'annotation':
			// Get the entity that was annotated
			$entity = $object->getEntity();
			break;
		case 'relationship':
			$entity = get_entity($object->guid_two);
			break;
		default:
			if ($object instanceof ElggComment) {
				// Use comment's container as notification target
				$entity = $object->getContainerEntity();
				$string = "river:comment:{$entity->getType()}:{$entity->getSubtype()}";

				// TODO How about discussion replies?
			} else {
				// This covers all other entities
				$entity = $object;
			}
	}

	if ($object->getType() == 'annotation' || $object->getType() == 'relationship' || $object instanceof ElggComment) {
		// Check if similar notification already exists
		$existing = notifier_get_similar($event->getDescription(), $entity, $recipient);
		if ($existing) {
			// Update the existing notification
			$existing->setSubject($actor);
			$existing->markUnread();
			return $existing->save();

			// TODO Update time_created?
		}
	}

	// Use summary string if river string is not available
	if ($string == elgg_echo($string) && !empty($notification->summary)) {
		$string = $notification->summary;
	}

	$note = new ElggNotification();
	$note->title = $string;
	$note->owner_guid = $recipient->getGUID();
	$note->container_guid = $recipient->getGUID();
	$note->event = $event->getDescription();

	if ($note->save()) {
		$note->setSubject($actor);
		$note->setTarget($entity);
	}

	elgg_set_ignore_access($ia);

	if ($note) {
		return true;
	}
}

/**
 * Get the count of all unread notifications
 *
 * @return integer
 */
function notifier_count_unread () {
	return notifier_get_unread(array('count' => true));
}

/**
 * Get all unread messages for logged in users
 *
 * @param  array $options Options passed to elgg_get_entities_from_metadata
 * @return ElggNotification[]|null
 */
function notifier_get_unread ($options = array()) {
	$defaults = array(
		'type' => 'object',
		'subtype' => 'notification',
		'limit' => false,
		'owner_guid' => elgg_get_logged_in_user_guid(),
		'metadata_name_value_pairs' => array(
			'name' => 'status',
			'value' => 'unread'
		)
	);

	$options = array_merge($defaults, $options);

	return elgg_get_entities_from_metadata($options);
}

/**
 * Notify when user is added as someone's friend
 */
function notifier_friend_notifications ($hook, $type, $return, $params) {
	$friend_guid = get_input('friend');
	$user_guid = elgg_get_logged_in_user_guid();

	if ($friend_guid) {
		// Having the logged in user as target is illogical but this way
		// we can search by target_guid in view notifier/view_listener
		notifier_add_notification(array(
			'title' => 'friend:newfriend:subject',
			'user_guid' => $friend_guid,
			'target_guid' => $user_guid,
			'subject_guid' => $user_guid,
		));
	}

	return $return;
}

/**
 * Handle annotation notifications
 *
 * @param string         $event
 * @param string         $type
 * @param ElggAnnotation $annotation
 * @return boolean
 */
function notifier_annotation_notifications($event, $type, $annotation) {
	$supported_types = array('generic_comment', 'group_topic_post', 'likes', 'messageboard');

	if (!in_array($annotation->name, $supported_types)) {
		return true;
	}

	$entity = $annotation->getEntity();
	$owner_guid = $entity->getOwnerGUID();

	$subject_guid = $annotation->owner_guid;

	// Do not notify about own annotations
	if ($subject_guid != $owner_guid) {
		// Check if user has enabled notifier for personal notifications
		$metadata = elgg_get_metadata(array(
			'metadata_name' => 'notification:method:notifier',
			'guid' => $owner_guid
		));

		if (!empty($metadata[0]->value)) {
			$target_guid = $entity->getGUID();

			switch ($annotation->name) {
				case 'likes':
					$title = 'likes:notifications:subject';
					break;
				case 'messageboard':
					$title = 'river:messageboard:user:default';
					$target_guid = $owner_guid;
					break;
				default:
					// We'll assume it's a comment
					$type = $entity->getType();
					$subtype = $entity->getSubtype();
					$title = "river:comment:$type:$subtype";
					break;
			}

			notifier_add_notification(array(
				'title' => $title,
				'user_guid' => $owner_guid,
				'target_guid' => $target_guid,
				'subject_guid' => $subject_guid
			));
		}
	}

	notifier_handle_mentions($annotation, 'annotation');

	notifier_handle_group_topic_replies($annotation);

	return TRUE;
}

/**
 * Create a notification for each @username tag
 *
 * @param object $object The content that was created
 * @param string $type   Type of content (annotation|object)
 */
function notifier_handle_mentions ($object, $type) {
	// This feature requires the mentions plugin
	if (!elgg_is_active_plugin('mentions')) {
		return false;
	}

	global $CONFIG;

	if ($type == 'annotation' && $object->name != 'generic_comment' && $object->name != 'group_topic_post') {
		return NULL;
	}

	// excludes messages - otherwise an endless loop of notifications occur!
	if ($object->getSubtype() == "messages") {
		return NULL;
	}

	$fields = array(
		'name', 'title', 'description', 'value'
	);

	// store the guids of notified users so they only get one notification per creation event
	$notified_guids = array();

	foreach ($fields as $field) {
		$content = $object->$field;
		// it's ok in in this case if 0 matches == FALSE
		if (preg_match_all(mentions_get_regex(), $content, $matches)) {
			// match against the 2nd index since the first is everything
			foreach ($matches[1] as $username) {

				if (!$user = get_user_by_username($username)) {
					continue;
				}

				if ($type == 'annotation') {
					if ($parent = get_entity($object->entity_guid)) {
						$access = has_access_to_entity($parent, $user);
						$target_guid = $parent->getGUID();
					} else {
						continue;
					}
				} else {
					$access = has_access_to_entity($object, $user);
					$target_guid = $object->getGUID();
				}

				// Override access
				// @todo What does the has_access_to_entity() do?
				$access = true;

				if ($user && $access && !in_array($user->getGUID(), $notified_guids)) {
					// if they haven't set the notification status default to sending.
					$notification_setting = elgg_get_plugin_user_setting('notify', $user->getGUID(), 'mentions');

					if (!$notification_setting && $notification_setting !== FALSE) {
						$notified_guids[] = $user->getGUID();
						continue;
					}

					// @todo Is there need to know what the type of the target is?
					$type_key = "mentions:notification_types:$type";
					if ($subtype = $object->getSubtype()) {
						$type_key .= ":$subtype";
					}
					$type_str = elgg_echo($type_key);

					$title = 'mentions:notification:subject';

					notifier_add_notification(array(
						'title' => $title,
						'user_guid' => $user->guid,
						'target_guid' => $target_guid,
						'subject_guid' => $object->owner_guid
					));
				}
			}
		}
	}
}

/**
 * Create notifications of group discussion replies.
 *
 * Notify all group members who have subscribed to group notifications
 * using notifier as a notification method.
 *
 * @param object $reply The reply that was posted
 */
function notifier_handle_group_topic_replies ($reply) {
	if ($reply->name != 'group_topic_post') {
		return false;
	}

	$topic = $reply->getEntity();

	$interested_users = elgg_get_entities_from_relationship(array(
		'relationship' => 'notifynotifier',
		'relationship_guid' => $topic->getContainerGUID(),
		'inverse_relationship' => true,
		'type' => 'user',
		'limit' => 0,
	));

	if ($interested_users) {
		foreach ($interested_users as $user) {
			// Do not notify the user who posted the reply
			if ($user->getGUID() == elgg_get_logged_in_user_guid()) {
				continue;
			}

			// The owner of the topic has already been notified
			if ($user->getGUID() == $topic->getOwnerGUID()) {
				continue;
			}

			notifier_add_notification(array(
				'title' => 'river:reply:object:groupforumtopic',
				'user_guid' => $user->getGUID(),
				'target_guid' => $topic->getGUID(),
				'subject_guid' => $reply->owner_guid
			));
		}
	}
}

/**
 * Add a new notification if similar not already exists
 *
 * @uses int $options['user_guid']    GUID of the user being notified
 * @uses int $options['target_guid']  GUID of the entity being acted on
 * @uses int $options['subject_guid'] GUID of the user acting on target
 * @uses string $options['title']     Translation string of the action
 */
function notifier_add_notification ($options) {
	$user_guid = $options['user_guid'];
	$target_guid = $options['target_guid'];
	$subject_guid = $options['subject_guid'];
	$title = $options['title'];

	$db_prefix = elgg_get_config('dbprefix');
	$ia = elgg_set_ignore_access(true);

	// Check if the same notification already exists
	$notifiers = elgg_get_entities_from_metadata(array(
		'type' => 'object',
		'subtype' => 'notification',
		'owner_guid' => $user_guid,
		'joins' => array(
			"JOIN {$db_prefix}objects_entity oe ON e.guid = oe.guid"
		),
		'wheres' => array("title = '$title'"),
		'metadata_name_value_pairs' => array(
			array(
				'name' => 'target_guid',
				'value' => $target_guid,
			),
			array(
				'name' => 'subject_guid',
				'value' => $subject_guid
			),
			array(
				'name' => 'status',
				'value' => 'unread',
			)
		),
	));

	if (empty($notifiers)) {
		$notification = new ElggNotification();
		$notification->title = $title;
		$notification->owner_guid = $user_guid;
		$notification->container_guid = $user_guid;
		$notification->setSubject($subject);
		$notification->setTarget($target_guid);
		$notification->save();
	}

	elgg_set_ignore_access($ia);
}

/**
 * Remove over week old notifications that have been read
 *
 * @param string $hook "cron"
 * @param string $
 *
 */
function notifier_cron ($hook, $entity_type, $return, $params) {
	// One week ago
	$time = time() - 60 * 60 * 24 * 7;

	$options = array(
		'type' => 'object',
		'subtype' => 'notification',
		'wheres' => array("e.time_created < $time"),
		'metadata_name_value_pairs' => array(
			'name' => 'status',
			'value' => 'read'
		),
		'limit' => false
	);

	$ia = elgg_set_ignore_access(true);
	$notifications = elgg_get_entities_from_metadata($options);

	$options['count'] = true;
	$count = elgg_get_entities_from_metadata($options);

	foreach ($notifications as $notification) {
		$notification->delete();
	}

	echo "<p>Removed $count notifications.</p>";

	elgg_set_ignore_access($ia);
}

/**
 * Add view listener to views that may be the targets of notifications
 */
function notifier_set_view_listener () {
	$dbprefix = elgg_get_config('dbprefix');
	$types = get_data("SELECT * FROM {$dbprefix}entity_subtypes");

	// These subtypes do not have notifications so they can be skipped
	$skip = array(
		'plugin',
		'widget',
		'admin_notice',
		'notification',
		'messages',
		'reported_content',
		'site_notification'
	);

	foreach ($types as $type) {
		if (in_array($type->subtype, $skip)) {
			continue;
		}

	    elgg_extend_view("object/{$type->subtype}", 'notifier/view_listener');
	}

	// Some manual additions
	elgg_extend_view('profile/wrapper', 'notifier/view_listener');
}

/**
 * Enable notifier by default for new users according to plugin settings.
 *
 * We do this using 'create, user' event instead of 'register, user' plugin
 * hook so that it affects also users created by an admin.
 *
 * @param  string   $event 'create'
 * @param  string   $type  'user'
 * @param  ElggUser $user
 * @return boolean
 */
function notifier_enable_for_new_user ($event, $type, $user) {
	$personal = (boolean) elgg_get_plugin_setting('enable_personal', 'notifier');
	$collections = (boolean) elgg_get_plugin_setting('enable_collections', 'notifier');

	if ($personal) {
		$prefix = "notification:method:notifier";
		$user->$prefix = true;
	}

	if ($collections) {
		/**
		 * This function is triggered before invite code is checked so it's
		 * enough just to add the setting. Notifications plugin will take care
		 * of adding the 'notifynotifier' relationship in case user was invited.
		 */
		$user->collections_notifications_preferences_notifier = '-1';
	}

	$user->save();

	return true;
}

/**
 * Enable notifier as notification method when joining a group.
 *
 * @param string $event  'join'
 * @param string $type   'group'
 * @param array  $params Array containing ElggUser and ElggGroup
 */
function notifier_enable_for_new_group_member ($event, $type, $params) {
	$group = $params['group'];
	$user = $params['user'];

	$enabled = (boolean) elgg_get_plugin_setting('enable_groups', 'notifier');

	if ($enabled) {
		if (elgg_instanceof($group, 'group') && elgg_instanceof($user, 'user')) {
			add_entity_relationship($user->guid, 'notifynotifier', $group->guid);
		}
	}
}

/**
 * Get existing notifications that match the given parameters.
 *
 * This can be used when we want to update an old notification.
 * E.g. "A likes X" and "B likes X" become "A and B like X".
 *
 * @param  string                $event_name String like "action:type:subtype"
 * @param  ElggEntity            $entity     Entity being notified about
 * @param  ElggUser              $recipient  User being notified
 * @return ElggNotification|null
 */
function notifier_get_similar($event_name, $entity, $recipient) {
	$db_prefix = elgg_get_config('dbprefix');
	$ia = elgg_set_ignore_access(true);

	// Notification (guid_one) has relationship 'hasObject' to target (guid_two)
	$options = array(
		'type' => 'object',
		'subtype' => 'notification',
		'owner_guid' => $recipient->guid,
		'metadata_name_value_pairs' => array(
			'name' => 'event',
			'value' => $event_name,
		),
		'joins' => array(
			"JOIN {$db_prefix}entity_relationships er ON e.guid = er.guid_one", // Object relationship
		),
		'wheres' => array(
			"er.guid_two = {$entity->guid}",
			"er.relationship = 'hasObject'", // TODO use constant
		),
	);

	$notification = elgg_get_entities_from_metadata($options);

	if ($notification) {
		$notification = $notification[0];
	}

	elgg_set_ignore_access($ia);

	return $notification;
}

/**
 * Prepare a notification message about a new like
 *
 * @param  string                          $hook         Hook name
 * @param  string                          $type         Hook type
 * @param  Elgg_Notifications_Notification $notification The notification to prepare
 * @param  array                           $params       Hook parameters
 * @return Elgg_Notifications_Notification
 */
function notifier_prepare_likes_notification($hook, $type, $notification, $params) {
	$annotation = $params['event']->getObject();
	$entity = $annotation->getEntity();
	$owner = $params['event']->getActor();
	$recipient = $params['recipient'];
	$language = $params['language'];
	$method = $params['method'];
	$site = elgg_get_site_entity();

	$notification->subject = elgg_echo('likes:notifications:subject', array($entity->title), $language);
	$notification->body = elgg_echo('likes:notifications:body', array(
		$recipient->name,
		$owner->name,
		$entity->title,
		$site->name,
		$entity->getURL(),
		$owner->getURL(),
	), $language);
	$notification->summary = 'likes:notifications:summary';

	return $notification;
}

/**
 * Prepare notification message about a new friend
 *
 * @param  string                          $hook         Hook name
 * @param  string                          $type         Hook type
 * @param  Elgg_Notifications_Notification $notification The notification to prepare
 * @param  array                           $params       Hook parameters
 * @return Elgg_Notifications_Notification
 */
function notifier_prepare_friend_notification($hook, $type, $notification, $params) {
	$relationship = $params['event']->getObject();
	$actor = $params['event']->getActor();
	$language = $params['language'];

	$notification->subject = elgg_echo('friend:newfriend:subject', array($actor->name), $language);
	$notification->body = elgg_echo('friend:newfriend:body', array(
		$actor->name,
		$actor->getURL(),
	), $language);
	$notification->summary = 'friend:notifications:summary';

	return $notification;
}

/**
 * Mark unread friend notifications as read.
 *
 * This hook is triggered when user goes to the "friendsof/<username>" page.
 *
 * @param string $hook
 * @param string $type
 * @param array  $return
 * @param array  $params
 */
function notifier_read_friends_notification ($hook, $type, $return, $params) {
	// Get unread notifications that match the friending event
	$options = array(
		'metadata_name_value_pairs' => array(
			'name' => 'event',
			'value' => 'create:relationship:friend',
		)
	);

	$notifications = notifier_get_unread($options);

	foreach ($notifications as $note) {
		$note->markRead();
	}
}
