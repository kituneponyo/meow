<?php
/**
 * Created by PhpStorm.
 * User: maju
 * Date: 2021/04/25
 * Time: 6:27
 */

namespace Meow\ActivityPub\Actor;

class Actor extends \LibraryBase
{
	public static function create (string $actor, \stdClass $content) {

	}

	public static function getByAcct ($acct) {
		$sql = " select * from ap_actor where host = ? and preferred_username = ? limit 1 ";
		list($preferredUsername, $host) = explode('@', $acct);
		$values = [$host, $preferredUsername];
		return self::db()->query($sql, $values)->row();
	}

	public static function getByActor ($actor) {
		$sql = " select * from ap_actor where actor = ? limit 1 ";
		return self::db()->query($sql, [$actor])->row();
	}

	public static function getByRowId ($id) {
		$sql = " select * from ap_actor where id = ? limit 1 ";
		return self::db()->query($sql, [$id])->row();
	}

	public static function get ($actorId) {

		if (!$actorId) {
			return false;
		}

		$actor = null;
		if (is_numeric($actorId)) {
			// ap_actor.id
			$actor = self::getByRowId($actorId);
		} elseif (strpos($actorId, 'https://') === 0) {
			// actor
			$actor = self::getByActor($actorId);
			if (!$actor) {
				$response = \ActivityPubService::safe_remote_get($actorId);
				if ($content = $response->getBody()->getContents()) {
					self::insert($content);
					$actor = self::getByActor($actorId);
				}
			}
		} else {
			// acct
			$actor = self::getByAcct($actorId);
		}

		if (!$actor) {
			return false;
		}

		$actor->content = json_decode($actor->content);

		// check service
		$service = Service::load($actor->host);
		if (!$service) {
			$sharedInbox = $actor->content->endpoints->sharedInbox ?? '';
			Service::create($actor->host, $sharedInbox);
		}

		// check user
		if ($remoteUser = \RemoteUser::get($actor->content->id)) {
		} else {
			\RemoteUser::insert($actor->content);
		}

		return $actor;
	}

	public static function insert ($content) {
		if (is_string($content)) {
			$content = json_decode($content);
		}
		if (empty($content->preferredUsername)) {
			return false;
		}
		if (parse_url($content->id, PHP_URL_HOST) == \Meow::FQDN) {
			return true;
		}
		$values = [
			'actor' => $content->id,
			'content' => json_encode($content, JSON_UNESCAPED_SLASHES),
			'preferred_username' => $content->preferredUsername,
			'host' => parse_url($content->url, PHP_URL_HOST),
			'update_at' => date('Y-m-d H:i:s')
		];

		self::db()->insert('ap_actor', $values);
		return true;
	}

	public static function update (\stdClass $content) {
		if (is_string($content)) {
			$content = json_decode($content);
		}
		if (empty($content->preferredUsername)) {
			return false;
		}
		$values = [
			'content' => json_encode($content, JSON_UNESCAPED_SLASHES),
			'update_at' => date('Y-m-d H:i:s')
		];
		$actor = self::db()->escape($content->id);
		self::db()->update('ap_actor', $values, " actor = {$actor} ");

		// remote user ???????????????????????????
		\RemoteUser::update($content);
		return true;
	}

	public static function delete (string $actor) {

		if (!$actor) {
			return true;
		}

		// actor ??????
		$sql = " select * from ap_actor where actor = ? limit 1 ";
		$apActor = self::db()->query($sql, [$actor])->row();
		if (!$apActor) {
			return true;
		}

		// remote user ??????
		$sql = " select * from user where actor = ? ";
		$remoteUser = self::db()->query($sql, [$actor])->row();

		// ?????? actor ??? meow ??????????????????????????????
		$sql = "
			delete from fav
			where meow_id in (
				select m.id
				from
					meow m 
					inner join ap_object ao 
						on ao.id = m.ap_object_id
				where ao.actor_id = ?
			)
		";
		self::db()->query($sql, [$apActor->id]);

		// ?????? remote user ???????????????????????????
		if ($remoteUser) {
			$sql = " delete from fav where user_id = ? ";
			self::db()->query($sql, [$remoteUser->id]);
		}

		// ?????? actor ??? meow ?????????
		$sql = "
			delete from meow
			where ap_object_id in (
				select id
				from ap_object ao 
				where ao.actor_id = ?
			)
				and ap_object_id != 0 /* ???????????? */
		";
		self::db()->query($sql, [$apActor->id]);

		// ?????? actor ??? remote user ?????????
		$sql = "
				delete from user 
				where
					actor = ? 
					and actor != '' /* ???????????? */
			";
		self::db()->query($sql, [$actor]);

		// ?????? actor ?????????
		$sql = " delete from ap_actor where actor = ? ";
		self::db()->query($sql, [$actor]);

		// ?????? actor ??? delete ????????? inbox ?????????
		$sql = " delete from inbox where actor = ? and not (type = 'Delete' and object = ?) ";
		self::db()->query($sql, [$actor, $actor]);

		return true;
	}
}