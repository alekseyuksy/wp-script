<?php
register_activation_hook(__FILE__, 'where_to_buy_table_install');
add_action('wp_enqueue_scripts', 'register_script');
add_action('init', 'where_buy_plugin_handler');
add_filter('the_content', 'where_buy_content', 20);
add_action('admin_menu', 'where_buy_plugin_setup_menu');
add_action('wp_ajax_search_retailers', 'search_retailers');
add_action('wp_ajax_nopriv_search_retailers', 'search_retailers');
add_action('init', 'register_session');

const PRODUCTS_IDS = 'productsIds';
const PRODUCT_ID = 'product_id';
const PRODUCT_RETAILER = 'product_retailer';

const LOCATION = 'location';
const GEOLOCATION = 'geolocation';

const RETAILER_ID = 'retailer_id';
const RETAILERS_LOCATION = 'retailers_location';

const OPTION_COUPON_LINK = 'coupon-link';
const OPTION_WHERE_BUY_HEADER_TEXT = 'where-buy-header-text';

const LAT = 'lat';
const LON = 'lon';

static $productsIds;

function search_retailers()
{
    global $wpdb;

    $result = [];
    $productsRetailers = getProductsRetailersInfo();

    if (!isset($_POST[PRODUCTS_IDS])) {
        wp_die();
    }

    $productsIds = $_POST[PRODUCTS_IDS];

    if (isset($_POST[LOCATION])) {
        if (intval($_POST[LOCATION])) {
            $zip = $_POST[LOCATION];
        }

        if (!isset($zip)) {
            $states = getStates();
            $statesFlipped = array_flip($states);
            $location = strtolower($_POST[LOCATION]);

            if (isset($states[strtoupper($location)])) {
                $currentState = strtoupper($location);
            } elseif (isset($statesFlipped[ucfirst($location)])) {
                $currentState = $statesFlipped[ucfirst($location)];
            } else {
                $location = ucfirst($location);
            }
        }

        foreach ($productsIds as $products_id) {
            $retailers = $productsRetailers[$products_id];

            foreach ($retailers as $retailer) {
                $retailerTableName = strtolower($retailer);
                $tableName = $wpdb->get_var('SHOW TABLES LIKE \'wp_' . $retailerTableName . '_retailer\'');

                if ($tableName) {
                    if (isset($currentState)) {
                        $select = 'SELECT * FROM ' . $tableName . ' where state=\'' . $currentState . '\'';
                    } elseif (isset($zip)) {
                        $select = 'SELECT * FROM ' . $tableName . ' where zip_code=\'' . $zip . '\'';
                    } else {
                        $select = 'SELECT * FROM ' . $tableName . ' where city=\'' . $location . '\'';
                    }

                    $res = $wpdb->get_results($select);

                    foreach ($res as $resItem) {
                        $result[$retailerTableName][$resItem->id] = '';
                    }
                }
            }
        }
    } elseif (isset($_POST[LAT]) && isset($_POST[LON]) && isset($_POST[PRODUCTS_IDS])) {
        $lat = $_POST[LAT];
        $lon = $_POST[LON];

        foreach ($productsIds as $products_id) {
            $retailers = $productsRetailers[$products_id];

            foreach ($retailers as $retailer) {
                $retailerTableName = strtolower($retailer);
                $tableName = $wpdb->get_var('SHOW TABLES LIKE \'wp_' . $retailerTableName . '_retailer\'');

                if ($tableName) {
                    $select = latLonSelect($tableName, $lat, $lon, 5.5);
                    $res = $wpdb->get_results($select);

                    foreach ($res as $row) {
                        $result[$retailerTableName][$row->id] = $row->distance;
                    }
                }
            }
        }
    }

    $_SESSION[RETAILERS_LOCATION] = $result;
    echo json_encode($result);

    wp_die();
}

function latLonSelect($table, $lat, $lon, $rad = 5, $where = '')
{
    return "SELECT *, ROUND(
		((ACOS(SIN($lat * PI() / 180) * SIN(latitude * PI() / 180) + COS($lat * PI() / 180) * COS(latitude * PI() / 180) * COS((($lon) - longitude) * PI() / 180)) * 180 / PI()) * 60 * 1.1515),1) AS 'distance' 
		FROM $table 
		HAVING distance<=$rad AND distance>0 $where ORDER BY distance ASC ";
}

