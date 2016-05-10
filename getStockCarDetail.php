<?php
/**
 * DBのデータをiPhoneアプリにJSON形式で返すプログラム
 * 物件詳細ページ
 *
 * オプション追加対応に伴い、新規プログラムとして追加
 *
 * http://www.goo-net.com/php/api/getStockCarDetail.php
 *
 * @package usedcarAPI
 * @author  uno
 * @version 1.3
 * @since   2014-06-XX
 */

require_once ('/usr/goonet/HTTP_PHP/lib/common.php');                // 共通設定
require_once ('/usr/goonet/HTTP_PHP/lib/Db.php');                    // DB関係
require_once ('/usr/goonet/HTTP_PHP/lib/Sanitizing.php');            // サニタイズ関係
require_once ('/usr/goonet/HTTP_PHP/lib/Logger.php');                // ログ関係
require_once ('/usr/goonet/HTTP_PHP/lib/search/common_search.php');  // 中古車検索遷移共通
require_once ('/usr/goonet/HTTP_PHP/lib/search/common_shop.php');    // 店舗画像用
require_once ('/usr/goonet/HTTP_PHP/lib/search/Parameter.php');      // 検索用パラメータ管理
require_once ('/usr/goonet/HTTP_PHP/lib/search/Search.php');         // 検索用関数
require_once ('/usr/goonet/HTTP_PHP/lib/subphoto/TT_SubPhoto.php');  // 複数画像関係
require_once ('/usr/goonet/HTTP_PHP/lib/search/Shop.php');           // 店舗用
require_once ('/usr/goonet/HTTP_PHP/lib/api/Api.php');               // API共通
require_once ('/usr/goonet/HTTP_PHP/lib/api/Zaiko.php');             // API Zaiko共通

$Sanitizing   = new Sanitizing();
$Logger       = new Logger("StockCarDetail");
$Param        = new Parameter();
$Search       = new Search();
$tt_sub_photo = new TT_SubPhoto();
$Shop         = new Shop();
$Api          = new Api();
$Zaiko        = new Zaiko();

$Logger->setLogFilePath("/usr/LOG/api_stock.log.daily");

$data = $_GET;

if (!_validate($data, $Logger)) {
    exit;
}

$Param->allSetter($data);

$goo_car_id = isset($data['goo_car_id']) ? $data['goo_car_id'] : '';
$stock_id   = isset($data['stock_id'])   ? $data['stock_id']   : '';

//baitaiがカラで、gooworldからの遷移とき
if ($data['baitai'] == '' && strstr($_SERVER['HTTP_HOST'],"gooworld.jp")) {
    $Param->setBaitai('goow_iphone');         // 媒体
} else {
    $Param->setBaitai($data['baitai']);       // 媒体
}

//APIVerを指定

$apiVer = 0;
if (isset($data["ver"])) {
    if (is_numeric($data["ver"]) && 0 < $data["ver"]) {
        $apiVer = $data["ver"];
    } else {
        $apiVer = 0;
    }
} else {
    $apiVer = 0;
}
$Zaiko->setDetailApiVer($apiVer);

$query = _getQuery($Sanitizing, $goo_car_id, $stock_id);

if ($query == "") {
    $Logger->error("クエリ文生成エラーです。");
    _error(1);
    exit;
}

// DB 接続関連
// DBクラスのインスタンス生成
$db = new Db();

// DB接続
if ($db->connect(GOO_ALL) == -1) {
    $Logger->error("DBコネクションエラーです。GOO_ALL");
    _error(1);
    exit;
}

// クエリ発行
$result = $db->query($query);

$Data = '';
if ($result == -1) {
    $Logger->error("クエリエラーです。");
    _error(1);
    exit;
} else {
    // 結果セットのカウント数を取得
    $count = pg_num_rows($result);

    if ($count == 0) {
        $Logger->error("物件が存在しませんでした。goo_car_id = $goo_car_id , stock_id = $stock_id");
        _error(1);
        exit;
    } else {
        $Data = pg_fetch_object($result, 0);
    }
}

// 結果セットを開放
pg_free_result($result);

//エリアコードを格納
$Data->area_cd = $Search->getPrefToArea($Data->pre_c);

// 排気量に単位(cc/L)を追加
// REの場合はそのまま
if ($Data->exhaust_nm == 'RE' || $Data->exhaust_nm == 'EV') {
// L追加
} elseif (strstr($Data->exhaust_nm, '.')) {
// L -> cc に変換
    $Data->exhaust_nm = intval($Data->exhaust_nm*1000).'cc';
// cc追加
} else {
    $Data->exhaust_nm = $Data->exhaust_nm.'cc';
}
//燃料の参照をz.nenryo → z.n_fuel_cd に変更 add 2009/10/27 tsuchiya
if ($Data->n_fuel_cd == '6') {
    $Data->exhaust_nm = $Data->exhaust_nm.'(D)';
}

// 走行距離の調整
$Data->distance = _editDistance ($Data->distance);

// 複数画像データを取得
$Data->sub_photo->photo_obj   = _getPhotoData($Data, $tt_sub_photo);    // 画像
$Data->sub_photo->comment_obj = _getPhotoComment($db, $Data->stock_id); // コメント
// 実際のに表示する複数画像表示データ
$ii=0;
for ($i=0; $i < 20; $i++) {
    $key_p = 'photo_P_'.$i;
    $key_c = 'img_text'.$i;
    if ($Data->sub_photo->photo_obj->sub_src_b[$i] != '') {
        if ($Data->sub_photo->comment_obj->$key_c == '') {
            $comment[$ii] = '拡大して見る';
        } else {
            $comment[$ii] = $Data->sub_photo->comment_obj->$key_c;
        }
        $ii++;
    }
}
$Data->sub_photo->subphoto_comment = $comment;

// 表示用車台番号を取得
$Data->disp_navi_code = $Search->getDispCarNo($Data->navi_code);

// Goo鑑定保証加盟店アイコン出力
$Data->guarantee_shop_flg = 0;

// Goo保証処理
// goo_hosyou_planのデータと別で契約状態のデータを保持する
$Data->goo_hosyou_plan_flg = 0;
if ($Data->goo_hosyou_plan > 0) {
    $Data->goo_hosyou_plan_flg = 1;

    $o_goo_hosyou_plan = $Search->getGooHosyouPlan($db, $Data->goo_hosyou_plan);
    foreach ($o_goo_hosyou_plan as $key => $value) {
        $Data->$key = $value;
    }

}

// Goo保証契約店舗かチェック
$Data->goo_hosyou_plan_shop_flg = 0;

// 見積りリンクデータの取得
$Data->estimate = $Search->getEstimateData($db, $Sanitizing, $Data, $Param->getBaitai(), 1);

// getShopUrlでデータを取得
$Data->o_hp_data = $Shop->getShopUrl($db, $Sanitizing, $Data->owner_cd, $baitai);

// 購入パック情報取得
$Data->purchase_pack = $Search->getPurchasePackQuery($db, $Sanitizing, $Data->stock_id);


