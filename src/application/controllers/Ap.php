<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Meow\ActivityPub\Actor\Actor;
use Meow\ActivityPub\Object\Note;

/**
 * ActivityPub
 * Class Ap
 */
class Ap extends MY_Controller {

    public function __construct () {
        parent::__construct();

	    $this->load->library('ActivityPubService');
    }

	public function index () {
		$this->display('activitypub/index.twig', [
			'enableMeowStartButton' => 0,
		]);
	}

    public function searchUser () {

    	$acct = $this->input->post_get('acct');

    	$me = $this->getMe();

	    if ($acct == '@' || strpos($acct, '@') === false || strpos($acct, ':') !== false){
		    return $this->display('activitypub/userSearch.twig', [
			    'me' => $me,
			    'acct' => $acct,
			    'enableMeowStartButton' => 0,
		    ]);
	    }

	    if ($acct[0] === '@') {
		    $acct = substr($acct, 1);
	    }

	    list($preferredUsername, $host) = explode('@', $acct);

	    // ローカルユーザは検索させない
	    if ($host == Meow::FQDN) {
		    return $this->display('activitypub/userSearch.twig', [
			    'me' => $me,
			    'acct' => $acct,
			    'enableMeowStartButton' => 0,
		    ]);
	    }

	    if (!$preferredUsername || !$host) {
		    return $this->display('activitypub/userSearch.twig', [
			    'me' => $me,
			    'acct' => $acct,
			    'enableMeowStartButton' => 0,
		    ]);
	    }

	    // キャッシュ見る
	    $actor = Actor::get($acct);
	    if ($actor) {
	    } else {
	    	$actor = ActivityPubService::queryActor($host, $preferredUsername);
	    	if (!$actor) {
			    return $this->display('activitypub/userSearch.twig', [
				    'me' => $me,
				    'acct' => $acct,
				    'enableMeowStartButton' => 0,
				    'noResult' => 1,
			    ]);
		    }
	    }

	    // check service
	    $service = \Meow\ActivityPub\Actor\Service::load($actor->host);
	    if (!$service) {
		    \Meow\ActivityPub\Actor\Service::create($actor->host, $actor->content->endpoints[0]);
	    }

	    if (!empty($actor->content->featured)) {
		    $response = ActivityPubService::safe_remote_get($actor->content->featured);
		    $content = $response->getBody()->getContents();
		    $featured = json_decode($content);
	    } else {
		    $featured = [];
	    }

	    $response = ActivityPubService::safe_remote_get($actor->content->outbox . "?page=true");
	    $content = $response->getBody()->getContents();
	    $outbox = json_decode($content);

	    // remote user
	    $remoteUser = RemoteUser::get($actor->content->id);
	    if (!$remoteUser) {
		    RemoteUser::insert($actor->content);
		    $remoteUser = RemoteUser::get($actor->content->id);
	    }

	    // フォロー状況
	    $sql = " select * from follow where user_id = ? and follow_user_id = ? ";
	    $follow = $this->db->query($sql, [$me->id, $remoteUser->id])->row();

    	return $this->display('activitypub/userSearch.twig', [
		    'me' => $me,
    		'acct' => $acct,
		    'actor' => $actor->content,
		    'featured' => ($featured->orderedItems ?? false),
		    'notes' => ($outbox->orderedItems ?? false),
		    'followStatus' => $follow,
		    'enableMeowStartButton' => 0,
	    ]);
    }

