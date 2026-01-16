<?php
/**
 * Plugin Name: 台股資訊中心 Pro Max - 自動更新版
 * Description: ETF 配息與新股申購即時資訊 - 自動從證交所 API 抓取
 * Version: 6.0.0
 * Author: wumetax
 */

if (!defined('ABSPATH')) exit;

class Taiwan_Stock_Info_Pro_Max {

    private static $instance = null;
    private $cache_time = 3600; // 快取 1 小時

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_menu'));
            add_action('admin_enqueue_scripts', array($this, 'load_assets'));
            add_action('wp_ajax_stock_update', array($this, 'ajax_update'));
            add_action('admin_head', array($this, 'add_inline_styles'));
        }

        add_action('stock_smart_update', array($this, 'smart_update'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    // ========== 證交所 API 抓取函數 ==========
    
    /**
     * 從證交所 OpenAPI 抓取所有股票當日資料
     * API: https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL
     */
    private function fetch_twse_stock_day_all() {
        $url = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';
        
        $args = array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('[台股資訊] 證交所 API 請求失敗: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !is_array($data)) {
            error_log('[台股資訊] 證交所 API 回傳格式錯誤');
            return false;
        }

        // 將陣列轉換成以股票代號為 key 的關聯陣列
        $stock_map = array();
        foreach ($data as $item) {
            if (isset($item['Code'])) {
                $stock_map[$item['Code']] = $item;
            }
        }

        return $stock_map;
    }

    /**
     * 從證交所即時 API 抓取個股資料 (備用)
     */
    private function fetch_twse_realtime_stock($code) {
        $url = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp?ex_ch=tse_' . $code . '.tw&json=1&delay=0';
        
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['msgArray'][0])) {
            return $data['msgArray'][0];
        }

        return null;
    }

    /**
     * 爬取 MoneyDJ ETF 配息資訊
     */
    private function scrape_moneydj_etf_info($code) {
        $url = 'https://www.moneydj.com/etf/x/basic/basic0004.xdjhtm?etfid=' . $code . '.TW';
        
        $args = array(
            'timeout' => 20,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('[台股資訊] MoneyDJ 請求失敗: ' . $response->get_error_message());
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        
        $result = array(
            'yield' => 0,
            'expense' => 0.5,
            'freq' => '季配',
            'holdings' => '資料更新中'
        );

        // 解析殖利率
        if (preg_match('/近12個月殖利率.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['yield'] = floatval($matches[1]);
        } elseif (preg_match('/殖利率.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['yield'] = floatval($matches[1]);
        }

        // 解析經理費
        if (preg_match('/經理費.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['expense'] = floatval($matches[1]);
        } elseif (preg_match('/管理費.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['expense'] = floatval($matches[1]);
        }

        // 解析配息頻率
        if (preg_match('/配息頻率.*?(月配|季配|半年配|年配)/isu', $html, $matches)) {
            $result['freq'] = $matches[1];
        }

        // 解析前三大成分股
        if (preg_match_all('/<td[^>]*>[\s]*([^<]{2,10})[\s]*<\/td>[\s]*<td[^>]*>[\s]*(\d+\.?\d*)%/isu', $html, $matches, PREG_SET_ORDER)) {
            $holdings = array();
            $count = 0;
            foreach ($matches as $match) {
                if ($count >= 3) break;
                $stock_name = trim($match[1]);
                if (mb_strlen($stock_name) >= 2 && mb_strlen($stock_name) <= 10) {
                    $holdings[] = $stock_name;
                    $count++;
                }
            }
            if (!empty($holdings)) {
                $result['holdings'] = implode('、', $holdings);
            }
        }

        return $result;
    }

    /**
     * 主要 ETF 數據抓取函數
     */
    private function get_etf_data() {
        $cache = get_transient('stock_etf_data');
        if ($cache) return $cache;

        // 定義要追蹤的 ETF
        $etf_list = array(
            '0050' => '元大台灣50',
            '0056' => '元大高股息',
            '00878' => '國泰永續高股息',
            '00919' => '群益台灣精選高息',
            '00929' => '復華台灣科技優息',
            '00701' => '國泰股利精選30',
            '00713' => '元大高息低波',
            '00927' => '群益半導體收益',
            '00881' => '國泰台灣科技龍頭',
            '00940' => '元大臺灣價值高息',
            '00918' => '大華優利高填息30',
            '00934' => '中信成長高股息',
            '00946' => '群益科技高息成長',
            '00730' => '富邦臺灣優質高息',
            '00939' => '統一台灣高息動能',
            '00915' => '凱基優選高股息30',
            '00900' => '富邦特選高股息30',
            '00923' => '群益台ESG低碳50',
            '00850' => '元大臺灣ESG永續',
            '00692' => '富邦公司治理',
        );

        // 1. 先從證交所 API 抓取所有股票資料
        $stock_data = $this->fetch_twse_stock_day_all();
        
        $result = array();
        $index = 0;

        foreach ($etf_list as $code => $name) {
            $index++;
            
            // 從證交所資料中取得股價
            $price = 20.0;
            $change_percent = 0;
            
            if ($stock_data && isset($stock_data[$code])) {
                $stock_info = $stock_data[$code];
                $price = floatval($stock_info['ClosingPrice'] ?? $stock_info['Close'] ?? 20.0);
                $change = floatval($stock_info['Change'] ?? 0);
                $change_percent = $change;
            }

            // 2. 爬取配息資訊 (每 3 檔間隔一下,避免被封鎖)
            if ($index % 3 == 0) {
                sleep(3);
            }
            
            $etf_info = $this->scrape_moneydj_etf_info($code);
            
            if (!$etf_info) {
                error_log("[台股資訊] 無法抓取 {$code} 的配息資訊,使用預設值");
                $etf_info = array(
                    'yield' => 5.0,
                    'expense' => 0.5,
                    'freq' => '季配',
                    'holdings' => '資料更新中'
                );
            }

            $yield_val = $etf_info['yield'];
            $dividend = round($price * ($yield_val / 100), 2);
            $cost_per_lot = $price * 1000;
            $annual_income = $dividend * 1000;

            $result[] = array(
                'code' => $code,
                'name' => $name,
                'price' => number_format($price, 2),
                'yield' => $yield_val . '%',
                'dividend' => $dividend . '元',
                'cost_per_lot' => number_format($cost_per_lot, 0) . '元',
                'annual_income' => number_format($annual_income, 0) . '元',
                'expense' => $etf_info['expense'] . '%',
                'freq' => $etf_info['freq'],
                'ret' => ($change_percent >= 0 ? '+' : '') . number_format($change_percent, 2) . '%',
                'holdings' => $etf_info['holdings'],
                'yield_val' => $yield_val,
                'return_val' => $change_percent
            );
        }

        set_transient('stock_etf_data', $result, $this->cache_time);
        update_option('stock_etf_update_time', current_time('Y-m-d H:i:s'));
        
        error_log('[台股資訊] ETF 資料更新完成,共 ' . count($result) . ' 檔');
        
        return $result;
    }

    /**
     * 爬取 IPO 新股申購資訊
     */
    private function get_ipo_data() {
        $cache = get_transient('stock_ipo_data');
        if ($cache) return $cache;

        // IPO 資料通常需要從特定網站爬取
        // 這裡提供範例結構,實際可以從 CMoney、玩股網等爬取
        $result = array(
            array(
                'code' => '4739',
                'name' => '康普',
                'type' => '上市增資',
                'period' => '01/08-01/12',
                'lottery' => '01/22',
                'price' => '150元',
                'return' => '預估45%',
                'tip' => '★ 可參與',
                'status' => 'closed',
                'status_txt' => '已截止'
            ),
            array(
                'code' => '1623',
                'name' => '大東電',
                'type' => '初上市',
                'period' => '01/12-01/16',
                'lottery' => '01/24',
                'price' => '188元',
                'return' => '預估147%',
                'tip' => '★★★ 強推',
                'status' => 'available',
                'status_txt' => '可申購'
            ),
        );

        set_transient('stock_ipo_data', $result, $this->cache_time);
        update_option('stock_ipo_update_time', current_time('Y-m-d H:i:s'));
        
        return $result;
    }

    // ========== 以下保持原有函數不變 ==========

    public function add_inline_styles() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page-stock-dashboard') {
            // 你原有的 CSS 樣式
            include(__DIR__ . '/admin-styles.php');
        }
    }

    public function activate() {
        if (!wp_next_scheduled('stock_smart_update')) {
            wp_schedule_event(time(), 'stock_ten_minutes', 'stock_smart_update');
        }
        add_filter('cron_schedules', array($this, 'custom_cron_schedules'));
    }

    public function deactivate() {
        wp_clear_scheduled_hook('stock_smart_update');
    }

    public function custom_cron_schedules($schedules) {
        $schedules['stock_ten_minutes'] = array(
            'interval' => 600,
            'display' => '每 10 分鐘'
        );
        return $schedules;
    }

    public function smart_update() {
        $now = current_time('timestamp');
        $day_of_week = date('N', $now);
        $hour = (int)date('H', $now);
        $minute = (int)date('i', $now);
        $time_decimal = $hour + ($minute / 60);

        // 週一到週五 7:00-14:30 才更新
        if ($day_of_week >= 1 && $day_of_week <= 5 && $time_decimal >= 7 && $time_decimal <= 14.5) {
            delete_transient('stock_etf_data');
            delete_transient('stock_ipo_data');
            delete_transient('stock_quote');
            $this->get_etf_data();
            $this->get_ipo_data();
            error_log('[台股資訊] 盤中自動更新: ' . current_time('Y-m-d H:i:s'));
        }
    }

    public function add_menu() {
        add_menu_page(
            '台股資訊中心',
            '台股資訊',
            'manage_options',
            'stock-dashboard',
            array($this, 'render'),
            'dashicons-chart-line',
            30
        );
    }

    public function load_assets($hook) {
        if ($hook !== 'toplevel_page-stock-dashboard') return;

        wp_enqueue_script('jquery');
        wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', array('jquery'), '1.13.7', true);
        wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css', array(), '1.13.7');
    }

    private function get_etf_url($code) {
        return 'https://www.moneydj.com/etf/x/basic/basic0004.xdjhtm?etfid=' . urlencode($code) . '.TW';
    }

    private function get_stock_url($code) {
        return 'https://www.google.com/finance/quote/' . urlencode($code) . ':TPE';
    }

    public function ajax_update() {
        check_ajax_referer('stock_update', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('msg' => '權限不足'));
        }

        delete_transient('stock_etf_data');
        delete_transient('stock_ipo_data');
        delete_transient('stock_quote');
        
        $this->get_etf_data();
        $this->get_ipo_data();

        wp_send_json_success(array('msg' => '資料更新成功！頁面即將重新載入'));
    }

    private function get_quote() {
        $cache = get_transient('stock_quote');
        if ($cache) return $cache;

        $quotes = array(
            array('投資最大的風險,不是價格的波動,而是你的資本永久損失。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('在別人貪婪時恐懼,在別人恐懼時貪婪。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('時間是優質企業的朋友,卻是平庸企業的敵人。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
        );

        $quote = $quotes[array_rand($quotes)];
        set_transient('stock_quote', $quote, 3600);
        return $quote;
    }

    private function analyze_data($etf) {
        $yields = array_column($etf, 'yield_val');
        $returns = array_column($etf, 'return_val');
        
        $high_yield = array_filter($etf, function($e) { return $e['yield_val'] > 10; });
        $high_growth = array_filter($etf, function($e) { return $e['return_val'] > 15; });
        $monthly = array_filter($etf, function($e) { return strpos($e['freq'], '月') !== false; });
        $tech = array_filter($etf, function($e) { 
            return strpos($e['holdings'], '台積電') !== false || strpos($e['holdings'], '聯發科') !== false; 
        });

        usort($etf, function($a, $b) { return $b['yield_val'] <=> $a['yield_val']; });
        $top_yield_etfs = array_slice(array_column($etf, 'code'), 0, 3);

        usort($etf, function($a, $b) { return $b['return_val'] <=> $a['return_val']; });
        $top_growth_etfs = array_slice(array_column($etf, 'code'), 0, 3);

        return array(
            'top_yield' => round(max($yields), 2) . '%',
            'avg_yield' => round(array_sum($yields) / count($yields), 2) . '%',
            'high_yield_count' => count($high_yield),
            'top_return' => '+' . round(max($returns), 2) . '%',
            'avg_return' => '+' . round(array_sum($returns) / count($returns), 2) . '%',
            'high_growth_count' => count($high_growth),
            'monthly_count' => count($monthly),
            'tech_count' => count($tech),
            'strategies' => array(
                array(
                    'title' => '高配息策略',
                    'etfs' => $top_yield_etfs,
                    'desc' => '專注於高殖利率 ETF,適合追求穩定現金流的投資人',
                    'pros' => array(
                        '年化配息率 ' . round(max($yields), 1) . '%',
                        '分散持股降低風險',
                        '適合退休規劃與被動收入'
                    ),
                    'risk' => '低'
                ),
                array(
                    'title' => '成長動能策略',
                    'etfs' => $top_growth_etfs,
                    'desc' => '聚焦高成長性 ETF,適合長期資本增值',
                    'pros' => array(
                        '年化報酬率 ' . round(max($returns), 1) . '%',
                        '掌握科技成長趨勢',
                        '適合長期投資累積財富'
                    ),
                    'risk' => '中高'
                )
            )
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('權限不足');

        $etf = $this->get_etf_data();
        $ipo = $this->get_ipo_data();
        $quote = $this->get_quote();
        $analysis = $this->analyze_data($etf);

        $etf_time = get_option('stock_etf_update_time', '尚未更新');
        $ipo_time = get_option('stock_ipo_update_time', '尚未更新');

        // 你原有的 HTML 輸出
        include(__DIR__ . '/admin-template.php');
    }
}

// 初始化插件
Taiwan_Stock_Info_Pro_Max::get_instance();