// 現行販売車情報取得
$Data->current_car_flg = $Search->getCurrentCarData($db, $Sanitizing, $Data->n_car_cd, $Data->n_p_grade_id, $Data->n_p_katashiki_id);

//動画情報の取得
if ($Zaiko->getDetailApiVer() > 0) {

    $movie_data = array();
    $movie_exists_flg = 0;

    //項目だけは事前に用意しておく
    if ($Data->movie_cnt > 0) {
       $movie_data = $Api->getMovieData($db, $Data->stock_id, $Data->goo_car_id, $Data->movie_cnt);
       if (count($movie_data) > 0 ) {
            $movie_exists_flg = 1;
       }else{
            $movie_data       = array();
            $movie_exists_flg = 0;
       }
    }
    $Data->movie_data       = $movie_data;
    $Data->movie_exists_flg = intval($movie_exists_flg);
}

// 輸入認定中古車フラグの取得
if ($Data->sub_photo_2 == 1 && ($Data->n_brand_cd > 2000 || $Data->n_brand_cd == 1005)) {
    $Data->import_certified_car_flg = 1;
} else {
    $Data->import_certified_car_flg = 0;
}

// gooクーポン ver=3から
if ($Zaiko->getDetailApiVer() >= 3) {
    if ($Data->stock_id != '' && $Data->goopon != '') {
        // クーポン詳細情報の取得
        $goopon = $Search->getGooponData($db, $Sanitizing, $Data->stock_id, $Data->goopon);

        // クーポン開始日の整形
        if ($goopon->coupon_s_date != '') {
            if ($goopon->coupon_s_date == "1900-01-01") {
                $Data->goopon_start_date = "";
            } else {
                // 日付の調整(date->2012-02-02)
                $Data->goopon_start_date = date('Y/n/j' ,strtotime($goopon->coupon_s_date));
            }
        }

        // クーポン終了日の整形
        if ($goopon->coupon_e_date != '') {
            if ($goopon->coupon_e_date == "9999-12-31") {
                $Data->goopon_end_date = "";
            } else {
                // 日付の調整(date->2012-02-02)
                $Data->goopon_end_date = date('Y/n/j' ,strtotime($goopon->coupon_e_date));
            }
        }

        if ($goopon->img_name != '' || $goopon->img_name != NULL) {
            $Data->goopon_img = 'http://picture2.goo-net.com/goocoupon/L/'.$goopon->img_name;
        }

        $Data->goopon_name = $goopon->coupon_name;
        $Data->goopon_comment = $goopon->coupon_comment;
    }
}


// 来店クーポン ver=3から
if ($Zaiko->getDetailApiVer() >= 3) {
    $shop_intro_sub_info = $Search->getShopIntroSubInfoData($db, $Sanitizing, $Data->owner_cd);
    if ($shop_intro_sub_info != "") {
        $Data->coupon_name       = $shop_intro_sub_info->visit_coupon_name;
        $Data->coupon_title      = $shop_intro_sub_info->visit_coupon_title;
        $Data->coupon_start_date = $shop_intro_sub_info->visit_coupon_term_s;
        $Data->coupon_end_date   = $shop_intro_sub_info->visit_coupon_term_e;
        $Data->coupon_text       = $shop_intro_sub_info->visit_coupon_text;
        if ($shop_intro_sub_info->visit_coupon_img != "") {
            $Data->coupon_img = sprintf(SHOP_IMG_URL, substr($Data->owner_cd, 0, 3), $Data->owner_cd).$shop_intro_sub_info->visit_coupon_img;
        }
    } else {
        $Data->coupon_name       = "";
        $Data->coupon_title      = "";
        $Data->coupon_start_date = "";
        $Data->coupon_end_date   = "";
        $Data->coupon_text       = "";
        $Data->coupon_img        = "";
    }
}

// DBクラス解放
$db->close();

// カタログID関係
$Data->cat_id = "";

if (!is_null($Data->n_cat_id) && trim($Data->n_cat_id) != '') {
    $ShinshaDB = new Db();
    if ($ShinshaDB->connect(SHINSHA) == -1) {
        $Logger->error("DBコネクションエラーです。SHINSHA");
        _error(1);
        exit;
    }

    $result = $ShinshaDB->query("SELECT cat_id FROM catalog_master WHERE cat_id = ".$Data->n_cat_id);
    if ($result != -1) {
        if (pg_num_rows($result) > 0) {
            $Data->cat_id = $Data->n_cat_id;
        }
        pg_free_result($result);
    }
    $ShinshaDB->close();
}

$Zaiko->setZaikoData($Data);

// アイコンデータの取得(一文字づつに分解して、配列化)
$option_values_ary = array();
$length = strlen($Data->option_values);
for ($i=0; $i<$length; $i++) {
    $option_values_ary[$i] = substr ($Data->option_values, $i, 1);
}
$Zaiko->setOptionValues($option_values_ary);

$a_option_values_web = array();
$length = strlen($Data->option_values_web);
for ($i=0; $i<$length; $i++) {
    $a_option_values_web[$i] = substr ($Data->option_values_web, $i, 1);
}

$Zaiko->setOptionValuesWeb($a_option_values_web);

if ($Zaiko->getDetailApiVer() > 0){
    $a_option_values_2 = array();
    $length = strlen($Data->option_values_2);
    for ($i=0; $i<$length; $i++) {
        $a_option_values_2[$i] = substr ($Data->option_values_2, $i, 1);
    }
    $Zaiko->setOptionValues2($a_option_values_2);
}

// 整備/保証用表示文字列を取得
// 整備
$Data->seibi_str  = $Search->getSeibiStr($Data->real_seibi, $Data->p_seibi_price);
// 保証
$Data->hosyou_str = $Search->getHosyouStr($Data->real_hosyou,
                                          $Data->hosyou_kikan_flg, $Data->hosyou_kigen_flg, $Data->hosyou_kyori_flg,
                                          $Data->p_hosyou_kikan, $Data->p_hosyou_kigen, $Data->p_hosyou_kyori,
                                          1) ;
// 送るデータを整形
_setSendItem($option_values_ary, $a_option_values_web, $Data, $Send_data, $Api, $Zaiko, $Search);

// 出力
_output($Send_data);

exit;


/**
 * クエリ生成
 *
 * @access public
 * @param  &$Sanitizing   : サニタイジングクラスリファレンス
 * @param  $area_cd      : エリアコード
 * @param  $goo_car_id   : goo_car_id
 * @return $ret_sql  : SQL文
 */
