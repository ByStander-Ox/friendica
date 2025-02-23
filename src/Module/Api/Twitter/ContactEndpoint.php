<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module\Api\Twitter;

use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Profile;
use Friendica\Model\User;
use Friendica\Module\BaseApi;
use Friendica\Model\Contact;
use Friendica\Network\HTTPException;
use Friendica\Util\Strings;

abstract class ContactEndpoint extends BaseApi
{
	const DEFAULT_COUNT = 20;
	const MAX_COUNT = 200;

	public static function init(array $parameters = [])
	{
		parent::init($parameters);

		self::checkAllowedScope(self::SCOPE_READ);
	}

	/**
	 * Computes the uid from the contact_id + screen_name parameters
	 *
	 * @param int|null $contact_id
	 * @param string   $screen_name
	 * @return int
	 * @throws HTTPException\NotFoundException
	 */
	protected static function getUid(int $contact_id = null, string $screen_name = null)
	{
		$uid = self::getCurrentUserID();

		if ($contact_id || $screen_name) {
			// screen_name trumps user_id when both are provided
			if (!$screen_name) {
				$contact = Contact::getById($contact_id, ['nick', 'url']);
				// We don't have the followers of remote accounts so we check for locality
				if (empty($contact) || !Strings::startsWith($contact['url'], DI::baseUrl()->get())) {
					throw new HTTPException\NotFoundException(DI::l10n()->t('Contact not found'));
				}

				$screen_name = $contact['nick'];
			}

			$user = User::getByNickname($screen_name, ['uid']);
			if (empty($user)) {
				throw new HTTPException\NotFoundException(DI::l10n()->t('User not found'));
			}

			$uid = (int)$user['uid'];
		}

		return $uid;
	}

	/**
	 * This methods expands the contact ids into full user objects in an existing result set.
	 *
	 * @param mixed $rel A relationship constant or a list of them
	 * @param int   $uid The local user id we query the contacts from
	 * @param int   $cursor
	 * @param int   $count
	 * @param bool  $skip_status
	 * @param bool  $include_user_entities
	 * @return array
	 * @throws HTTPException\InternalServerErrorException
	 * @throws HTTPException\NotFoundException
	 * @throws \ImagickException
	 */
	protected static function list($rel, int $uid, int $cursor = -1, int $count = self::DEFAULT_COUNT, bool $skip_status = false, bool $include_user_entities = true)
	{
		$return = self::ids($rel, $uid, $cursor, $count);

		$users = [];
		foreach ($return['ids'] as $contactId) {
			$users[] = DI::twitterUser()->createFromContactId($contactId, $uid, $skip_status, $include_user_entities);
		}

		unset($return['ids']);
		$return['users'] = $users;

		$return = [
			'users' => $users,
			'next_cursor' => $return['next_cursor'],
			'next_cursor_str' => $return['next_cursor_str'],
			'previous_cursor' => $return['previous_cursor'],
			'previous_cursor_str' => $return['previous_cursor_str'],
			'total_count' => (int)$return['total_count'],
		];

		return $return;
	}

	/**
	 * @param mixed $rel A relationship constant or a list of them
	 * @param int   $uid The local user id we query the contacts from
	 * @param int   $cursor
	 * @param int   $count
	 * @param bool  $stringify_ids
	 * @return array
	 * @throws HTTPException\NotFoundException
	 */
	protected static function ids($rel, int $uid, int $cursor = -1, int $count = self::DEFAULT_COUNT, bool $stringify_ids = false)
	{
		$hide_friends = false;
		if ($uid != self::getCurrentUserID()) {
			$profile = Profile::getByUID($uid);
			if (empty($profile)) {
				throw new HTTPException\NotFoundException(DI::l10n()->t('Profile not found'));
			}

			$hide_friends = (bool)$profile['hide-friends'];
		}

		$ids = [];
		$next_cursor = 0;
		$previous_cursor = 0;
		$total_count = 0;
		if (!$hide_friends) {
			$condition = [
				'rel' => $rel,
				'uid' => $uid,
				'self' => false,
				'deleted' => false,
				'hidden' => false,
				'archive' => false,
				'pending' => false
			];

			$total_count = (int)DBA::count('contact', $condition);

			$params = ['limit' => $count, 'order' => ['id' => 'ASC']];

			if ($cursor !== -1) {
				if ($cursor > 0) {
					$condition = DBA::mergeConditions($condition, ['`id` > ?', $cursor]);
				} else {
					$condition = DBA::mergeConditions($condition, ['`id` < ?', -$cursor]);
					// Previous page case: we want the items closest to cursor but for that we need to reverse the query order
					$params['order']['id'] = 'DESC';
				}
			}

			$contacts = Contact::selectToArray(['id'], $condition, $params);

			// Previous page case: once we get the relevant items closest to cursor, we need to restore the expected display order
			if ($cursor !== -1 && $cursor <= 0) {
				$contacts = array_reverse($contacts);
			}

			// Contains user-specific contact ids
			$ids = array_column($contacts, 'id');

			// Cursor is on the user-specific contact id since it's the sort field
			if (count($ids)) {
				$previous_cursor = -$ids[0];
				$next_cursor = (int)$ids[count($ids) -1];
			}

			// No next page
			if ($total_count <= count($contacts) || count($contacts) < $count) {
				$next_cursor = 0;
			}
			// End of results
			if ($cursor < 0 && count($contacts) === 0) {
				$next_cursor = -1;
			}

			// No previous page
			if ($cursor === -1) {
				$previous_cursor = 0;
			}

			if ($cursor > 0 && count($contacts) === 0) {
				$previous_cursor = -$cursor;
			}

			if ($cursor < 0 && count($contacts) === 0) {
				$next_cursor = -1;
			}

			// Conversion to public contact ids
			array_walk($ids, function (&$contactId) use ($uid, $stringify_ids) {
				$cdata = Contact::getPublicAndUserContacID($contactId, $uid);
				if ($stringify_ids) {
					$contactId = (string)$cdata['public'];
				} else {
					$contactId = (int)$cdata['public'];
				}
			});
		}

		$return = [
			'ids' => $ids,
			'next_cursor' => $next_cursor,
			'next_cursor_str' => (string)$next_cursor,
			'previous_cursor' => $previous_cursor,
			'previous_cursor_str' => (string)$previous_cursor,
			'total_count' => $total_count,
		];

		return $return;
	}
}