	/**
	 * 自分の出したフォローリクエストを取り下げる
	 */
    public function undoFollowRequest () {
	    $me = $this->getMeOrJumpTop();

	    $actor = Actor::get($this->input->post('actor'));
	    if (!$actor) {
		    redirect('/ap/searchUser');
		    return true;
	    }

	    $remoteUser = RemoteUser::get($actor->content->id);

	    // フォロリク存在チェック
	    $sql = " select id from follow where user_id = ? and follow_user_id = ? ";
	    if ($apFollow = $this->db->query($sql, [$me->id, $remoteUser->id])->row()) {
	    	// フォローを無効化
	    	$sql = " delete from follow where id = ? and user_id = ? and follow_user_id = ? ";
	    	$this->db->query($sql, [$apFollow->id, $me->id, $remoteUser->id]);

	    	// Undo投げる
	    	$request = [
			    '@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => Meow::BASE_URL . "/u/{$me->mid}/follow/{$apFollow->id}/undo",
				'type' => 'Undo',
		        'actor' => Meow::BASE_URL . '/u/' . $me->mid,
			    'object' => [
				    "@context" => "https://www.w3.org/ns/activitystreams",
				    "id" => Meow::BASE_URL . "/u/{$me->mid}/follow/{$apFollow->id}",
				    "type" => "Follow",
				    "actor" => Meow::BASE_URL . "/u/" . $me->mid,
				    "object" => $actor->actor,
			    ]
		    ];
	    	$response = ActivityPubService::safe_remote_post($actor->content->inbox, $request, $me->mid);
	    }

	    redirect("/ap/searchUser?acct={$actor->preferred_username}@{$actor->host}");
	    return true;
    }

    public function sendFollowRequest () {
	    $me = $this->getMeOrJumpTop();

	    $actor = Actor::get($this->input->post('actor'));
	    if (!$actor) {
		    redirect('/ap/searchUser');
		    return true;
	    }

	    // リモートユーザ取得
	    $remoteUser = RemoteUser::get($actor->content->id);
	    if (!$remoteUser) {
		    RemoteUser::insert($actor->content);
		    $remoteUser = RemoteUser::get($actor->content->id);
	    }

	    // フォロリク存在チェック
	    $sql = " select id from follow where user_id = ? and follow_user_id = ? ";
	    if ($followId = $this->db->query($sql, [$me->id, $remoteUser->id])->row()) {
		    redirect("/ap/searchUser?acct={$remoteUser->mid}");
		    return true;
	    }

	    $values = [
	    	'user_id' => $me->id,
		    'follow_user_id' => $remoteUser->id,
		    'is_accepted' => 0,
	    ];
	    $this->db->insert('follow', $values);
	    $followId = $this->db->insert_id();

	    $objectId = Meow::BASE_URL . "/u/{$me->mid}/follow/{$followId}";
	    $request = [
			"@context" => "https://www.w3.org/ns/activitystreams",
			"id" => $objectId,
			"type" => "Follow",
			"actor" => Meow::BASE_URL . "/u/" . $me->mid,
			"object" => $actor->actor,
	    ];

	    // 一応自前でもactivity積んどく
	    $values = [
	    	'object_id' => $objectId,
		    'type' => 'Follow',
		    'actor' => Meow::BASE_URL . "/u/" . $me->mid,
		    'object' => json_encode($request, JSON_UNESCAPED_SLASHES),
	    ];
	    $this->db->insert('ap_activity', $values);

	    $response = ActivityPubService::safe_remote_post($actor->content->inbox, $request, $me->mid);

	    redirect("/ap/searchUser?acct={$actor->preferred_username}@{$actor->host}");
	    return true;
    }