global $db_version;

function where_buy_plugin_setup_menu()
{
    add_menu_page('Where Buy Plugin Page', 'Where Buy Plugin', 'manage_options', 'where-buy-plugin',
        'where_buy_admin_init');
}

function where_buy_admin_init()
{
    global $wpdb;

    $retailerInfo = $wpdb->get_results('SELECT 
                                            wp_product_retailer.retailer_id, 
                                            wp_product_retailer.product_id, 
                                            wp_product_retailer.buy_online_link, 
                                            wp_retailers.title  
                                        FROM wp_product_retailer
                                        JOIN wp_retailers on wp_retailers.id = wp_product_retailer.retailer_id');
    $data = [];

    foreach ($retailerInfo as $item) {
        if ($item->title) {
            $title = strtolower($item->title);
            $data[$item->product_id][$title] = $item->buy_online_link;
        }
    }

    $table_name = $wpdb->prefix . 'where_buy_options';
    $headerTextDb = $wpdb->get_results(
        'SELECT * from' .
        $table_name .
        ' where option_name=\'' .
        OPTION_WHERE_BUY_HEADER_TEXT . '\''
    );
    $headerTextDb = reset($headerTextDb);

    $headerText = $headerTextDb->option_value;
    $headerText = stripslashes($headerText);

    $couponLinkDB = $wpdb->get_results('SELECT option_value from' . $table_name . 'where option_name=\'' . OPTION_COUPON_LINK . '\'');
    $couponLinkDB = reset($couponLinkDB);
    $couponLink = $couponLinkDB->option_value;

    echo '
<h1>BUY ONLINE LINKS</h1>
<div class="wrap">
       
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-1" method="post">
        <h3>Assorted Fruit:</h3>
            Target: <input style="width: 700px; margin-left: 50px" type="text" name="target" value="' . $data[1]['target'] . '"><br />
            Walmart: <input style="width: 700px;margin-left: 50px" type="text" name="walmart" value="' . $data[1]['walmart'] . '"><br />
            Rite Aid: <input style="width: 700px;margin-left: 50px"  type="text" name="rite_aid" value="' . $data[1]['rite aid'] . '"><br />
            Kroger: <input style="width: 700px;margin-left: 50px" type="text" name="kroger" value="' . $data[1]['kroger'] . '"><br />
            Meijer: <input style="width: 700px;margin-left: 50px" type="text" name="meijer" value="' . $data[1]['meijer'] . '"><br />
            Amazon: <input style="width: 700px;margin-left: 50px" type="text" name="amazon" value="' . $data[1]['amazon'] . '"><br />
            <input type="hidden" name="product_id" value="1">
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
        
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-2" method="post">
        <h3>Bone Health:</h3>
            CVS: <input style="width: 700px; margin-left: 50px" type="text" name="cvs" value="' . $data[2]['cvs'] . '"><br />
            Meijer: <input style="width: 700px;margin-left: 50px" type="text" name="meijer" value="' . $data[2]['meijer'] . '"><br />
            
            Amazon: <input style="width: 700px;margin-left: 50px" type="text" name="amazon" value="' . $data[2]['amazon'] . '"><br />
            <input type="hidden" name="product_id" value="2">
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
        
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-3" method="post">
        <h3>Fruity Bites:</h3>
            Kroger: <input style="width: 700px;margin-left: 50px" type="text" name="kroger" value="' . $data[3]['kroger'] . '"><br />
            Meijer: <input style="width: 700px;margin-left: 50px" type="text" name="meijer" value="' . $data[3]['meijer'] . '"><br />
            Amazon: <input style="width: 700px;margin-left: 50px" type="text" name="amazon" value="' . $data[3]['amazon'] . '"><br />
            <input type="hidden" name="product_id" value="3">
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
        
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-4" method="post">
        <h3>Metabolism + Energy:</h3>
            Target: <input style="width: 700px; margin-left: 50px" type="text" name="target" value="' . $data[4]['target'] . '"><br />
            Amazon: <input style="width: 700px;margin-left: 50px" type="text" name="amazon" value="' . $data[4]['amazon'] . '"><br />
            <input type="hidden" name="product_id" value="4">
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
        
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-5" method="post">
        <h3>Flavor Drops:</h3>
            Amazon: <input style="width: 700px;margin-left: 50px" type="text" name="amazon" value="' . $data[5]['amazon'] . '"><br />
            <input type="hidden" name="product_id" value="5">
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
        
        <hr/>
        <form name="where-buy-plugin-form" id="where-buy-plugin-form-6" method="post">
        <h3>Coupon callout:</h3>
            Link: <input style="width: 700px;margin-left: 50px" type="text" name="coupon-link" value="' . $couponLink . '"><br />
            Text: <textarea style="width: 700px;margin-left: 50px; height: 200px"  name="header-text" >' . $headerText . '</textarea><br />
          
            <input type="submit" name="where-buy-plugin-form-submit" value="Update">
        </form>
    </div>';
}

function where_buy_plugin_handler()
{
    if (isset($_POST['where-buy-plugin-form-submit'])) {
        global $wpdb;

        if (isset($_POST[PRODUCT_ID])) {
            $productId = $_POST[PRODUCT_ID];
            $productRetailerInfo = getProductsRetailersInfo();
            $productRetailer = $productRetailerInfo[$productId];

            $retailers = '';

            foreach ($productRetailer as $item) {
                $retailers .= '\'' . $item . '\',';
            }

            $retailers = rtrim($retailers, ',');
            $retailerIds = $wpdb->get_results('SELECT id, title from wp_retailers where title IN (' . $retailers . ')');

            $tableName = $wpdb->prefix . PRODUCT_RETAILER;

            foreach ($retailerIds as $item) {
                $retailerTitle = strtolower($item->title);
                $retailerTitle = str_replace(' ', '_', $retailerTitle);
                $link = $_POST[$retailerTitle];

                $wpdb->update($tableName, [
                    'buy_online_link' => $link
                ], [
                    RETAILER_ID => $item->id,
                    PRODUCT_ID => $productId
                ]);
            }
        }

        if (isset($_POST['header-text'])) {
            $headerText = htmlspecialchars($_POST['header-text']);
            $tableName = $wpdb->prefix . 'where_buy_options';
            $headerTextDb = $wpdb->get_results('SELECT * from ' . $tableName .
                ' where option_name=\'' . OPTION_WHERE_BUY_HEADER_TEXT . '\'');

            if ($headerTextDb) {
                $wpdb->update($tableName, [
                    'option_value' => $headerText
                ], [
                    'option_name' => OPTION_WHERE_BUY_HEADER_TEXT,
                ]);
            } else {
                $wpdb->insert($tableName, [
                    'option_value' => $headerText,
                    'option_name' => OPTION_WHERE_BUY_HEADER_TEXT,
                ]);
            }

            $couponText = htmlspecialchars($_POST[OPTION_COUPON_LINK]);
            $couponTextDb = $wpdb->get_results("SELECT * from $tableName where option_name=OPTION_COUPON_LINK");

            if ($couponTextDb) {
                $wpdb->update($tableName, [
                    'option_value' => $couponText
                ], [
                    'option_name' => OPTION_COUPON_LINK,
                ]);
            } else {
                $wpdb->insert($tableName, [
                    'option_value' => $couponText,
                    'option_name' => OPTION_COUPON_LINK,
                ]);
            }
        }
    }
}

function where_to_buy_table_install()
{
    global $wpdb;
    $db_version = "1.0";
    $tableName = $wpdb->prefix . "cvs_retailer";

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/cvs_02-01-19.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }

        add_option("db_version", $db_version);
    }

    $tableName = $wpdb->prefix . "kroger_retailer";

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  dba text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/kroger_11-06-18.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . "meijer_retailer";

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/meijer_12-06-18.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }

    }

    $tableName = $wpdb->prefix . 'rite_aid_retailer';

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/rite_aid_01-01-19.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . 'target_retailer';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  store_name text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/target_02-01-19.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . 'walmart_retailer';

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_number text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/walmart_02-01-19.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }


    $tableName = $wpdb->prefix . 'albertsons_retailer';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_name text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/albertsons_12-06-18.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . 'safeway_retailer';

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  store_name text,
	  address text,
	  city text,
	  state text ,
	  zip_code text,
	  latitude Float( 15, 12 ),
	  longitude Float( 15, 12 ),
	  country_code text,
	  county text,
	  country text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $cvsFile = ABSPATH . 'wp-content/plugins/where-buy/retailers/safeway_02-02-19.csv';
        $csvData = getDataFromCsv($cvsFile);

        foreach ($csvData as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . 'retailers';

    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  title text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $retailerTitles = [
            'Target',
            'Walmart',
            'Rite Aid',
            'Kroger',
            'Albertsons',
            'Safeway',
            'Meijer',
            'CVS',
            'Amazon'
        ];

        foreach ($retailerTitles as $item) {
            $wpdb->insert($tableName, ['title' => $item]);
        }
    }

    $tableName = $wpdb->prefix . PRODUCT_RETAILER;
    if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
        $sql = "CREATE TABLE " . $tableName . " (
	  id int NOT NULL AUTO_INCREMENT,
	  retailer_id int,
	  product_id int,
	  buy_online_link text,
	  UNIQUE KEY id (id)
	)";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $retailerData = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "retailers");

        $productRetailer = getProductsRetailersInfo();

        $data = [];

        foreach ($productRetailer as $key => $productRetailerItem) {
            foreach ($retailerData as $retailerItem) {
                if (in_array($retailerItem->title, $productRetailerItem)) {
                    $data[] = [
                        PRODUCT_ID => (int)$key,
                        RETAILER_ID => (int)$retailerItem->id
                    ];
                }
            }
        }

        foreach ($data as $item) {
            $wpdb->insert($tableName, $item);
        }
    }

    $tableName = $wpdb->prefix . 'where_buy_options';
    if ($wpdb->get_var('SHOW TABLES LIKE \'' . $tableName . '\'') != $tableName) {
        $sql = 'CREATE TABLE ' . $tableName . ' (
                      id int NOT NULL AUTO_INCREMENT,
                      option_name text,
                      option_value text,
                      UNIQUE KEY id (id)
        )';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    add_option('db_version', $db_version);
}