function _getQuery(&$Sanitizing, $goo_car_id, $stock_id) {

    $ret_sql = 'SELECT '.
                   'z.absyear, '.
                   'z.syear, '.
                   'z.n_p_car_nm_n AS car_name, '.
                   'z.exhaust_nm, '.
                   'z.n_p_grade_nm_n AS grade_name, '.
                   'z.distance, '.
                   'z.inspect AS syaken, '.
                   'z.color_nm, '.
                   'z.n_p_brand_nm_n AS brand_name, '.
                   'c.owner_nm, '.
                   'c.owner_addr1, '.
                   'c.owner_addr2, '.
                   'c.tel, '.
                   'c.fax, '.
                   'z.goo_car_id, '.
                   'z.n_cat_id, '.
                   'z.nenryo, '.
                   'z.n_fuel_cd, '.
                   'z.pre_c, '.
                   'z.price, '.
                   'z.goopon, '.
                   'z.stock_id, '.
                   'z.option_values, '.
                   'z.option_values_web, '.
                   'z.option_values_2, '.
                   'z.repair_flag, '.
                   'z.seibi AS real_seibi, '.
                   'z.hosyou AS real_hosyou, '.
                   " (CASE WHEN z.seibi  = '1' THEN '1' ELSE '0' END) AS seibi, ".
                   " (CASE WHEN z.hosyou = '1' THEN '1' ELSE '0' END) AS hosyou, ".
                   'z.upload_date, '.
                   'z.movie_cnt, '.
                   'z.m_flg, '.
                   'z.n_brand_cd, '.
                   'z.n_p_door_n, '.
                   'z.fukabun, '.
                   'z.catch_string, '.
                   'z.catchcopy, '.
                   'z.n_car_cd, '.
                   'z.price_jpn, '.
                   'z.sub_photo_num, '.
                   'z.navi_code, '.
                   'z.recycle_flg, '.
                   'z.recycle_add_flg, '.
                   'z.kantei_syatai_id, '.
                   'z.kantei_naisou_id, '.
                   'z.kantei_kikan_id, '.
                   'z.kantei_kokkaku_id, '.
                   'z.hosyou_flg,  '.
                   'z.goo_hosyou_plan,  '.
                   'n_m_option, ' .
                   'c.yubin,  '.
                   'z.total_price, '.
                   'z.repair_comment, '.
                   'z.sub_photo_4, '.
                   'z.owner_cd, '.
                   'c.shop_url, '.
                   'z.assurance_comment, '.
                   'z.repair_comment, '.
                   'z.p_seibi_price, '.
                   'z.hosyou_kikan_flg, '.
                   'z.hosyou_kigen_flg, '.
                   'z.hosyou_kyori_flg, '.
                   'z.p_hosyou_kikan, '.
                   'z.p_hosyou_kigen, '.
                   'z.p_hosyou_kyori, '.
                   'z.other_guarantee, '. //新規追加項目
                   'z.sub_photo_2, '.     //認定中古車フラグ
                   'z.n_p_katashiki_id, '.
                   'z.n_p_grade_id, '.
                   "z.auto_reply_flg, ".
                   "wg.geo[0] AS ido, ".
                   "wg.geo[1] AS keido, ".
                   "call.phone_no AS callnote, ".
                   " (CASE WHEN z.hosyou_flg = 2 AND z.goo_plus_flg = '1' THEN '1' ELSE '0' END) AS goo_plus, ".
                   "z.kantei_date, ".
                   "z.goo_warranty, ".
                   "z.hosyou_item_count, ".
                   "z.hosyou_item_comments, ".
                   "z.repair_upper_limit, ".
                   "z.repair_count, ".
                   "z.hosyou_limit_upper_limit, ".
                   "z.hosyou_limit_comments, ".
                   "z.deductible, ".
                   "z.deductible_comments, ".
                   "z.hosyou_repair_receptionist, ".
                   "z.road_service, ".
                   "z.keiro_id, ".
                   "CASE WHEN EXISTS(SELECT * FROM campaign_table ct WHERE z.stock_id = ct.stock_id) THEN 1 ELSE 0 END as goo_campaign_flg, ".
                   "(CASE WHEN z.loan_flg = 3 AND NOT EXISTS (SELECT m_spli.client_id FROM m_spli WHERE m_spli.client_id = z.owner_cd AND m_spli.orico_flg = 1) THEN 0 ELSE loan_flg END) AS loan_flg, ".
                   "(CASE WHEN EXISTS ( SELECT oem_reject.client_id FROM oem_reject_table oem_reject WHERE z.owner_cd = oem_reject.client_id ) THEN 1 ELSE 0 END ) AS oem_reject_flg, ".
                   "(CASE WHEN EXISTS(SELECT * FROM messenger_reject_table mrt WHERE z.owner_cd = mrt.client_id) THEN 1 ELSE 0 END) AS messenger_reject_flg ".
               'FROM client c, ((all_zaiko z '.
               "LEFT JOIN client_world_geodetic wg ON z.owner_cd = wg.client_id) ".
               "LEFT JOIN callnote call ON call.client_id = z.owner_cd) ".
               "WHERE z.owner_cd = c.owner_cd";

    if ($goo_car_id != "") {
        $ret_sql .= " AND z.goo_car_id = '".$Sanitizing->SanitizingSQL($goo_car_id)."'";
    }

    if ($stock_id != "") {
        $ret_sql .= " AND z.stock_id = '".$Sanitizing->SanitizingSQL($stock_id)."'";
    }

    $ret_sql .= " LIMIT 1";

    return $ret_sql;
}

/**
 * Goo鑑定保証提携店チェック
 *
 * @access public
 * @param  &$db      : goo_allへのコネクトクラス
 * @param  $owner_cd : クライアントコード
 * @return $ret_bool : Goo鑑定保証提携店かどうか true/false
 */
function _checkGuaranteeShop(&$db, $owner_cd) {
    $ret_bool = false;

    $sql = "SELECT owner_cd FROM ".COUNT_CLIENT_ZAIKO_GUARANTEE_TABLE_NAME." WHERE owner_cd = '".$owner_cd."'";
    $res = $db->query($sql);
    if (-1 === $res) {
    } else {
        // 結果セットのカウントを取得
        if (pg_num_rows($res) > 0) {
            $ret_bool = true;
        }
    }

    return $ret_bool;
}

/**
 * 複数画像のコメントを取得
 *
 * @access public
 * @param  &$db      : goo_allへのコネクトクラス
 * @param  $stock_id : stock_id
 * @return $ret_obj  : 取得したオブジェクトデータ
 */
function _getPhotoComment(&$db, $stock_id) {

    $ret_obj = '';

    // 契約データをチェック
    $sql = 'SELECT '.
               'img_text1, '.
               'img_text2, '.
               'img_text3, '.
               'img_text4, '.
               'img_text5, '.
               'img_text6, '.
               'img_text7, '.
               'img_text8, '.
               'img_text9, '.
               'img_text10, '.
               'img_text11, '.
               'img_text12, '.
               'img_text13, '.
               'img_text14, '.
               'img_text15, '.
               'img_text16, '.
               'img_text17, '.
               'img_text18, '.
               'img_text19 '.
           'FROM photo_comment '.
           "WHERE stock_id = '".$stock_id."'";

    $res = $db->query($sql);
    if (-1 === $res) {
        // 結果セットを開放
        pg_free_result($res);
        return $ret_obj;
    } else {
        // 結果セットのカウントを取得
        if (pg_num_rows($res) > 0) {
            $ret_obj = pg_fetch_object($res, 0);

            // 各カラムに値が入っていなかったら、空を入れる add 2010/01/08 rabe
            foreach ($ret_obj as $key => $img_text) {
                if ($img_text != NULL) {
                    $ret_obj->$key = $img_text;
                } else {
                    $ret_obj->$key = "";
                }
            }
        }
    }
    // 結果セットを開放
    pg_free_result($res);

    return $ret_obj;
}

