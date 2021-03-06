<?php
/**
 * Created by PhpStorm.
 * User: maju
 * Date: 2021/05/13
 * Time: 4:54
 */

namespace Meow\ActivityPub\Object;

use Meow\ActivityPub\Actor\Actor;

class ActivityPubObject extends \LibraryBase
{
	public static function load ($objectId) {
		if (intval($objectId)) {
			$sql = " select * from ap_object where id = ? ";
		} else {
			$sql = " select * from ap_object where object_id = ? ";
		}
		return self::db()->query($sql, [$objectId])->row();
	}

	private static function insertObject ($actor, $object) {
		$values = [
			'object_id' => $object->id,
			'actor_id' => $actor->id,
			'type' => $object->type,
			'object' => json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		];
		self::db()->insert('ap_object', $values);
		return self::db()->insert_id();
	}

	public static function create ($object) {

		if (empty($object->id)) {
			return false;
		}
		if (empty($object->attributedTo)) {
			return false;
		}

		$actor = Actor::get($object->attributedTo);
		if (!$actor) {
			return false;
		}

		$apObject = self::load($object->id);
		if ($apObject) {
			$objectRowId = $apObject->id;
		} else {
			$objectRowId = self::insertObject($actor, $object);
		}

		if ($object->type == 'Note') {
			Note::createFromObject($actor, $object);
		}

		return $objectRowId;
	}

	public static function delete ($content) {

		$result = false;

		// object が string で、https:// で始まる actor == object なら delete person
		if (is_string($content->object)
			&& strpos($content->object, 'https://') === 0
			&& $content->actor == $content->object
		) {
			Actor::delete($content->object);
			return true;
		}

		// ap_object にある？
		if ($apObject = self::load($content->object->id)) {

			// 対応するmeow取得
			$sql = " select * from meow where ap_object_id = ? limit 1 ";
			if ($meow = self::db()->query($sql, [$apObject->id])->row()) {

				// meowについたふぁぼ削除
				$sql = " delete from fav where meow_id = ? ";
				self::db()->query($sql, [$meow->id]);

				// meow 削除
				$sql = " delete from meow where id = ? ";
				self::db()->query($sql, [$meow->id]);
			}

			// collection から delete しておく
			$sql = " delete from ap_collection_object where object_id = ? ";
			self::db()->query($sql, [$apObject->id]);

			// ap_object 削除
			$sql = " delete from ap_object where object_id = ? ";
			self::db()->query($sql, [$content->object->id]);

			// inbox 削除
			$sql = " delete from inbox where object_id = ? ";
			self::db()->query($sql, [$content->object->id]);

			$result = true;
		}

		return $result;
	}

	public static function update (\stdClass $content) {
		if ($content->object->type == 'Person') {
			return Actor::update($content->object);
		}
	}
}