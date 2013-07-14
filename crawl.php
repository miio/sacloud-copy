<?php
require("rest-client-php/rest_client.php");

class ConnectionAdapters
{
  const API_BASE_URI = "https://secure.sakura.ad.jp/cloud/api/cloud/1.0/";
  const TOKEN = "69901466-e3ca-4358-9c2b-ef4553739b47";
  const SECRET = "U2cAMWMBOWMOpu7deK1D7whWNVvLBgfgvJi6rBYk894nBxIZImDL3eRhJvub9y3I";

  private $api;
  private $headers = array();

  public function __construct() {
    $basic = self::TOKEN . ':' . self::SECRET;
    $this->api = new RestClient(array(
      'httpauth' => $basic,
      'headers' => array(
        'Content-type' => 'application/json'
      ),
    ));
  }

  public function server($params = array()) {
    return $this->api->get(self::API_BASE_URI . "server", $params);
  }
  public function serverid($server_id) {
    return $this->api->get(self::API_BASE_URI . "server/${server_id}", array());
  }

  public function monitor($server_id, $params = array()) {
    return $this->api->get(self::API_BASE_URI . "server/${server_id}/monitor", $params, $this->headers);
  }

  public function powerOn($server_id) {
    return $this->api->put(self::API_BASE_URI . "server/${server_id}/power", '');
  }

  public function productDisk() {
    return $this->api->get(self::API_BASE_URI . "product/disk", array(), $this->headers);
  }
  public function productServer() {
    return $this->api->get(self::API_BASE_URI . "product/server", array(), $this->headers);
  }
  public function createDisk($params) {
    return $this->api->post(self::API_BASE_URI . "disk", json_encode($params), $this->headers);
  }
  public function createServer($params) {
    return $this->api->post(self::API_BASE_URI . "server", json_encode($params), $this->headers);
  }

  public function zone() {
    return $this->api->get(self::API_BASE_URI . "zone", array(), $this->headers);
  }
  public function diskConfig($disk_id, $params) {
    return $this->api->put(self::API_BASE_URI . "disk/${disk_id}/config", json_encode($params), $this->headers);
  }
  public function diskToServer($disk_id, $server_id) {
    return $this->api->put(self::API_BASE_URI . "disk/${disk_id}/to/server/${server_id}", '');
  }
}

class AutoScaler
{
  const SCALE_THRETHOLD = 1;
  const SCALE_DOWN_THRETHOLD = 0.3;
  const SCALE_DEFAULT = 5;
  const SCALE_MAX = 10;
  const SSD_PLAN = 4;

  const LOCALE_ISHIKARI = 31001;

  private static $IP_ADDRS = array(
    '133.242.77.68',
    '133.242.77.69',
    '133.242.77.70',
    '133.242.77.71',
    '133.242.77.72',
    '133.242.77.73',
    '133.242.77.74',
    '133.242.77.75',
    '133.242.77.76',
    '133.242.77.77',
    '133.242.77.78',
  );
  public static function getDateRange() {
    $now = new DateTime();
    $now->modify('-10 minutes');
    $now = $now->format('Y-m-d H:i:s');

    $before = new DateTime();
    $before->modify('-15 minutes');
    $before = $before->format('Y-m-d H:i:s');

    return array('Start' => $before, 'End' => $now);
  }

  public static function getScaleTarget($api) {
    $res = json_decode($api->server()->response)->Servers;
    $target_ids = array();
    $rates = array('num' => 0, 'total_cputime' => 0);
    foreach ($res as $sv) {
      $data = (array)json_decode($api->monitor($sv->ID, self::getDateRange())->response)->Data;

      $before = (array)reset($data);
      $after = (array)end($data);

      $rate = $before['CPU-TIME'] - $after['CPU-TIME'];

      // 負荷が上昇してきてしきい値を超えた
      if ($rate > 0 and $after['CPU_TIME'] >= self::SCALE_THRETHOLD) {
        // 対象IDを格納する
        $target_ids[] = $sv->ID;
        $rates['num'] += $after['CPU_TIME'];
        $rates['total_cputime']++;
      }
    }

    // 新規インスタンス追加直後は負荷が出ない可能性があるので、
    // 最終的なしきい値を超えているか判定することにする
    // 最終的なしきい値を超えていない場合は空配列を返してスケールアウト対象は無いことにする
    //
    // スケール最大数に達した場合も対象は無いことにする
    if (($rates['total_cputime']/$rates['num']) >= self::SCALE_THRETHOLD and $rates['num'] < self::SCALE_MAX) {
      return $target_ids;
    }

    return array();
  }

  public static function executeScale($api, $server_id, $ip_list_id) {
    $data = json_decode($api->serverid($server_id));
    $disk_args = array(
      'Disk' => array(
        'Name' => $server_id . '_' . time(),
        'Zone' => array('ID' => self::LOCALE_ISHIKARI),
        'Plan' => array('ID' => self::SSD_PLAN),
        'SourceDisk' => array('ID' => $data->Server->Disks[0]->ID),
      )
    );
    // ディスクをコピーする
    // ディスクの完了を待つ
    $disk_id = json_decode($api->createDisk($disk_args))->Disk->ID;

    // サーバインスタンスをコピーする
    $instance_args = array(
      'Server' => array(
        'Name' => 'web-' . time(),
        'Zone' => array('ID' => self::LOCALE_ISHIKARI),
        'ServerPlan' => array('ID' => $data->Server->ServerPlan->ID),
        'ConnectedSwitches' => array(array('ID' => $data->Server->Interfaces[0]->Switch->ID))
      )
    );
    $instance_id = json_decode($api->createServer($instance_args))->Server->ID;

    // ディスクの作成を待つ
    self::modifyDisk($api, $disk_id, $ip_list_id);

    // ディスクをアタッチメントする
    $api->diskToServer($disk_id, $instance_id);
    // 電源を入れる
    $api->powerOn($instance_id);
    // スケールアウト後に実行するコマンド
    self::executeAfterScaledCommand();
  }

  public static function executeDownScale() {
    // インスタンスを停止する
    // ディスクを削除する
    // インスタンスを削除する
    // スケールダウンと後に実行するコマンド
    self::executeAfterScaledCommand();
  }

  public static function executeAfterScaledCommand() {
  }

  public static function executeAfterScaleDownCommand() {
  }

  private static function modifyDisk($api, $disk_id, $ip_list_id) {
    try {
      // ディスクの設定をする
      $id = $ip_list_id + 1;
      $disk_configs = array(
        'UserIPAddress' => self::$IP_ADDRS[$id]
      );
      return(json_decode($api->diskConfig($disk_id, $disk_configs)));
    } catch (Exception $e) {
      sleep(10);
      self::modifyDisk($api, $disk_id, $ip_list_id);
    }
  }

}
$c = new ConnectionAdapters();

// TODO 負荷モニタによるスケーリング
//$target = AutoScaler::getScaleTarget($c);
$servers = json_decode($c->server())->Servers;
$scale_by_server_id = $servers[count($servers) - 1]->ID;
// 最初のインスタンスに対してディスク・インスタンスのコピーを開始する
AutoScaler::executeScale($c, $scale_by_server_id, count($servers));