/**
 * 画像取得処理取りまとめ
 *
 * @access public
 * @param  &$data   : 結果セットのオブジェクトデータ(stock_idを使用)
 * @return $ret_url : 画像URL
 */
function _getPhotoData(&$data, &$tt_sub_photo) {
    $ret_obj = '';

    if ( isset($data->oem_reject_flg ) && ($data->oem_reject_flg) ) {
        // oem非表示店舗の場合メイン画像のみ
        $sub_photo_data = $data->oem_reject_flg;
    } else {
        // stock_id(goo_car_id)から複数画像bitデータを取得 add 2010/01/06 rabe
        $sub_photo_data = $tt_sub_photo->getSubphotoTTData($data->stock_id, $data->goo_car_id);
    }

    // 大/小の画像のURLを取得 add 2010/01/08 rabe
    $a_type = array('J', 'P');
    foreach ($a_type as $type) {
        //$img_num_ary = array();
        for ($i = 0 ; $i < strlen($sub_photo_data) ; $i++) {
            $key = $i;
            if (substr($sub_photo_data, $i, 1)) {
                ($i==0) ? $id = $data->goo_car_id : $id = $data->stock_id;
                if ($type == 'J'){
                    $ret_obj->sub_src_b[$key] = _getPhotoUrl($id, $type, $i);
                    $ii++;
                } else {
                    $ret_obj->sub_src[$key] = _getPhotoUrl($id, $type, $i);
                }
            }
        }
    }

    // 画像数を取得
    $ret_obj->img_num_ary = $img_num_ary;

    return $ret_obj;
}

/**
 * 画像URLを取得
 *
 * @access public
 * @param  $id             : goo_car_id or stock_id
 * @param  $image_size_flg : 画像サイズフラグデータ
 * @param  $photo_num  : サブ画像フラグ
 * @return $ret_url : 画像URL
 */
function _getPhotoUrl($id, $image_size_flg, $photo_num) {

    $ret_url = '';

    // メイン画像
    if ($photo_num == 0) {
        $dir[0] = substr($id,  0, 10) ;
        $dir[1] = substr($id, 10,  8) ;

    // 複数画像
    } else {
        $dir[0] = substr($id, 0, 3) ;
        $dir[1] = substr($id, 0, 7) ;
    }

    $photo_num = sprintf("%02d",$photo_num);

    $img_flg = substr ($image_size_flg, 0, 1) ;

    $ext = '';    // 拡張子が格納
    // P:プレビュー(P2はサムネイル）J:拡大 Q:在庫カセット
    if ($img_flg == 'P' || $img_flg == 'J' || $img_flg == 'Q') {
        $ext = '.jpg';

    // iモード用 (gif)
    } elseif ($img_flg == 'I') {
        $ext = '.gif';

    // Ez,J-Sky用 (png)
    } elseif ($img_flg == 'A') {
        $ext = '.png';

    } else {
        $ret_url = IMG_CAR_NOPHOTO;
    }

    if ($ret_url == '') {
        $ret_url = 'http://'.PICTURE_DOMAIN.'/'.$dir[0].'/'.$dir[1].'/'.$image_size_flg.'/'.$id.$photo_num.$ext;
    }

    return $ret_url;
}

/**
 * 走行距離データを調整
 *
 * @access public
 * @param  $distance : 走行距離データ
 * @return $ret_str  : 調整された走行距離データ
 */
function _editDistance ($distance){

    $ret_str = '';

    // 調整なし
    if ($distance == '改ざん車'   ||
        $distance == 'メータ交換' ) {
        $ret_str = $distance;

    // 走不明
    } elseif ($distance == '走不明'   ||
               $distance == '走行不明') {
        $ret_str = '走不明';

    // 空
    } elseif ($distance == '') {
        $ret_str = '-----';

    // 整数値が1000以上の場合
    } elseif (intval($distance) >= 1000 ) {
        if(strstr( $distance , "万K")){
            $ret_str = $distance;

        } elseif (strstr( $distance , "K") ) {
            $ret_str = number_format(intval($distance)).'km';

        } else {
            $ret_str = number_format(intval($distance)).'m';

        }

    } else {
        $ret_str = $distance.'m';
    }

    return $ret_str;
}

/**
 * JSON形式で送るデータを整形する。
 *
 * @access public
 * @param  $option_values_ary     : option_values のデータ配列
 * @param  $a_option_values_web   : option_values_web のデータ配列
 * @param  $data                  : 結果セットのオブジェクトデータ
 */