function getDataFromCsv($file)
{
    $resData = [];
    $keys = [];
    $c = 0;

    if (($handle = fopen($file, 'r')) !== false) {
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $mappedData = [];
            if ($c == 0) {
                $keys = $data;

                foreach ($keys as &$key) {
                    $key = trim($key);
                    $key = strtolower($key);
                    $key = str_replace(' ', '_', $key);
                }

                unset($key);
                $c++;
                continue;
            }

            foreach ($data as $key => $item) {
                $mappedData[$keys[$key]] = $item;
            }

            $resData[] = $mappedData;
        }
        fclose($handle);
    }

    return $resData;
}

function getProductsRetailersInfo()
{
    return [
        1 => ['Target', 'Walmart', 'Rite Aid', 'Kroger', 'Albertsons', 'Meijer', 'Safeway', 'Amazon'],
        2 => ['CVS', 'Meijer', 'Amazon'],
        3 => ['Kroger', 'Meijer', 'Amazon'],
        4 => ['Target', 'Amazon'],
        5 => ['Amazon']
    ];
}

function register_script()
{
    wp_register_style('task', plugins_url('style.css', __FILE__));
    wp_enqueue_style('task');
    wp_register_script('task', plugins_url('script.js', __FILE__));
    wp_enqueue_script('task');
    wp_localize_script('task', 'MyAjax', ['ajaxurl' => admin_url('admin-ajax.php')]);
}