    public function deleteMyself () {
    	$me = $this->getMeOrJumpTop();
    	$request = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => Meow::BASE_URL . '/ap/u/k#delete',
		    'type' => 'Delete',
		    'actor' => Meow::BASE_URL . '/ap/u/k',
		    'to' => [
		    	'https://www.w3.org/ns/activitystreams#Public'
		    ],
		    'object' => Meow::BASE_URL . '/ap/u/k',
	    ];
	    $response = ActivityPubService::safe_remote_post('https://pawoo.net/inbox', $request, $me->mid);
	    return $response;
    }

	/**
	 * フォローリクエストに許可を出す
	 */
	public function accept () {
		$me = $this->getMeOrJumpTop();
		$objectId = $this->input->post('object_id');

		// request
		$sql = " select * from inbox where object_id = ? and object = ? ";
		$request = $this->db->query($sql, [$objectId, $me->actor->id])->row();
		if (!$request) {
			redirect('/ap/followRequest');
			return true;
		}
		$request->content = json_decode($request->content);

		// request user
		$actor = Actor::get($request->actor);

		// 知らない人なら取りに行く
		if (!$actor) {

			$url = $request->actor;
			$response = ActivityPubService::safe_remote_get($url);
			if (!$response) {
				redirect('/ap/followRequest');
				return true;
			}

			$json = $response->getBody()->getContents();

			Actor::insert($json);

			$actor = Actor::get($request->actor);
		}

		if (!$actor) {
			print "リモートユーザーの情報がありません<br>\n";
			var_dump($actor);
			exit;
		}

		// remote user
		$remoteUser = RemoteUser::get($actor->content->id);
		if (!$remoteUser) {
			RemoteUser::insert($actor->content);
			$remoteUser = RemoteUser::get($actor->content->id);
		}

		if (is_string($actor->content)) {
			$actor->content = json_decode($actor->content);
		}

		$accept = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'type' => 'Accept',
			'actor' => Meow::BASE_URL . "/u/{$me->mid}",
			'object' => $request->content
		];

		$response = ActivityPubService::safe_remote_post($actor->content->inbox, $accept, $me->mid);

		$statusCode = $response->getStatusCode();
		// accept成功
		if ($statusCode == 200 || $statusCode == 202) {
			// follow request を apply = 0 に
			$sql = " update inbox set apply = 0 where object_id = ? and object = ? ";
			$this->db->query($sql, [$objectId, $me->actor->id]);

			// 既フォローチェック
			$sql = " select id from follow where user_id = ? and follow_user_id = ? ";
			if ($this->db->query($sql, [$remoteUser->id, $me->id])->row()) {
				$sql = " update follow set is_accepted = 1 where user_id = ? and follow_user_id = ? ";
				$this->db->query($sql, [$remoteUser->id, $me->id]);
			} else {
				// 被フォロー追加
				$values = [
					'user_id' => $remoteUser->id,
					'follow_user_id' => $me->id,
					'is_accepted' => 1
				];
				$this->db->insert('follow', $values);
			}

		} else {

			print " follow request accept error : http status code = {$statusCode} ";
			exit;

		}

		redirect('/ap/followRequest');
		return true;
	}

    public function followRequest () {
    	$me = $this->getMeOrJumpTop();
    	$sql = "
    	    select * from inbox
    	    where
    	    	object = ?
    	    	and type = 'Follow'
    	    	and apply = 1
    	    order by create_at desc
    	";
    	if ($rows = $this->db->query($sql, [$me->actor->id])->result()) {
    		foreach ($rows as $i => $row) {
    			$row->content = json_decode($row->content);
    			$rows[$i] = $row;
		    }
	    }
    	$this->display('/activitypub/followRequest.twig', [
    		'followRequests' => $rows,
			'enableMeowStartButton' => 0,
	    ]);
    }

    public function checkDeletedNote () {
		$sql = "
			select *
			from inbox
			where
				type = 'Delete'
				and object not like 'https://%'
		";
		$activities = $this->db->query($sql)->result();
		foreach ($activities as $a) {
			$object = json_decode($a->object);

			print "{$a->id}, {$a->actor} => {$object->id} ";

			// 実在チェック
			$sql = " select * from ap_note where object_id = ? ";
			$apNote = $this->db->query($sql, [$object->id])->row();
			if ($apNote) {
				print "ある（ap_note_id = {$apNote->id}）";

//				$this->deleteNote(json_decode($a->content), $apNote);
				Note::delete(json_decode($a->content), $apNote);

			} else {
				print "ない";
			}

			print "<br>\n";

		}
    }

    public function inbox () {
		$statusCode = ActivityPubService::sharedInbox();
	    http_response_code($statusCode);
    }

    public function processInbox (int $id) {
		$sql = " select * from inbox where id = ? limit 1 ";
		$activity = $this->db->query($sql, [$id])->row();
		$content = json_decode($activity->content);
		ActivityPubService::processInbox($content);
		print "done";
    }

    public function u ($mid, $mode = false) {

    	$user = MeowManager::getUser($mid);
    	if (!$user) {
    		print "error";
    		exit;
	    }

	    if (is_numeric($mode)) {
		    print "note";
		    $this->note($user, $mode);
	    } elseif ($mode == false) {
	    	$this->person($user);
	    }
    }

    public function user_inbox ($mid) {
	    return ActivityPubService::userInbox($mid);
    }

    public function user_outbox ($mid) {
	    print "{$mid}/outbox";
    }

    private function note ($user, $id) {
    	$sql = " select * from meow where id = ? and user_id = ? limit 1 ";
    	$meow = $this->db->query($sql, [$id, $user->id])->row();
    	if (!$meow) {
    		exit;
	    }
	    $values = [
		    '@context' => 'https://www.w3.org/ns/activitystreams',
		    'type' => 'Note',
		    'id' => Meow::BASE_URL . "/p/{$user->mid}/{$id}", // Fediverseで一意
		    'attributedTo' => Meow::BASE_URL . "/u/{$user->mid}", // 投稿者のPerson#id
		    'content' => $meow->text, // XHTMLで記述された投稿内容
		    'published' => str_replace(' ', 'T', $user->create_at) . '+09:00', // ISO形式の投稿日
		    'to' => [ // 公開範囲
			    'https://www.w3.org/ns/activitystreams#Public', // 公開（連合？）
			    // 'https://example.com/test/follower', // フォロワー
		    ]
		];
	    header('Content-Type: application/ld+json; charset=UTF-8');
	    print json_encode($values);
    }

    private function person ($user) {
	    header('Content-Type: application/activity+json; charset=UTF-8');
	    $content = MeowUser::content($user);
	    print json_encode($content);
    }

    public function hostMeta () {
		$this->display('activitypub/host-meta.twig');
    }

    public function webfinger () {

    	$resource = $_GET['resource'] ?? '';
    	if (strpos($resource, ':') === false) {
    		exit;
	    }
    	list($type, $value) = explode(':', $resource);
    	if ($type == 'acct') {
    		if (strpos($value, '@') !== false) {
    			list($mid, $host) = explode('@', $value);
		    } else {
    			$mid = $value;
    			$host = Meow::FQDN;
		    }
		    if ($host != Meow::FQDN) {
		    	exit;
		    }
    		$sql = " select * from user where mid = ? limit 1 ";
    		$user = $this->db->query($sql, [$mid])->row();
    		if (!$user) {
    			exit;
		    }
		    $values = [
			    "subject" => "acct:{$user->mid}@" . Meow::FQDN,
			    "aliases" => [
			    	Meow::BASE_URL . "/u/{$user->mid}",
			    ],
			    "links" => [
			    	[
						"rel" => "http://webfinger.net/rel/profile-page",
						"type" => "text/html",
						"href" => Meow::BASE_URL . "/u/{$user->mid}"
				    ],
			        [
				        "rel" => "self",
			            "type" => "application/activity+json",
			            "href" => Meow::BASE_URL . "/u/{$user->mid}"
			        ],
				    [
				    	'rel' => 'http://schemas.google.com/g/2010#updates-from',
					    "type" => "application/atom+xml",
						"href" => Meow::BASE_URL . "/u/{$user->mid}/atom"
				    ]
			    ]
		    ];
		    header('Content-Type: application/json; charset=UTF-8');
    		print json_encode($values, JSON_UNESCAPED_SLASHES);
	    }
    }


}