function _setSendItem($option_values_ary, $a_option_values_web, &$data, &$send_data, &$Api ,$Zaiko, $Search) {

    $send_data->stock_id         = $Api->convertNull(strval($data->stock_id));
    $send_data->goo_car_id       = $Api->convertNull(strval($data->goo_car_id));
    $send_data->area_cd          = intval($data->area_cd);
    $send_data->pre_c            = intval($data->pre_c);

    //複数画像関連データ
    $send_data->subphoto_comment = _getArrData($data->sub_photo->subphoto_comment);
    $send_data->sub_src          = _getArrData($data->sub_photo->photo_obj->sub_src_b);
    $send_data->sub_src_b        = _getArrData($data->sub_photo->photo_obj->sub_src_b);

    $send_data->brand_name       = $Api->convertNull(strval($data->brand_name));
    $send_data->car_name         = $Api->convertNull(strval($data->car_name));
    $send_data->grade_name       = $Api->convertNull(strval($data->grade_name));

    if ($data->upload_date) {
        $send_data->upload_date = $data->upload_date.'更新';
    } else {
        $send_data->upload_date = '';
    }

    if ($data->price_jpn == 'ASK') {
        $send_data->price_jpn = 'ASK';
    } elseif ($data->price > 10000) {
        $send_data->price_jpn = floatval($data->price / 10000) . '万円';
    } else {
        $send_data->price_jpn = number_format(floatval($data->price / 10000), '1') . '万円';
    }

    if ($data->total_price) {
        $send_data->total_price = $data->total_price.'万円';
    } else {
        $send_data->total_price = '';
    }

    //消費税表示
    if ($data->sub_photo_4 == '1') {
        $send_data->tax = '消費税込';
    } elseif ($data->sub_photo_4 == '99') {
        $send_data->tax = '消費税非課税';
    } else {
        $send_data->tax = '消費税別';
    }

    //リサイクル
    $send_data->recycle = '';
    if ($data->recycle_flg == 0) {
        if ($data->recycle_add_flg == 1) {
            $send_data->recycle = 'リ・追';
        }
    } elseif ($data->recycle_flg == 1) {
        if ($data->recycle_add_flg == 0) {
            $send_data->recycle = 'リ未';
        } elseif ($data->recycle_add_flg == 1) {
            $send_data->recycle = 'リ未・追';
        }
    } elseif ($data->recycle_flg == 2) {
        if ($data->recycle_add_flg == 0) {
            $send_data->recycle = 'リ済別';
        } elseif ($data->recycle_add_flg == 1) {
            $send_data->recycle = 'リ済別・追';
        }
    } elseif ($data->recycle_flg == 3) {
        if ($data->recycle_add_flg == 0) {
            $send_data->recycle = 'リ済込';
        } elseif ($data->recycle_add_flg == 1) {
            $send_data->recycle = 'リ済込・追';
        }
    } elseif ($data->recycle_flg == 4) {
        if ($data->recycle_add_flg == 0) {
            $send_data->recycle = 'リ未';
        } elseif ($data->recycle_add_flg == 1) {
            $send_data->recycle = 'リ未・追';
        }
    }

    // 年式
    if ($data->n_brand_cd <= 2000) {
        $send_data->nenshiki   = $Api->convertNull(strval($data->absyear));
    } else {
        if ($data->syear != "") {
            if ($data->syear== '9999') {
                $send_data->nenshiki   = "新車";
            } else {
                $send_data->nenshiki   = $data->syear."年";
            }
        } else {
            $send_data->nenshiki   = '';
        }
    }

    $send_data->syaken     = $Api->convertNull(strval($data->syaken));

    if ($data->color_nm == '2') {
        $send_data->color = 'ツートン';
    } else {
        $send_data->color = $Api->convertNull(strval($data->color_nm));
    }
    $send_data->distance  = strval($data->distance);
    $send_data->exhaust   = strval($data->exhaust_nm);

    if ($data->estimate != '' && $data->estimate != '0') {
        $send_data->estimate_flg = 1;
        $send_data->soldout_flg  = 0;
    } elseif ($data->estimate == '') {
        $send_data->estimate_flg = 0;
        $send_data->soldout_flg  = 0;
    } else {
        //---バージョンアップまで対応--- add 2010/05/13 tsuchiya
        $send_data->estimate_flg = 0;
        $send_data->soldout_flg  = 1; //売約済み
    }

    //見積り
    switch (substr($data->m_flg, 0, 1)) {
        // Gazoo
        case 'T' :
            $send_data->baitai_flag= 'gazoo';
            break;
        // powerstage
        case 'C' :
            $send_data->baitai_flag = 'pws';
            break;
        // MJ四国
        case 'A' :
            $send_data->baitai_flag = 'mjs';
            break;
        // くるまる
        case 'K' :
            $send_data->baitai_flag = 'kurumaru';
            break;
        // asoccar
        case 'N' :
            $send_data->baitai_flag = 'asoccar';
            break;
        default :
            // canget
            if (strlen($data->m_flg) == 2 && substr($data->m_flg, 1, 1) == '1') {
                $send_data->baitai_flag = 'canget';
            } else {
                $send_data->baitai_flag = ''; // ボタン未表示
            }
    }

    // バージョンアップまでに対応 1だけ渡す add 2010/05/28 tsuchiya
    // バージョンアップしたら戻す
    if ($data->seibi != 0) {
        $send_data->seibi                   = intval($data->seibi);
    } else {
        $send_data->seibi                   = 0;
    }

    // バージョンアップまでに対応 1だけ渡す add 2010/05/28 tsuchiya
    // バージョンアップしたら戻す
    if ($data->hosyou != 0) {
        $send_data->hosyou                  = intval($data->hosyou);
    } else {
        $send_data->hosyou                  = 0;
    }

    // 整備/保証
    $send_data->seibi_str         = $Api->convertNull(strval($data->seibi_str));
    $send_data->hosyou_str        = $Api->convertNull(strval($data->hosyou_str));
    $send_data->assurance_comment = $Api->convertNull(strval($data->assurance_comment));
    $send_data->repair_comment    = $Api->convertNull(strval($data->repair_comment));

    // goo保証
    $send_data->goo_hosyou_plan_flg = intval($data->goo_hosyou_plan_flg);

    // Goo保証
    $send_data->goo_hosyou_plan     = intval($data->goo_hosyou_plan);
    // プラン名
    $send_data->plan_name           = $Api->convertNull(strval($data->plan_name));
    // 免責
    if ($send_data->goo_hosyou_plan_flg != 1) {
        $send_data->menseki         = strval('');
    } elseif ($data->menseki_flg == '1') {
        $send_data->menseki         = strval('免責金あり');
    } else {
        $send_data->menseki         = strval('免責金なし');
    }

    // 保証年数
    $send_data->hosyou_years        = $Api->convertNull(strval($data->hosyou_years));

    // 保証項目
    if ($data->hosyou_range == '999') {
        $send_data->hosyou_range    = strval('無制限');
    } else {
        $send_data->hosyou_range    = $Api->convertNull(strval($data->hosyou_range));
    }

    // WEBコーリング　店舗番号
    $send_data->webcalling = "";

    //修復歴
    if ($data->repair_flag == 2) {
        $send_data->repair_flag = 1;     //あり
    } else {
        $send_data->repair_flag = 0;     //なし
    }

    //Goo保証認定
    $send_data->hosyou_flg        = intval($data->hosyou_flg);
    $send_data->kantei_syatai_id  = intval($data->kantei_syatai_id);
    $send_data->kantei_naisou_id  = intval($data->kantei_naisou_id);
    // 1以外は0で返す修正 add 2010/08/19 tsuchiya
    // 機関
    if ($data->kantei_kikan_id != 1) {
        $send_data->kantei_kikan_id = 0;
    } else {
        $send_data->kantei_kikan_id = 1;
    }
    // 骨格
    if ($data->kantei_kokkaku_id != 1) {
        $send_data->kantei_kokkaku_id = 0;
    } else {
        $send_data->kantei_kokkaku_id = 1;
    }

    //表示文言で返す add 2010/08/19 tsuchiya
    // 機関
    if ($data->kantei_kikan_id == 1) {
        $send_data->kantei_kikan = '正常';
    } elseif ($data->kantei_kikan_id == 2) {
        $send_data->kantei_kikan = '要整備';
    } else {
        $send_data->kantei_kikan = '';
    }
    // 骨格
    if ($data->kantei_kokkaku_id == 1) {
        $send_data->kantei_kokkaku = '無';
    } elseif ($data->kantei_kokkaku_id == 2) {
        $send_data->kantei_kokkaku = '有　軽度';
    } elseif ($data->kantei_kokkaku_id == 3) {
        $send_data->kantei_kokkaku = '有　中度';
    } elseif ($data->kantei_kokkaku_id == 4) {
        $send_data->kantei_kokkaku = '有　重度';
    } else {
        $send_data->kantei_kokkaku = '';
    }
    // 鑑定日
    $send_data->kantei_date = strval($data->kantei_date);

    //------オプション項目---------
    $send_data->kirokubo       = intval($option_values_ary[51]); // 記録簿
    $send_data->one_owner      = intval($option_values_ary[49]);  //ワンオーナー
    $send_data->nonsmoke       = intval($option_values_ary[50]);  //禁煙車
    $send_data->non_used       = intval($option_values_ary[63]);  //未使用車

    if ($Zaiko->getDetailApiVer() <= 0) {
        $send_data->delear         = intval($option_values_ary[8]);   //ディーラー車 スペル違いなので、二つ出す。
    }
    $send_data->dealer         = intval($option_values_ary[8]);   //ディーラー車
    $send_data->leaser         = intval($option_values_ary[22]);  //革シート
    $send_data->power_seat     = intval($option_values_ary[61]);  //パワーシート
    $send_data->row_seat       = intval($option_values_ary[41]);  //3列シート
    $send_data->bench_seat     = intval($option_values_ary[47]);  //ベンチシート
    $send_data->full_flat      = intval($option_values_ary[48]);  //フルフラット
    $send_data->fullflat_seat = intval($option_values_ary[48]);  //フルフラット
    $send_data->ecocar         = intval($a_option_values_web[30]);//エコカー減税対象車

    //ミッション
    $send_data->mission = $Zaiko->getMissionWording();

    //ハンドル
    if ($option_values_ary[10] == '1') {
        $send_data->handle     = '左';
    } else {
        $send_data->handle     = '右';
    }
    $send_data->wd = intval($option_values_ary[17]); //4WD

    // 使用燃料
    $send_data->fuel = $Zaiko->getFuelWording();

    //乗務定員
    $send_data->max = $Zaiko->getMaxWording();

    //ウォークスルー
    $send_data->walk_through = intval($option_values_ary[46]);

    //ナビ
    $send_data->navi = $Zaiko->getNaviWording();

    // TV
    $send_data->tv = $Zaiko->getTvWording();

    // TVナビ
    $objTvNavi = "";
    $objTvNavi = $Zaiko->getTvNaviWording();
    if (!empty($objTvNavi)) {
        if (isset($objTvNavi->text) && isset($objTvNavi->value)) {
            $send_data->tv_navi->text  =  $objTvNavi->text;
            $send_data->tv_navi->value =  intval($objTvNavi->value);
        }
    }
    //MD/MDプレイヤー
    if ($Zaiko->getMdParam() != -1) {
        $send_data->md              = intval($Zaiko->getMdParam());
        $send_data->md_player       = intval($Zaiko->getMdParam());  //MD/MDチェンジャー スペル違いなので、二つ出す。
    }

    //CD/CDプレイヤー
    if ($Zaiko->getCdParam() != -1) {
        $send_data->cd              = intval($Zaiko->getCdParam());
        $send_data->cd_player       = intval($Zaiko->getCdParam());  //CD/CDチェンジャー  スペル違いなので、二つ出す。
    }

    //カセット
    if ($Zaiko->getCassetteParam() != -1) {
        $send_data->cassette        = intval($Zaiko->getCassetteParam());  //カセット
    }

    //バックモニター(旧ver用)
    if ($Zaiko->getBackMonitorParam() != -1) {
        $send_data->backmoniter     = intval($Zaiko->getBackMonitorParam());  //バックモニター
    }

    //バックカメラ
    if ($Zaiko->getBackCameraParam() != -1) {
        $send_data->backcamera      = intval($Zaiko->getBackCameraParam());  //バックカメラ
    }

    //フル装備
    if ($Zaiko->getFullEquipmentParam() != -1) {
        $send_data->full_equipment  = intval($Zaiko->getFullEquipmentParam());  //フル装備
    }

    //エアコン
    if ($option_values_ary[12] == '1' || $option_values_ary[13] == '1') {
        $send_data->aircon      = 1;
    } else {
        $send_data->aircon      = 0;
    }

    //Ｗエアコン
    $send_data->waircon      = intval($option_values_ary[60]);

    //パワーステアリング
    if ($option_values_ary[12] == '1' || $option_values_ary[14] == '1') {
        $send_data->powersteering      = 1;
    } else {
        $send_data->powersteering      = 0;
    }

    //パワーウィンドウ
    if ($option_values_ary[12] == '1' || $option_values_ary[15] == '1') {
        $send_data->powerwindow      = 1;
    } else {
        $send_data->powerwindow      = 0;
    }

    //スライドドア
    if ($option_values_ary[42] == '1') {
        $send_data->slidedoor = '片面';
    } elseif ($option_values_ary[43] == '1') {
        $send_data->slidedoor = '両面';
    } elseif ($option_values_ary[44] == '1') {
        $send_data->slidedoor = '電動';
    } elseif ($option_values_ary[45] == '1') {
        $send_data->slidedoor = '両面電動';
    } else {
        $send_data->slidedoor = '-';
    }

    $send_data->roof            = intval($option_values_ary[21]);   //サンルーフ

    //ハイブリッドカー
    if ($Zaiko->getHybridCarParam() != -1){
        $send_data->hybridcar  = intval($Zaiko->getHybridCarParam());  //フル装備
    }

    $send_data->welfare         = intval($option_values_ary[0]);    //福祉車両
    $send_data->kanreichisiyou  = intval($option_values_ary[16]);   //寒冷地仕様
    $send_data->campingcar      = intval($a_option_values_web[29]); //キャンピングカー
    $send_data->etc             = intval($a_option_values_web[24]); //ETC
    $send_data->keyless         = intval($option_values_ary[54]);   //キーレスエントリー
    $send_data->smartkey        = intval($a_option_values_web[26]); //スマートキー
    $send_data->hid             = intval($a_option_values_web[23]); //HID（キセノンライト）

    // アルミホイール
    $send_data->alumi_wheel     = $Zaiko->getAlumiWheelWording();

    $send_data->fullaero     = intval($option_values_ary[52]);//フルエアロ
    $send_data->lowdown      = intval($option_values_ary[53]);//ローダウン
    $send_data->abs          = intval($option_values_ary[58]);//ABS
    $send_data->esc          = intval($a_option_values_web[27]);//横滑り防止装置
    $send_data->airbag       = $Zaiko->getAirbagParam();//エアバッグ
    $send_data->antitheft    = intval($option_values_ary[62]);//盗難防止システム

    $send_data->sheetheater              = intval($a_option_values_web[35]);//シートヒーター
    $send_data->automatic_cruise_control = intval($a_option_values_web[32]);//オートクルーズコントロール

    //ターボチャージャー
    if ($Zaiko->getTurboChargerParam() != -1) {
        $send_data->turbo         = intval($Zaiko->getTurboChargerParam());
    }

    //スーパーチャージャー
    if ($Zaiko->getSuperChargerParam() != -1) {
        $send_data->supercharger  = intval($Zaiko->getSuperChargerParam());
    }

    //電気自動車
    if ($Zaiko->getEvParam() != -1) {
        $send_data->ev            = intval($Zaiko->getEvParam());
    }

    if ($data->option_values_web) {
        $send_data->option_values_web = 1;
    } else {
        $send_data->option_values_web = 0;
    }
    //------//オプション項目---------

    $send_data->navi_code  = strval($data->disp_navi_code);//車台番号
    $send_data->n_m_option = intval($data->n_m_option);//新着フラグ
    $send_data->door_nm    = $Api->convertNull(strval($data->n_p_door_n));//ドア
    $send_data->fukabun    = $Api->convertNull(strval($data->fukabun));//付加文
    $send_data->pr_comment = '';
    if ($data->catch_string != '') {
       $send_data->pr_comment = strval($data->catch_string);
    } elseif ($data->catchcopy != '' ) {
       $send_data->pr_comment = strval($data->catchcopy);
    }

    //クライアント情報
    $send_data->owner_cd    = $Api->convertNull(strval($data->owner_cd));
    $send_data->owner_nm    = $Api->convertNull(strval($data->owner_nm));
    $send_data->yubin       = $Api->convertNull(strval($data->yubin));
    $send_data->owner_addr1 = $Api->convertNull(strval($data->owner_addr1));
    $send_data->owner_addr2 = $Api->convertNull(strval($data->owner_addr2));
    $send_data->tel         = $Api->convertNull(strval($data->tel));
    $send_data->fax         = $Api->convertNull(strval($data->fax));
    $send_data->shop_url    = $Api->convertNull(strval($data->o_hp_data->url));

    //緯度経度を求める
    $send_data->keido = '0';
    $send_data->ido   = '0';
    if ($data->ido != '' && $data->keido!= '')  {
        $send_data->keido = floatval($data->keido);
        $send_data->ido = floatval($data->ido);
    }

    $send_data->n_brand_cd = $Api->convertNull($data->n_brand_cd);
    $send_data->n_car_cd   = $Api->convertNull($data->n_car_cd);

    //追加オプション

    //最新モデル (現行型)
    if ($Zaiko->getNewModelParam() != -1) {
        $send_data->new_model  = intval($Zaiko->getNewModelParam());
    }

    //輸入認定中古車
    if ($Zaiko->getImportNinteiCarParam() != -1) {
        $send_data->import_nintei_car  = intval($Zaiko->getImportNinteiCarParam());
    }

    //リフトアップ
    if ($Zaiko->getLiftUpParam() != -1) {
        $send_data->lift_up  = intval($Zaiko->getLiftUpParam());
    }

    //アイドリングストップ
    if ($Zaiko->getNoIdlingParam() != -1) {
        $send_data->noidling  = intval($Zaiko->getNoIdlingParam());
    }

    //衝突被害軽減システム
    if ($Zaiko->getUnticolliSystemParam() != -1) {
        $send_data->unticolli_system  = intval($Zaiko->getUnticolliSystemParam());
    }

    //ビジュアル
    if ($Zaiko->getVisualParam() != -1) {
        $send_data->visual  = $Zaiko->getVisualParam();
    }

    //後席モニター
    if ($Zaiko->getBackSeatMonitorParam() != -1) {
        $send_data->back_monitor  = intval($Zaiko->getBackSeatMonitorParam());
    }

    //シートエアコン
    if ($Zaiko->getSeatAirconParam() != -1) {
        $send_data->seat_aircon  = intval($Zaiko->getSeatAirconParam());
    }

    //オットマン
    if ($Zaiko->getOttomanParam() != -1) {
        $send_data->ottoman  = intval($Zaiko->getOttomanParam());
    }

    //フロントカメラ
    if ($Zaiko->getFrontCameraParam() != -1) {
        $send_data->front_camera  = intval($Zaiko->getFrontCameraParam());
    }

    //電動リアゲート
    if ($Zaiko->getElectionRearGateParam() != -1) {
        $send_data->election_rear_gate  = intval($Zaiko->getElectionRearGateParam());
    }

    //サイドカメラ
    if ($Zaiko->getSideCameraParam() != -1) {
        $send_data->side_camera  = intval($Zaiko->getSideCameraParam());
    }

    //全周囲カメラ
    if ($Zaiko->getAllRangeCameraParam() != -1) {
        $send_data->allrange_camera  = intval($Zaiko->getAllRangeCameraParam());
    }

    //オーディオ
    if ($Zaiko->getAudioParam() != -1) {
        $send_data->audio = $Zaiko->getAudioParam();
    }

    //衝突安全ボディ
    if ($Zaiko->getCrachSafetyBodyParam() != -1) {
        $send_data->crash_safety_body  = intval($Zaiko->getCrachSafetyBodyParam());
    }

    //鑑定書
    if ($Zaiko->getKanteiBookParam() != -1) {
        $send_data->kantei_book  = intval($Zaiko->getKanteiBookParam());
    }

    //過給器
    if ($Zaiko->getCharGerParam() != -1) {
        $send_data->charger  = $Zaiko->getCharGerParam();
    }

    //call note
    if ($Zaiko->getCallNoteParam() != -1) {
        $send_data->callnote = strval($Zaiko->getCallNoteParam());
    }

    //動画

    if ($Zaiko->getMovieParam() != -1) {
        $send_data->movie_data  = $Zaiko->getMovieParam();
    }

    if ($Zaiko->getMovieExistFlgParam() != -1) {
        $send_data->movie_exists_flg  = intval($Zaiko->getMovieExistFlgParam());
    }

    // 購入パック
    if ($data->purchase_pack != '' && $data->purchase_pack[0]->total_price != '' && $data->purchase_pack[0]->plan_name !='') {
        //旧バージョンと切り分け
        if ($Zaiko->getDetailApiVer() <= 0) {
            $i = 0;
            foreach ($data->purchase_pack as $purchase_pack) {
                if ($purchase_pack->package_id != '-') {
                    $send_data->Package[$i]->package_type    = $Api->convertNull($purchase_pack->package_id);
                    $send_data->Package[$i]->package_name    = $Api->convertNull($purchase_pack->plan_name);
                    $send_data->Package[$i]->package_price   = $purchase_pack->total_price;

                    // 本番へデータが入るのがリリース後の為暫定処理(20141002)
                    if (isset($purchase_pack->goo_plus_flg)) {
                        $send_data->Package[$i]->goo_plus = $purchase_pack->goo_plus_flg;
                    }
                    $send_data->Package[$i]->goo_warranty               = (is_null($purchase_pack->goo_warranty)) ? "" : intval($purchase_pack->goo_warranty);
                    $send_data->Package[$i]->hosyou_item_count          = intval($purchase_pack->hosyou_item_count);
                    $send_data->Package[$i]->hosyou_item_comments       = strval($purchase_pack->hosyou_item_comments);
                    $send_data->Package[$i]->repair_upper_limit         = (is_null($purchase_pack->repair_upper_limit)) ? "" : intval($purchase_pack->repair_upper_limit);
                    $send_data->Package[$i]->repair_count               = intval($purchase_pack->repair_count);
                    $send_data->Package[$i]->hosyou_limit_upper_limit   = (is_null($purchase_pack->hosyou_limit_upper_limit)) ? "" : intval($purchase_pack->hosyou_limit_upper_limit);
                    $send_data->Package[$i]->hosyou_limit_comments      = strval($purchase_pack->hosyou_limit_comments);
                    $send_data->Package[$i]->deductible                 = (is_null($purchase_pack->deductible)) ? "" : intval($purchase_pack->deductible);
                    $send_data->Package[$i]->deductible_comments        = strval($purchase_pack->deductible_comments);
                    $send_data->Package[$i]->hosyou_repair_receptionist = strval($purchase_pack->hosyou_repair_receptionist);
                    $send_data->Package[$i]->road_service               = (is_null($purchase_pack->road_service)) ? "" : intval($purchase_pack->road_service);

                    $i++;
                }
            }
        } else {
            //オプション追加版では購入パックがある場合、パック数の有る無しに関わらず全てデータを返す
            $Zaiko->setPurChasePackData($data->purchase_pack);
            for ($packCounter = 0 ; $packCounter < 3 ; $packCounter++) {
                $send_data->Package[$packCounter] = $Zaiko->getPurChasePackObject($packCounter);
            }
        }

        // package_idが1～始まらない物件の為のpackage_typeの取るつめ対応
        $p_id    = 1;
        $package = 0;
        foreach ($send_data->Package as $purchase_pack_data) {
            if (is_numeric($purchase_pack_data->package_type) && $purchase_pack_data->package_type != $p_id) {
                $send_data->Package[$package]->package_type = $p_id;
            }
            $p_id++;
            $package++;
        }
    }

    // gooクーポン ver=3から
    if ($Zaiko->getDetailApiVer() >= 3) {
        $goopon_name = "";
        if ($data->goopon > 1) {
            if ($data->goopon == 2) {
                $goopon_name = "シルバーグークーポン";
            } elseif ($data->goopon == 3) {
                $goopon_name = "ゴールドグークーポン";
            } elseif ($data->goopon == 4) {
                $goopon_name = "プラチナグークーポン";
            }
        }
        $goopon_term = "";
        if ($data->goopon_start_date != "") {
            $goopon_term = $data->goopon_start_date."～";
        }
        if ($data->goopon_end_date != "") {
            if ($goopon_term == "") {
                $goopon_term = "～".$data->goopon_end_date;
            } else {
                $goopon_term .= $data->goopon_end_date;
            }
        }

        $send_data->goopon       = intval($data->goopon);
        $send_data->goopon_name  = $goopon_name;
        $send_data->goopon_img   = $Api->convertNull($data->goopon_img);
        $send_data->goopon_term  = $goopon_term;
        $send_data->goopon_title = $Api->convertNull($data->goopon_name);
        $send_data->goopon_text  = $Api->convertNull($data->goopon_comment);
    }

    // スピード見積り ver=3から
    if ($Zaiko->getDetailApiVer() >= 3) {
        $send_data->auto_reply = ($data->auto_reply_flg == 1) ? 1 : 0;
    }

    // 来店クーポン ver=3から
    if ($Zaiko->getDetailApiVer() >= 3) {
        $coupon_term = "";
        if ($data->coupon_start_date != "") {
            $coupon_term = $data->coupon_start_date."～";
        }
        if ($data->coupon_end_date != "") {
            if ($coupon_term == "") {
                $coupon_term = "～".$data->coupon_end_date;
            } else {
                $coupon_term .= $data->coupon_end_date;
            }
        }
        $send_data->coupon_flg   = $Api->convertNull($data->coupon_name) == "" ? 0 : 1;
        $send_data->coupon_name  = $Api->convertNull($data->coupon_name);
        $send_data->coupon_title = $Api->convertNull($data->coupon_title);
        $send_data->coupon_text  = $Api->convertNull($data->coupon_text);
        $send_data->coupon_term  = $coupon_term;
        $send_data->coupon_img   = $Api->convertNull($data->coupon_img);
    }

    // カタログID
    $send_data->cat_id = $data->cat_id;

    // GooPLUS
    $send_data->goo_plus = intval($data->goo_plus);

    // Goo保証
    $send_data->goo_warranty               = (is_null($data->goo_warranty)) ? "" : intval($data->goo_warranty);
    $send_data->hosyou_item_count          = intval($data->hosyou_item_count);
    $send_data->hosyou_item_comments       = strval($data->hosyou_item_comments);
    $send_data->repair_upper_limit         = (is_null($data->repair_upper_limit)) ? "" : intval($data->repair_upper_limit);
    $send_data->repair_count               = intval($data->repair_count);
    $send_data->hosyou_limit_upper_limit   = (is_null($data->hosyou_limit_upper_limit)) ? "" : intval($data->hosyou_limit_upper_limit);
    $send_data->hosyou_limit_comments      = strval($data->hosyou_limit_comments);
    $send_data->deductible                 = (is_null($data->deductible)) ? "" : intval($data->deductible);
    $send_data->deductible_comments        = strval($data->deductible_comments);
    $send_data->hosyou_repair_receptionist = strval($data->hosyou_repair_receptionist);
    $send_data->road_service               = (is_null($data->road_service)) ? "" : intval($data->road_service);

    // 物件詳細輸入経路
    $send_data->keiro_id = intval($data->keiro_id);

    // gooキャンペーン
    $send_data->goo_campaign = $Search->getGooCampaignFlg($data);

    // ローンフラグ
    $send_data->loan_flg = intval($data->loan_flg);

    // メッセンジャー
    $send_data->messenger_flg = ($send_data->estimate_flg == 1 && $send_data->soldout_flg == 0 && $data->messenger_reject_flg == 0) ? 1 : 0;

    return;
}