function where_buy_content($content)
{
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'],
            '/where-to-buy-fiber-choice') !== false || is_page('where-to-buy-fiber-choice')) {
        $content = html();
    }

    return $content;
}

function html()
{
    global $wpdb;
    $textInLine = '';
    $tableName = $wpdb->prefix . 'where_buy_options';
    $headerTextDb = $wpdb->get_results('SELECT * from ' . $tableName . ' where option_name=\'' . OPTION_WHERE_BUY_HEADER_TEXT . '\'');

    if ($headerTextDb) {
        $headerTextDb = reset($headerTextDb);
        $headerText = $headerTextDb->option_value;
        $headerText = stripslashes($headerText);
        $textInLine = htmlspecialchars_decode($headerText);
    }

    $couponTextDB = $wpdb->get_results('SELECT * from ' . $tableName . ' where option_name=\'' . OPTION_COUPON_LINK . '\'');
    $couponTextDB = reset($couponTextDB);
    $couponLink = $couponTextDB->option_value;

    return '
<div class="green-line"  style=""><a class="green-line-link" href="' . $couponLink . '">
            <p class="center">' . $textInLine . '</p>
        </a></div>
	<div class="where-buy-wrapper">
        
        ' . map() . '
    </div>
	';
}

function googleMapInit($retailersRows)
{
    $dataForMap = [];

    foreach ($retailersRows as $retailersRow) {
        $data = [];
        $data[] = $retailersRow->store_name ? $retailersRow->store_name : $retailersRow->dba;
        $data[] = $retailersRow->latitude;
        $data[] = $retailersRow->longitude;

        if (!$data[0]) {
            $data[0] = $retailersRow->address;
        }

        $dataForMap[] = $data;
    }

    $dataForMapJson = json_encode($dataForMap);

    return '
<script type="text/javascript">
function initMap() {

    var locations = ' . $dataForMapJson . ';
        
    if (locations.length) {
        
        var map = new google.maps.Map(document.getElementById(\'map\'), {
        zoom: 11,
        center: new google.maps.LatLng(locations[0][1], locations[0][2]),
        mapTypeId: google.maps.MapTypeId.ROADMAP
        });

        var infowindow = new google.maps.InfoWindow();
    
        var marker, i;
    
        for (i = 0; i < locations.length; i++) {
            marker = new google.maps.Marker({
                position: new google.maps.LatLng(locations[i][1], locations[i][2]),
                map: map,
            });
    
            google.maps.event.addListener(marker, \'click\', (function(marker, i) {
                return function() {
                    infowindow.setContent(locations[i][0]);
                    infowindow.open(map, marker);
                }
            })(marker, i));
        }
    } else {
        let latLng = {lat: 33.753746, lng: -84.386330};
        let map = new google.maps.Map(
            document.getElementById(\'map\'), {zoom: 4, center: latLng});
    
        const marker = new google.maps.Marker({position: latLng, map: map});

    }}
</script>
<script src="https://apis.google.com/js/api.js" type="text/javascript"></script>
<script 
    src="https://maps.googleapis.com/maps/api/js?libraries=places&key=AIzaSyBfxcYaq_RYxjF9GU_Du1g858jYDBU87Wk&callback=initMap">
    </script> ';
}