/**
 * 連想配列で入っているデータを、普通の配列に入れなおす
 *
 * @access public
 * @param  $option     : option のデータ
 * @return             : セットするデータ
 */
function _getArrData($arr) {
   $ret_arr = array();

   if (!is_array($arr)) {
       return $ret_arr;
   } else {
       foreach ($arr as $key => $val) {
            $ret_arr[] = $val;
       }
   }
   return $ret_arr;
}


/**
 * バリデート処理
 *
 * @param  array   $check_data   バリデートチェック対象のデータ
 * @param  object  $Logger       Logger Object
 */
function _validate($check_data, $Logger) {

    // 必須パラメータチェック
    if (!(isset($check_data["goo_car_id"]) || isset($check_data["stock_id"]))) {
        $Logger->error("goo_car_id or stock_id が指定されていません");
        _error(1);
        return false;
    }

    if (isset($check_data["goo_car_id"])) {
        if (trim($check_data["goo_car_id"]) == "") {
            $Logger->error("goo_car_id が空です");
            _error(1);
            return false;
        }
    }

    if (isset($check_data["stock_id"])) {
        if (trim($check_data["stock_id"]) == "") {
            $Logger->error("stock_id が空です");
            _error(1);
            return false;
        }
    }

    // ver パラメータのチェック (指定時)
    if (isset($check_data["ver"])) {
        if (!is_numeric($check_data["ver"])) {
            $Logger->error("バージョンが不正です：バージョン=".$check_data["ver"]);
            _error(1);
            return false;
        }
    }

    return true;
}


/**
 * エラー処理
 *
 * @param    integer  $status     エラーコード
 * @return   無し
 */
function _error($status) {
    $obj = new stdClass;
    $obj->error_cd = $status;
    _output($obj);
    return;
}


/**
 * データオブジェクトをJSON形式で出力する
 *
 * @param    object  $output_data   出力データオブジェクト
 * @return   無し
 */
function _output($output_data) {
    mb_convert_variables("UTF-8", "EUC-JP", $output_data);
    header("Content-Type: application/json; charset=utf-8");
    $encoded_output_data = json_encode($output_data);
    echo $encoded_output_data;
    return;
}