function nearByCalculate($location, $retailer, &$dataBuf)
{
    if (isset($dataBuf[$retailer->address])) {
        return null;
    } else {
        $dataBuf[$retailer->address] = true;
    }

    $distance = distance($location->lat, $location->lon, $retailer->latitude, $retailer->longitude, $unit = 'M');

    if ($distance <= 5.5) {
        $distance = round($distance, 1);
        $retailer->distance = $distance;
        return $retailer;
    }

    return null;
}

function distance($lat1, $lon1, $lat2, $lon2, $unit = 'M')
{
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
        return 0;
    } else {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == 'K') {
            return ($miles * 1.609344);
        } elseif ($unit == 'N') {
            return ($miles * 0.8684);
        }

        return $miles;
    }
}

function map()
{
    global $wpdb;

    $nearByHtml = '<div class="find-near-by-wrapper hidden no-items">Select a product and enter your city, state or ZIP</div>';
    $onlineItemsHtml = '<div class="buy-online-wrapper no-items hidden">There are no items. Please provide City, State or ZIP.</div>';
    $retailersRows = [];
    $nearByItems = [];
    $filterData = [];

    if (!isset($_SESSION['count'])) {
        $_SESSION['count'] = 0;
    }

    if (isset($_SESSION[RETAILERS_LOCATION]) && $_SESSION[RETAILERS_LOCATION]) {
        $_SESSION['count']++;

        $retailers = $_SESSION[RETAILERS_LOCATION];

        foreach ($retailers as $retailerTitle => $retailer) {
            $tableName = $wpdb->get_var('SHOW TABLES LIKE \'wp_' . $retailerTitle . '_retailer\'');

            foreach ($retailer as $id => $item) {
                $retailerRow = $wpdb->get_results('Select * from ' . $tableName . ' where id=' . $id);
                $retailerRow = reset($retailerRow);
                $retailerRow->distance = $item;
                $retailerRow->retailer = $retailerTitle;
                $retailersRows[] = $retailerRow;

                if ($item) {
                    $nearByItems[] = $retailerRow;
                } else {
                    if (isset($_COOKIE[GEOLOCATION])) {
                        $location = json_decode(stripslashes($_COOKIE[GEOLOCATION]));
                        $nearByItems[] = nearByCalculate($location, $retailerRow, $filterData);
                    }
                }

            }
        }

        if ($_SESSION['count'] >= 3) {
            $_SESSION[RETAILERS_LOCATION] = [];
            $_SESSION['count'] = 0;
        }

        $nearByItems = array_filter($nearByItems);
        $nearByHtml = renderFindNearBy($nearByItems);

        if (!$nearByItems) {
            $nearByHtml = '<div class="find-near-by-wrapper hidden no-items">There are no items near your location.</div>';
        }
    }

    $where = '';

    if (isset($_COOKIE[PRODUCTS_IDS]) && $_COOKIE[PRODUCTS_IDS]) {
        $where = " where product_id IN (" . $_COOKIE[PRODUCTS_IDS] . ")";
    }

    $onlineItemsDb = $wpdb->get_results("SELECT wp_product_retailer.retailer_id,wp_product_retailer.product_id, wp_product_retailer.buy_online_link,wp_retailers.title  
                                                from wp_product_retailer
                                                JOIN wp_retailers on wp_retailers.id = wp_product_retailer.retailer_id $where
                                                ");
    $onlineItems = [];

    foreach ($onlineItemsDb as $onlineItem) {
        if ($onlineItem->buy_online_link) {
            $onlineItems[$onlineItem->title] = $onlineItem;
        }
    }

    $onlineItemsHtml = renderBuyOnline($onlineItems);

    return '
            <span class="for-mobile-display">
		    
				<h1>Find Fiber Choice products online or at a retailer near you.</h1>
				<div class="where-buy-info"></div>
				<div class="retailer-block">
					<input type="text" class="where-find-by-input" placeholder="City, State or ZIP">
					<button id="search-retailer-by-zip"></button>
					<nav class="where-buy-switcher">
						<a class="nav-switcher" id="find-nearby-mobile">Find Nearby</a>
						<a class="nav-switcher switch-on" id="by-online-mobile">Buy Online</a>
					</nav>
				</div>
			    <div class="for-mobile-display-products">
			    <div>
			        <p class="where-buy-products-title-for-mobile">Product Filter <span class="toggle-product-menu icon-down"></span></p>
                </div>
			    
               
			        <div class="products">
				<div class="product-item" data-id="1"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Assorted Fruit Tablets</div>
				<div class="product-item" data-id="3"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Fruity Bites Gummies</div>
				<div class="product-item" data-id="2"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Bone Health Tablets</div>
				<div class="product-item" data-id="4"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Metabolism Gummies</div>
			</div>
                </div>
			
            </span>
		<div class="map-block">
			<div id="map" class="map"></div>
			<span class="for-desktop-display-products">
                <p class="where-buy-products-title">Product Filter <span class="reload-spinner"></span></p>
                
                <div class="products">
                    <div class="product-item" data-id="1"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Assorted Fruit Tablets</div>
                    <div class="product-item" data-id="3"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Fruity Bites Gummies</div>
                    <div class="product-item" data-id="2"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Bone Health Tablets</div>
                    <div class="product-item" data-id="4"><input type="checkbox"><span class="where-buy-checkbox-style "></span>Metabolism Gummies</div>
                </div>
			</span>
		</div>
		<div id="map1" style="display: none"></div>
		<div class="where-buy-fiber-choice-block">
		    <span class="for-desktop-display">
		    
				<h1>Find Fiber Choice products online or at a retailer near you.</h1>
				<div class="where-buy-info"></div>
				<div class="retailer-block">
					<input type="text" class="where-find-by-input" placeholder="City, State or ZIP">
					<button id="search-retailer-by-zip"></button>
					<nav class="where-buy-switcher">
						<a class="nav-switcher" id="find-nearby-desktop">Find Nearby</a>
						<a class="nav-switcher switch-on" id="by-online-desktop">Buy Online</a>
					</nav>
				</div>
            </span>
				' . $nearByHtml . $onlineItemsHtml . '
			</div>
	' . googleMapInit($retailersRows);
}

function renderFindNearBy($items)
{
    $html = '';

    foreach ($items as $item) {
        $html .= renderFindNearByItem($item);
    }

    return '
        <div class="find-near-by-wrapper hidden">
            ' . $html . '
        </div>
	';
}

function renderFindNearByItem($item)
{
    if (strtoupper($item->retailer) == 'CVS') {
        $item->retailer = strtoupper($item->retailer) . ' /pharmacy';
    } else {
        $item->retailer = ucfirst($item->retailer);
    }

    $address = $item->address . '<br/> ' . $item->city . ' ' . $item->state . ' ' . $item->zip_code;
    $addressForMap = $item->address . ' ' . $item->city . ' ' . $item->state . ' ' . $item->zip_code;
    $latLon = '@' . $item->latitude . ',' . $item->longitude . ',14z';

    if ($item->distance) {
        $item->distance .= ' miles';
    }

    return '
			<div class="find-near-by-item">
				<div class="find-near-by-locator"></div>
				<div class="find-near-by-description">
					<p class="item-title">' . $item->retailer . '</p>
					<p class="item-address">' . $address . '</p>
				</div>
				<div class="find-near-by-direction desktop">
					<p class="item-direction">' . $item->distance . '</p>
					<button><span class="button-direction-text"><a href="https://www.google.com/maps/place/' . $addressForMap . '/' . $latLon . '" target="_blank">Directions</a></span></button>
				</div>
				<div class="find-near-by-direction mobile">
					<p class="item-direction">' . $item->distance . '</p>
					<button class="button-phone" data-id-lat="' . $item->latitude . '" data-id-lon="' . $item->longitude . '"><span class="popover above"></span></button>
					<button class="button-car"><span class="button-direction-text"><a href="https://www.google.com/maps/place/' . $addressForMap . '/' . $latLon . '" target="_blank"></a></span></button>
				</div>
			</div>
	';
}

function renderBuyOnline($onlineItems)
{
    $itemsHtml = '';

    foreach ($onlineItems as $onlineItem) {
        if ($onlineItem->buy_online_link) {
            $itemsHtml .= renderBuyOnlineItem($onlineItem);
        }
    }

    return '
		<div class="buy-online-wrapper ">
			' . $itemsHtml . '
		</div>
	';
}

function renderBuyOnlineItem($onlineItem)
{
    $retailerTitle = strtolower($onlineItem->title);
    $retailerTitle = str_replace(' ', '_', $retailerTitle);
    $imageTitle = 'FC_WTB_' . $retailerTitle;
    $isImageEnabled = get_attachment_url_by_slug($imageTitle) ? true : false;
    $imgHtml = '<span class="retailer-title">' . $onlineItem->title . '</span>';

    if ($isImageEnabled) {
        $imgHtml = '<img src="' . get_attachment_url_by_slug($imageTitle) . '" class="' . strtolower($onlineItem->title) . '-img" alt="' . strtolower($onlineItem->title) . '">';
    }

    return '
			<div class="buy-online-item">
				<p class="logo-retailer">' . $imgHtml . '</p>
				<div class="buy-online-button-wrapper">
					<button><span class="button-direction-text"><a href="' . $onlineItem->buy_online_link . '" target="_blank">Buy Now</a></span></button>
				</div>
			</div>
	';
}

function get_attachment_url_by_slug($slug)
{
    $args = [
        'post_type' => 'attachment',
        'name' => sanitize_title($slug),
        'posts_per_page' => 1,
        'post_status' => 'inherit',
    ];

    $_header = get_posts($args);
    $header = $_header ? array_pop($_header) : null;

    return $header ? wp_get_attachment_url($header->ID) : '';
}


function getStates()
{
    return [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'DC' => 'District Of Columbia',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming'
    ];
}

function register_session()
{
    if (!session_id()) {
        session_start();
    }
}

